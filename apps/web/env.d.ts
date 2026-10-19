/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_URL: string
  readonly VITE_OAUTH_CLIENT_ID: string
  readonly VITE_DESKTOP_OAUTH_CLIENT_ID: string
  readonly VITE_NATIVE_REDIRECT_URI: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
