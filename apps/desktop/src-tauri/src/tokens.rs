//! The signed-in user's OAuth tokens, kept in the OS keychain (Keychain on macOS, Credential Manager on Windows, the
//! Secret Service on Linux) instead of web storage. The web client hands them over as an opaque JSON string.

use keyring::{Entry, Error};

const SERVICE: &str = "app.tresso.desktop";
const ACCOUNT: &str = "oauth-tokens";

fn entry() -> Result<Entry, String> {
  Entry::new(SERVICE, ACCOUNT).map_err(|error| format!("The keychain is not available: {error}"))
}

#[tauri::command]
pub fn load_tokens() -> Result<Option<String>, String> {
  match entry()?.get_password() {
    Ok(tokens) => Ok(Some(tokens)),
    Err(Error::NoEntry) => Ok(None),
    Err(error) => Err(format!("Reading the sign-in from the keychain failed: {error}")),
  }
}

#[tauri::command]
pub fn save_tokens(tokens: String) -> Result<(), String> {
  entry()?
    .set_password(&tokens)
    .map_err(|error| format!("Saving the sign-in in the keychain failed: {error}"))
}

#[tauri::command]
pub fn clear_tokens() -> Result<(), String> {
  match entry()?.delete_credential() {
    Ok(()) | Err(Error::NoEntry) => Ok(()),
    Err(error) => Err(format!("Removing the sign-in from the keychain failed: {error}")),
  }
}
