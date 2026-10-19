import { useEffect, useState } from 'react'
import { ActivityIndicator, Linking, Pressable, StyleSheet, Text, View } from 'react-native'
import type { MessageBody } from '@tresso/core'
import { AttachmentRow } from '@/components/AttachmentRow'
import { HtmlFrame } from '@/components/HtmlFrame'
import { useMail } from '@/mail/MailProvider'
import { useTheme, type Theme } from '@/theme'

/** One message's body: sanitized HTML in a locked-down web view, or its plain text. */
export function MessageBodyView({ messageId }: { messageId: string }) {
  const mail = useMail()
  const theme = useTheme()
  const styles = makeStyles(theme)
  const [body, setBody] = useState<MessageBody | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [remote, setRemote] = useState(false)
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

  // Inline images are part of the text; the rest are files to open.
  const files = body.attachments.filter((attachment) => !attachment.inline)

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
        <HtmlFrame html={body.html} allowRemoteContent={body.remoteContentLoaded} accessibilityLabel="Message" />
      ) : (
        <Text style={styles.text} selectable>
          {body.text ?? ''}
        </Text>
      )}
      {files.length > 0 && (
        <View style={styles.files} accessibilityLabel="Attachments">
          {files.map((file) => {
            const url = file.url
            return (
              <AttachmentRow
                key={file.id}
                filename={file.filename}
                size={file.size}
                scanStatus={file.scanStatus}
                {...(url === null ? {} : { onOpen: () => void Linking.openURL(url) })}
              />
            )
          })}
        </View>
      )}
    </View>
  )
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    wrapper: { gap: 8 },
    files: { gap: 8 },
    text: { color: theme.ink, fontSize: 16, lineHeight: 24 },
    loading: { paddingVertical: 16 },
    error: { color: theme.danger, fontSize: 14 },
    remote: { padding: 10, borderRadius: 6, backgroundColor: theme.ground },
    remoteLabel: { color: theme.accent, fontSize: 14, fontWeight: '600' },
  })
}
