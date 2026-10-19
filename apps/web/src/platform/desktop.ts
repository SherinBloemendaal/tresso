import { invoke, isTauri } from '@tauri-apps/api/core'
import { getCurrent, onOpenUrl } from '@tauri-apps/plugin-deep-link'
import { openUrl } from '@tauri-apps/plugin-opener'

/**
 * The bridge to the Tauri desktop shell (`apps/desktop`). The web client runs unchanged inside it; only sign-in and
 * token storage go through the shell: the system browser signs in, a `tresso://` link brings the result back, and the
 * tokens live in the OS keychain.
 */
export const isDesktop = isTauri()

export function loadKeychainTokens(): Promise<string | null> {
  return invoke<string | null>('load_tokens')
}

export function saveKeychainTokens(tokens: string): Promise<void> {
  return invoke('save_tokens', { tokens })
}

export function clearKeychainTokens(): Promise<void> {
  return invoke('clear_tokens')
}

export function openInBrowser(url: string): Promise<void> {
  return openUrl(url)
}

/**
 * Calls `handle` with every link the app is opened with: the one that started it, if any, and each one after.
 */
export async function onDeepLinks(handle: (url: string) => void): Promise<void> {
  for (const url of (await getCurrent()) ?? []) {
    handle(url)
  }
  await onOpenUrl((urls) => urls.forEach(handle))
}
