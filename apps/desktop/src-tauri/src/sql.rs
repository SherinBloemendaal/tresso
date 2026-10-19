//! The local mail copy's SQLite databases, for the web client (its `SqliteMailStore`). Each open database has a
//! connection that writes and one that reads: the client queues its writes, so the writer never meets another writer,
//! and in WAL mode readers see the last committed state without waiting for a write under way.

use std::collections::HashMap;
use std::path::PathBuf;
use std::sync::atomic::{AtomicU32, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Duration;

use rusqlite::types::{Value as SqlValue, ValueRef};
use rusqlite::{Connection, OpenFlags, params_from_iter};
use serde::Deserialize;
use serde_json::{Map, Number, Value};
use tauri::{AppHandle, Manager, State};

struct Database {
  path: PathBuf,
  writer: Mutex<Connection>,
  reader: Mutex<Connection>,
}

#[derive(Default)]
pub struct Databases {
  next: AtomicU32,
  open: Mutex<HashMap<u32, Arc<Database>>>,
}

/// Which connection a statement runs on: reads outside a transaction use the reader, everything else the writer.
#[derive(Deserialize, Clone, Copy)]
#[serde(rename_all = "lowercase")]
pub enum On {
  Reader,
  Writer,
}

/// Names come from the client (`tresso-mail-<user id>`); anything that could leave the folder is refused.
fn path(app: &AppHandle, name: &str) -> Result<PathBuf, String> {
  let valid = !name.is_empty() && name.len() <= 128 && name.chars().all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '_');
  if !valid {
    return Err(format!("\"{name}\" is not a valid database name."));
  }
  let folder = app.path().app_data_dir().map_err(|error| format!("The app's data folder is not known: {error}"))?.join("mail");
  std::fs::create_dir_all(&folder).map_err(|error| format!("Creating the mail folder failed: {error}"))?;
  Ok(folder.join(format!("{name}.sqlite")))
}

fn connect(path: &PathBuf, flags: OpenFlags) -> Result<Connection, String> {
  let connection = Connection::open_with_flags(path, flags).map_err(|error| format!("Opening the mail copy failed: {error}"))?;
  // The reader can meet a checkpoint and the writer a reader finishing one; both wait a moment instead of failing.
  connection.busy_timeout(Duration::from_secs(5)).map_err(|error| error.to_string())?;
  Ok(connection)
}

fn database(state: &State<'_, Databases>, handle: u32) -> Result<Arc<Database>, String> {
  state
    .open
    .lock()
    .map_err(|_| "The mail copies are unavailable.".to_string())?
    .get(&handle)
    .cloned()
    .ok_or_else(|| "The mail copy is closed.".to_string())
}

fn connection(database: &Database, on: On) -> Result<std::sync::MutexGuard<'_, Connection>, String> {
  match on {
    On::Reader => database.reader.lock(),
    On::Writer => database.writer.lock(),
  }
  .map_err(|_| "The mail copy is unavailable.".to_string())
}

fn to_sql(value: Value) -> Result<SqlValue, String> {
  match value {
    Value::Null => Ok(SqlValue::Null),
    Value::Bool(flag) => Ok(SqlValue::Integer(i64::from(flag))),
    Value::Number(number) => match number.as_i64() {
      Some(integer) => Ok(SqlValue::Integer(integer)),
      None => number.as_f64().map(SqlValue::Real).ok_or_else(|| format!("{number} cannot be stored.")),
    },
    Value::String(text) => Ok(SqlValue::Text(text)),
    other => Err(format!("{other} cannot be stored: only text, numbers and null are.")),
  }
}

fn to_json(value: ValueRef<'_>) -> Result<Value, String> {
  Ok(match value {
    ValueRef::Null => Value::Null,
    ValueRef::Integer(integer) => Value::Number(integer.into()),
    ValueRef::Real(real) => Number::from_f64(real).map(Value::Number).unwrap_or(Value::Null),
    ValueRef::Text(text) => Value::String(String::from_utf8_lossy(text).into_owned()),
    ValueRef::Blob(_) => return Err("The mail copy holds no binary data.".to_string()),
  })
}

fn bind(params: Vec<Value>) -> Result<Vec<SqlValue>, String> {
  params.into_iter().map(to_sql).collect()
}

/// Opens (or creates) a mail copy and returns its handle.
#[tauri::command]
pub fn sql_open(app: AppHandle, state: State<'_, Databases>, name: String) -> Result<u32, String> {
  let path = path(&app, &name)?;
  let writer = connect(&path, OpenFlags::SQLITE_OPEN_READ_WRITE | OpenFlags::SQLITE_OPEN_CREATE | OpenFlags::SQLITE_OPEN_NO_MUTEX)?;
  writer
    .execute_batch("PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL;")
    .map_err(|error| format!("Preparing the mail copy failed: {error}"))?;
  let reader = connect(&path, OpenFlags::SQLITE_OPEN_READ_ONLY | OpenFlags::SQLITE_OPEN_NO_MUTEX)?;
  let handle = state.next.fetch_add(1, Ordering::Relaxed);
  let mut open = state.open.lock().map_err(|_| "The mail copies are unavailable.".to_string())?;
  // Handles of the same copy left by a web view that reloaded can never be used again: they go, and a transaction one
  // of them left open is rolled back as its connection closes.
  open.retain(|_, database| database.path != path);
  open.insert(handle, Arc::new(Database { path, writer: Mutex::new(writer), reader: Mutex::new(reader) }));
  Ok(handle)
}

