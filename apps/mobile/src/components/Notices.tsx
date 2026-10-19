import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { AccessibilityInfo, findNodeHandle, Pressable, StyleSheet, Text, View } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { useTheme, type Theme } from '@/theme'

export interface Notice {
  text: string
  tone: 'info' | 'error'
  action?: { label: string; accessibilityLabel?: string; run: () => void }
}

interface Notices {
  notify(notice: Notice, ms?: number): void
  /** Space a screen keeps free at the bottom (its own bar), so a notice never covers it; 0 when it leaves. */
  setBottomInset(points: number): void
}

const NoticeContext = createContext<Notices | null>(null)

/**
 * Short messages at the bottom of the screen ("Archived." with Undo, or an error), one at a time. They are announced
 * to screen readers, and while one is on, a notice with an action stays until it is used or closed, so there is time
 * to reach it.
 */
export function NoticeProvider({ children }: { children: ReactNode }) {
  const theme = useTheme()
  const styles = useMemo(() => makeStyles(theme), [theme])
  const insets = useSafeAreaInsets()
  const [notice, setNotice] = useState<Notice | null>(null)
  const [bottomInset, setBottomInset] = useState(0)
  const [screenReader, setScreenReader] = useState(false)
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  const undoButton = useRef<React.ComponentRef<typeof Pressable>>(null)

  useEffect(() => {
    void AccessibilityInfo.isScreenReaderEnabled().then(setScreenReader)
    const subscription = AccessibilityInfo.addEventListener('screenReaderChanged', setScreenReader)
    return () => {
      subscription.remove()
      clearTimeout(timer.current)
    }
  }, [])

  const notify = useCallback(
    (next: Notice, ms = 8000) => {
      clearTimeout(timer.current)
      setNotice(next)
      AccessibilityInfo.announceForAccessibility(next.action === undefined ? next.text : `${next.text} ${next.action.accessibilityLabel ?? next.action.label} is available.`)
      if (!(screenReader && next.action !== undefined)) {
        timer.current = setTimeout(() => setNotice(null), ms)
      }
    },
    [screenReader],
  )
  const value = useMemo(() => ({ notify, setBottomInset }), [notify])

  // A notice with an action puts the screen reader on its button, after the announcement had a moment.
  useEffect(() => {
    if (notice?.action === undefined || !screenReader) {
      return
    }
    const focus = setTimeout(() => {
      const handle = undoButton.current === null ? null : findNodeHandle(undoButton.current)
      if (handle != null) {
        AccessibilityInfo.setAccessibilityFocus(handle)
      }
    }, 600)
    return () => clearTimeout(focus)
  }, [notice, screenReader])

  return (
    <NoticeContext.Provider value={value}>
      {children}
      {notice !== null && (
        <View style={[styles.layer, { bottom: insets.bottom + bottomInset + 16 }]} pointerEvents="box-none">
          <View style={styles.notice} accessible={notice.action === undefined} accessibilityRole={notice.tone === 'error' ? 'alert' : 'summary'}>
            <Text style={styles.text}>{notice.text}</Text>
            {notice.action !== undefined && (
              <Pressable
                ref={undoButton}
                onPress={() => {
                  notice.action?.run()
                  setNotice(null)
                }}
                accessibilityRole="button"
                accessibilityLabel={notice.action.accessibilityLabel ?? notice.action.label}
                hitSlop={12}
              >
                <Text style={styles.action}>{notice.action.label}</Text>
              </Pressable>
            )}
            <Pressable onPress={() => setNotice(null)} accessibilityRole="button" accessibilityLabel="Dismiss" hitSlop={12}>
              <Text style={styles.close}>×</Text>
            </Pressable>
          </View>
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
    layer: { position: 'absolute', left: 16, right: 16 },
    notice: { flexDirection: 'row', alignItems: 'center', gap: 16, paddingVertical: 12, paddingHorizontal: 16, borderRadius: 10, backgroundColor: theme.ink },
    text: { flex: 1, color: theme.surface, fontSize: 15 },
    action: { color: theme.surface, fontSize: 15, fontWeight: '700' },
    close: { color: theme.surface, fontSize: 20, lineHeight: 20 },
  })
}
