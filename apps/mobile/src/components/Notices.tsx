import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from 'react'
import { Pressable, StyleSheet, Text, View } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { useTheme, type Theme } from '@/theme'

export interface Notice {
  text: string
  tone: 'info' | 'error'
  action?: { label: string; run: () => void }
}

interface Notices {
  notify(notice: Notice, ms?: number): void
}

const NoticeContext = createContext<Notices | null>(null)

/** Short messages at the bottom of the screen ("Archived." with Undo, or an error), one at a time. */
export function NoticeProvider({ children }: { children: ReactNode }) {
  const theme = useTheme()
  const styles = useMemo(() => makeStyles(theme), [theme])
  const insets = useSafeAreaInsets()
  const [notice, setNotice] = useState<Notice | null>(null)
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)

  const notify = useCallback((next: Notice, ms = 8000) => {
    clearTimeout(timer.current)
    setNotice(next)
    timer.current = setTimeout(() => setNotice(null), ms)
  }, [])
  const value = useMemo(() => ({ notify }), [notify])

  return (
    <NoticeContext.Provider value={value}>
      {children}
      {notice !== null && (
        <View style={[styles.notice, { bottom: insets.bottom + 16 }]} accessibilityRole={notice.tone === 'error' ? 'alert' : 'summary'} accessibilityLiveRegion="polite">
          <Text style={styles.text}>{notice.text}</Text>
          {notice.action !== undefined && (
            <Pressable
              onPress={() => {
                notice.action?.run()
                setNotice(null)
              }}
              accessibilityRole="button"
              hitSlop={12}
            >
              <Text style={styles.action}>{notice.action.label}</Text>
            </Pressable>
          )}
        </View>
      )}
    </NoticeContext.Provider>
  )
}

export function useNotices(): Notices {
  const notices = useContext(NoticeContext)
  if (notices === null) {
    throw new Error('useNotices() is used outside <NoticeProvider>.')
  }
  return notices
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    notice: { position: 'absolute', left: 16, right: 16, flexDirection: 'row', alignItems: 'center', gap: 16, paddingVertical: 12, paddingHorizontal: 16, borderRadius: 10, backgroundColor: theme.ink },
    text: { flex: 1, color: theme.surface, fontSize: 15 },
    action: { color: theme.surface, fontSize: 15, fontWeight: '700' },
  })
}