/// Runs statements without results (several may be given at once, without parameters).
#[tauri::command]
pub fn sql_exec(state: State<'_, Databases>, handle: u32, source: String) -> Result<(), String> {
  let database = database(&state, handle)?;
  connection(&database, On::Writer)?.execute_batch(&source).map_err(|error| error.to_string())
}

/// Runs one statement that changes the copy.
#[tauri::command]
pub fn sql_run(state: State<'_, Databases>, handle: u32, source: String, params: Vec<Value>) -> Result<(), String> {
  let database = database(&state, handle)?;
  let params = bind(params)?;
  let connection = connection(&database, On::Writer)?;
  let mut statement = connection.prepare_cached(&source).map_err(|error| error.to_string())?;
  statement.execute(params_from_iter(params)).map_err(|error| error.to_string())?;
  Ok(())
}

/// Runs one query and returns its rows as objects keyed by column name.
#[tauri::command]
pub fn sql_select(state: State<'_, Databases>, handle: u32, source: String, params: Vec<Value>, on: On) -> Result<Vec<Map<String, Value>>, String> {
  let database = database(&state, handle)?;
  let params = bind(params)?;
  let connection = connection(&database, on)?;
  let mut statement = connection.prepare_cached(&source).map_err(|error| error.to_string())?;
  let columns: Vec<String> = statement.column_names().into_iter().map(str::to_string).collect();
  let mut rows = statement.query(params_from_iter(params)).map_err(|error| error.to_string())?;
  let mut result = Vec::new();
  while let Some(row) = rows.next().map_err(|error| error.to_string())? {
    let mut object = Map::with_capacity(columns.len());
    for (index, column) in columns.iter().enumerate() {
      object.insert(column.clone(), to_json(row.get_ref(index).map_err(|error| error.to_string())?)?);
    }
    result.push(object);
  }
  Ok(result)
}

/// Closes a copy. Its handle stops working; closing one that is closed already does nothing.
#[tauri::command]
pub fn sql_close(state: State<'_, Databases>, handle: u32) -> Result<(), String> {
  let removed = state.open.lock().map_err(|_| "The mail copies are unavailable.".to_string())?.remove(&handle);
  if let Some(database) = removed {
    // Folds the write-ahead log back into the database, so a closed copy is a single file.
    if let Ok(writer) = database.writer.lock() {
      let _ = writer.execute_batch("PRAGMA wal_checkpoint(TRUNCATE);");
    }
  }
  Ok(())
}

/// Removes a closed copy from the computer, e.g. on sign-out. A copy that is still open is refused.
#[tauri::command]
pub fn sql_delete(app: AppHandle, state: State<'_, Databases>, name: String) -> Result<(), String> {
  let path = path(&app, &name)?;
  let still_open = state.open.lock().map_err(|_| "The mail copies are unavailable.".to_string())?.values().any(|database| database.path == path);
  if still_open {
    return Err("The mail copy is still open.".to_string());
  }
  for suffix in ["", "-wal", "-shm"] {
    let file = PathBuf::from(format!("{}{suffix}", path.display()));
    match std::fs::remove_file(&file) {
      Ok(()) => {}
      Err(error) if error.kind() == std::io::ErrorKind::NotFound => {}
      Err(error) => return Err(format!("Removing the mail copy failed: {error}")),
    }
  }
  Ok(())
}

#[cfg(test)]
mod tests {
  use super::*;

  #[test]
  fn converts_values_both_ways() {
    assert!(matches!(to_sql(Value::Null), Ok(SqlValue::Null)));
    assert!(matches!(to_sql(serde_json::json!(42)), Ok(SqlValue::Integer(42))));
    assert!(matches!(to_sql(serde_json::json!(1.5)), Ok(SqlValue::Real(r)) if (r - 1.5).abs() < f64::EPSILON));
    assert!(matches!(to_sql(serde_json::json!("x")), Ok(SqlValue::Text(t)) if t == "x"));
    assert!(to_sql(serde_json::json!([1])).is_err());

    assert_eq!(to_json(ValueRef::Integer(7)), Ok(serde_json::json!(7)));
    assert_eq!(to_json(ValueRef::Text(b"hi")), Ok(serde_json::json!("hi")));
    assert!(to_json(ValueRef::Blob(b"x")).is_err());
  }
}
