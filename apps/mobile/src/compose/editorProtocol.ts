/** What the compose screen tells the message editor in its web view. */
export type EditorCommand =
  | { type: 'set'; html: string }
  | { type: 'focus' }
  | { type: 'bold' }
  | { type: 'italic' }
  | { type: 'bulletList' }
  /** null removes the link around the cursor; `text` is shown when nothing is selected (default: the address). */
  | { type: 'link'; href: string | null; text?: string }
  | { type: 'undo' }
  | { type: 'redo' }

/** What the message editor tells the compose screen. */
export type EditorEvent =
  | { type: 'ready' }
  /** The user changed the text; '' when it is empty. */
  | { type: 'change'; html: string }
  | { type: 'state'; bold: boolean; italic: boolean; bulletList: boolean; link: string | null; canUndo: boolean; canRedo: boolean }
  | { type: 'focus'; focused: boolean }
  | { type: 'height'; value: number }
  | { type: 'error'; message: string }

const TYPES = new Set(['ready', 'change', 'state', 'focus', 'height', 'error'])

/** Reads a message from the editor, or null for anything that is not one. */
export function parseEditorEvent(data: string): EditorEvent | null {
  try {
    const value = JSON.parse(data) as { type?: unknown }
    return typeof value === 'object' && value !== null && typeof value.type === 'string' && TYPES.has(value.type) ? (value as EditorEvent) : null
  } catch {
    return null
  }
}

/** The script that hands a command to the editor, for `WebView.injectJavaScript`. */
export function editorScript(command: EditorCommand): string {
  // JSON inside a JSON string literal: the command arrives exactly as sent, whatever the text holds.
  return `window.tresso && window.tresso.receive(${JSON.stringify(JSON.stringify(command))}); true;`
}

/**
 * Whether a link the user typed is one the editor may hold: web and mail addresses only. A bare domain becomes https.
 * Returns the address to use, or null when it is not acceptable.
 */
export function linkAddress(input: string): string | null {
  const value = input.trim()
  if (value === '') {
    return null
  }
  if (/^mailto:[^\s@]+@[^\s@]+$/i.test(value)) {
    return value
  }
  if (/^[^\s@/:]+@[^\s@]+\.[^\s@]+$/.test(value)) {
    return `mailto:${value}`
  }
  const withScheme = /^[a-z][a-z0-9+.-]*:/i.test(value) ? value : `https://${value}`
  try {
    const url = new URL(withScheme)
    return (url.protocol === 'https:' || url.protocol === 'http:') && url.hostname.includes('.') ? url.toString() : null
  } catch {
    return null
  }
}
