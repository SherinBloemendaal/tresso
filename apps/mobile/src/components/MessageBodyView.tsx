import { useEffect, useRef, useState } from 'react'
import { ActivityIndicator, Linking, Pressable, StyleSheet, Text, View } from 'react-native'
import { WebView, type WebViewMessageEvent } from 'react-native-webview'
import { mailFrameDocument, type MessageBody } from '@tresso/core'
import { config } from '@/config'
import { MAX_BODY_HEIGHT, nextBodyHeight } from '@/mail/bodyHeight'
import { useMail } from '@/mail/MailProvider'
import { useTheme, type Theme } from '@/theme'

const API_ORIGIN = new URL(config.apiUrl).origin

// Reports the content's height so the view can size to it. It is added by the app, not by the mail: the document's
// Content-Security-Policy keeps any script in the message itself from running. The body is measured with heights
// set to auto, so mail sized to the viewport (100vh, height:100%) does not grow with every report.
const MEASURE = `
  (function () {
    var style = document.createElement('style')
    style.textContent = 'html,body{height:auto!important;min-height:0!important}'
    document.head.appendChild(style)
    function report() { window.ReactNativeWebView.postMessage(String(Math.ceil(document.body.getBoundingClientRect().height))) }
    new ResizeObserver(report).observe(document.body)
    window.addEventListener('load', report)
    report()
  })();
  true;
`
/** One message's body: sanitized HTML in a locked-down web view, or its plain text. */
export function MessageBodyView({ messageId }: { messageId: string }) {
  const mail = useMail()
  const theme = useTheme()
  const styles = makeStyles(theme)
  const [body, setBody] = useState<MessageBody | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [remote, setRemote] = useState(false)
  const [height, setHeight] = useState(80)
  /** The last growths, to tell content that grows with the view (sized in vh) from content that is just long. */
  const growths = useRef<number[]>([])
  const shown = useRef(80)
  const { body: load } = mail

  useEffect(() => {
    let current = true
    load(messageId, remote)
      .then((loaded) => current && setBody(loaded))
      .catch((e: unknown) => current && setError(e instanceof Error ? e.message : String(e)))
    return () => {
      current = false
    }
  }, [load, messageId, remote])

  if (error !== null) {
    return <Text style={styles.error}>{error}</Text>
  }
  if (body === null) {
    return <ActivityIndicator style={styles.loading} accessibilityLabel="Loading the message" />
  }

  return (
    <View style={styles.wrapper}>
      {body.hasRemoteContent && !body.remoteContentLoaded && (
        <Pressable
          style={styles.remote}
          onPress={() => {
            setError(null)
            setRemote(true)
          }}
          accessibilityRole="button"
          accessibilityLabel="Show images from the internet"
          accessibilityHint="Images from the internet are blocked so senders cannot see when you read their mail."
        >
          <Text style={styles.remoteLabel}>Images from the internet are blocked. Show them</Text>
        </Pressable>
      )}
      {body.html !== null ? (
        <WebView
          style={[styles.web, { height }]}
          originWhitelist={['about:*']}
          source={{ html: mailFrameDocument(body.html, { apiOrigin: API_ORIGIN, allowRemoteContent: body.remoteContentLoaded }), baseUrl: '' }}
          scrollEnabled={height >= MAX_BODY_HEIGHT}
          nestedScrollEnabled
          javaScriptEnabled
          allowFileAccess={false}
          mixedContentMode="never"
          injectedJavaScript={MEASURE}
          onMessage={(event: WebViewMessageEvent): void => {
            const measured = Number(event.nativeEvent.data)
            if (!Number.isFinite(measured) || measured <= 0) {
              return
            }
            // Worked out here rather than in a state updater, which React may run twice.
            shown.current = nextBodyHeight(shown.current, measured, growths.current)
            setHeight(shown.current)
          }}
          // A link the reader taps opens in the browser (or the mail app), never inside the message view; nothing
          // else leaves the page (a redirect, a frame).
          onShouldStartLoadWithRequest={(request): boolean => {
            if (request.url.startsWith('about:')) {
              return true
            }
            const tapped = request.isTopFrame !== false && (request.navigationType === undefined || request.navigationType === 'click')
            if (tapped && /^(https?|mailto|tel):/i.test(request.url)) {
              void Linking.openURL(request.url)
            }
            return false
          }}
          allowsLinkPreview={false}
          setSupportMultipleWindows={false}
          accessibilityLabel="Message"
        />
      ) : (
        <Text style={styles.text} selectable>
          {body.text ?? ''}
        </Text>
      )}
    </View>
  )
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    wrapper: { gap: 8 },
    web: { width: '100%', backgroundColor: '#ffffff' },
    text: { color: theme.ink, fontSize: 16, lineHeight: 24 },
    loading: { paddingVertical: 16 },
    error: { color: theme.danger, fontSize: 14 },
    remote: { padding: 10, borderRadius: 6, backgroundColor: theme.ground },
    remoteLabel: { color: theme.accent, fontSize: 14, fontWeight: '600' },
  })
}
