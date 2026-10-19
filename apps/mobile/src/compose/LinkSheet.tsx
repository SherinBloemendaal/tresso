import { useMemo, useState } from 'react'
import { KeyboardAvoidingView, Modal, Platform, Pressable, StyleSheet, Text, TextInput, View } from 'react-native'
import { linkAddress } from '@/compose/editorProtocol'
import { useTheme, type Theme } from '@/theme'

interface Props {
  /** The link around the cursor, to change or remove; null to add one. */
  current: string | null
  onDone(href: string | null): void
  onClose(): void
}

/** Adds, changes or removes a link in the message: web addresses and mail addresses only. Shown while mounted. */
export function LinkSheet({ current, onDone, onClose }: Props) {
  const theme = useTheme()
  const styles = useMemo(() => makeStyles(theme), [theme])
  const [value, setValue] = useState(current ?? '')
  const [error, setError] = useState<string | null>(null)

  function done(): void {
    const href = linkAddress(value)
    if (href === null) {
      setError('Enter a web address (https://…) or an email address.')
      return
    }
    onDone(href)
  }

  return (
    <Modal visible transparent animationType="fade" onRequestClose={onClose} statusBarTranslucent>
      <KeyboardAvoidingView style={styles.backdrop} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <View style={styles.card} accessibilityViewIsModal>
          <Text style={styles.title} accessibilityRole="header">
            {current === null ? 'Add link' : 'Edit link'}
          </Text>
          <TextInput
            style={[styles.input, error !== null && styles.inputWrong]}
            value={value}
            onChangeText={(next) => {
              setValue(next)
              setError(null)
            }}
            onSubmitEditing={done}
            placeholder="https://"
            placeholderTextColor={theme.ink2}
            keyboardType="url"
            autoCapitalize="none"
            autoCorrect={false}
            autoFocus
            accessibilityLabel="Link address"
            accessibilityHint={error ?? undefined}
          />
          {error !== null && (
            <Text style={styles.error} accessibilityRole="alert">
              {error}
            </Text>
          )}
          <View style={styles.buttons}>
            {current !== null && (
              <Pressable style={styles.button} onPress={() => onDone(null)} accessibilityRole="button">
                <Text style={[styles.buttonLabel, styles.danger]}>Remove</Text>
              </Pressable>
            )}
            <View style={styles.spacer} />
            <Pressable style={styles.button} onPress={onClose} accessibilityRole="button">
              <Text style={styles.buttonLabel}>Cancel</Text>
            </Pressable>
            <Pressable style={[styles.button, styles.primary]} onPress={done} accessibilityRole="button">
              <Text style={[styles.buttonLabel, styles.primaryLabel]}>Done</Text>
            </Pressable>
          </View>
        </View>
      </KeyboardAvoidingView>
    </Modal>
  )
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    backdrop: { flex: 1, justifyContent: 'center', padding: 24, backgroundColor: 'rgba(15, 17, 21, 0.4)' },
    card: { borderRadius: 14, padding: 16, gap: 10, backgroundColor: theme.surface },
    title: { fontSize: 17, fontWeight: '600', color: theme.ink },
    input: { height: 44, paddingHorizontal: 12, borderRadius: 6, borderWidth: 1, borderColor: theme.lineStrong, fontSize: 15, color: theme.ink },
    inputWrong: { borderColor: theme.danger },
    error: { fontSize: 13, color: theme.danger },
    buttons: { flexDirection: 'row', alignItems: 'center', gap: 8 },
    spacer: { flex: 1 },
    button: { minHeight: 44, paddingHorizontal: 14, borderRadius: 999, justifyContent: 'center' },
    buttonLabel: { fontSize: 15, fontWeight: '600', color: theme.ink2 },
    danger: { color: theme.danger },
    primary: { backgroundColor: theme.ink },
    primaryLabel: { color: theme.surface },
  })
}
