import { useMemo, useRef, useState } from 'react'
import { Linking, StyleSheet } from 'react-native'
import { WebView, type WebViewMessageEvent } from 'react-native-webview'
import { mailFrameDocument } from '@tresso/core'
import { config } from '@/config'
import { MAX_BODY_HEIGHT, nextBodyHeight } from '@/mail/bodyHeight'

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

interface Props {
  /** Sanitized HTML (a message body, a quote, a signature). */
  html: string
  allowRemoteContent: boolean
  accessibilityLabel: string
  /** Height before the content reported its own. */
  initialHeight?: number
}

/**
 * Sanitized HTML in a locked-down web view that sizes to its content. Links the reader taps open in the browser (or
 * the mail app), never inside the view; nothing else leaves the page.
 */
export function HtmlFrame({ html, allowRemoteContent, accessibilityLabel, initialHeight = 80 }: Props) {
  const [height, setHeight] = useState(initialHeight)
  /** The last growths, to tell content that grows with the view (sized in vh) from content that is just long. */
  const growths = useRef<number[]>([])
  const shown = useRef(initialHeight)
  const source = useMemo(() => ({ html: mailFrameDocument(html, { apiOrigin: API_ORIGIN, allowRemoteContent }), baseUrl: '' }), [html, allowRemoteContent])

  return (
    <WebView
      style={[styles.web, { height }]}
      originWhitelist={['about:*']}
      source={source}
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
      accessibilityLabel={accessibilityLabel}
    />
  )
}

const styles = StyleSheet.create({
  web: { width: '100%', backgroundColor: '#ffffff' },
})
