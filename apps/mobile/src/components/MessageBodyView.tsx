import { useEffect, useState } from 'react'
import { ActivityIndicator, Linking, Pressable, StyleSheet, Text, View } from 'react-native'
import { WebView, type WebViewMessageEvent } from 'react-native-webview'
import { mailFrameDocument, type MessageBody } from '@tresso/core'
import { config } from '@/config'
import { useMail } from '@/mail/MailProvider'
import { useTheme, type Theme } from '@/theme'

const API_ORIGIN = new URL(config.apiUrl).origin

// Reports the document's height so the view can size to it. It is added by the app, not by the mail: the document's
// Content-Security-Policy keeps any script in the message itself from running.
const MEASURE = `
  (function () {
    function report() { window.ReactNativeWebView.postMessage(String(document.documentElement.scrollHeight)) }
    new ResizeObserver(report).observe(document.documentElement)
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
        >
          <Text style={styles.remoteLabel}>Images from the internet are blocked. Show them</Text>
        </Pressable>
      )}
      {body.html !== null ? (
        <WebView
          style={[styles.web, { height }]}
          originWhitelist={['about:*']}
          source={{ html: mailFrameDocument(body.html, { apiOrigin: API_ORIGIN, allowRemoteContent: body.remoteContentLoaded }), baseUrl: '' }}
          scrollEnabled={false}
          javaScriptEnabled
          injectedJavaScript={MEASURE}
          onMessage={(event: WebViewMessageEvent): void => {
            const measured = Number(event.nativeEvent.data)
            if (Number.isFinite(measured) && measured > 0) {
              setHeight(Math.ceil(measured))
            }
          }}
          // Links open in the browser (or the mail app), never inside the message view.
          onShouldStartLoadWithRequest={(request): boolean => {
            if (request.url.startsWith('about:')) {
              return true
            }
            if (/^(https?|mailto|tel):/i.test(request.url)) {
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
