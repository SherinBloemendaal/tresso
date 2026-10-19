import { useColorScheme } from 'react-native'

// The web client's tokens (apps/web/src/assets/main.css), so both apps look like one product.
const light = {
  ground: '#f3f4f6',
  surface: '#ffffff',
  line: '#e3e5e9',
  /** Borders of controls (chips, buttons), a step stronger than dividers. */
  lineStrong: '#d5d8de',
  ink: '#16181d',
  ink2: '#4a505c',
  danger: '#b42318',
  accent: '#2341d6',
  onAccent: '#ffffff',
  /** The "know" verdict's color, also used for good news such as "no threats". */
  know: '#256b5d',
}

const dark: typeof light = {
  ground: '#0f1115',
  surface: '#171a20',
  line: '#2a2f38',
  lineStrong: '#3a3f4a',
  ink: '#eceef2',
  ink2: '#b5bbc6',
  danger: '#f97066',
  accent: '#8fa2ff',
  onAccent: '#0f1115',
  know: '#6cc7b2',
}

export type Theme = typeof light

export function useTheme(): Theme {
  return useColorScheme() === 'dark' ? dark : light
}
