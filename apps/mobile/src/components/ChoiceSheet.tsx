import { useMemo } from 'react'
import { Modal, Pressable, StyleSheet, Text, View } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { useTheme, type Theme } from '@/theme'

export interface Choice<K extends string> {
  key: K
  label: string
  detail?: string
  selected?: boolean
  tone?: 'danger'
}

interface Props<K extends string> {
  visible: boolean
  title: string
  choices: Choice<K>[]
  onChoose(key: K): void
  onClose(): void
}

/** A list of choices from the bottom of the screen: pick one, or tap outside or Cancel to keep things as they are. */
export function ChoiceSheet<K extends string>({ visible, title, choices, onChoose, onClose }: Props<K>) {
  const theme = useTheme()
  const styles = useMemo(() => makeStyles(theme), [theme])
  const insets = useSafeAreaInsets()

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose} statusBarTranslucent>
      <Pressable style={styles.backdrop} onPress={onClose} accessibilityLabel="Close" accessibilityRole="button" />
      <View style={[styles.sheet, { paddingBottom: insets.bottom + 8 }]} accessibilityViewIsModal>
        <Text style={styles.title} accessibilityRole="header">
          {title}
        </Text>
        {choices.map((choice) => (
          <Pressable
            key={choice.key}
            style={({ pressed }) => [styles.choice, pressed && styles.pressed]}
            onPress={() => onChoose(choice.key)}
            accessibilityRole="button"
            accessibilityState={choice.selected === undefined ? undefined : { selected: choice.selected }}
          >
            <View style={styles.texts}>
              <Text style={[styles.label, choice.tone === 'danger' && styles.danger]}>{choice.label}</Text>
              {choice.detail !== undefined && <Text style={styles.detail}>{choice.detail}</Text>}
            </View>
            {choice.selected === true && <Text style={styles.check}>✓</Text>}
          </Pressable>
        ))}
        <Pressable style={({ pressed }) => [styles.choice, styles.cancel, pressed && styles.pressed]} onPress={onClose} accessibilityRole="button">
          <Text style={styles.cancelLabel}>Cancel</Text>
        </Pressable>
      </View>
    </Modal>
  )
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    backdrop: { flex: 1, backgroundColor: 'rgba(15, 17, 21, 0.4)' },
    sheet: { backgroundColor: theme.surface, borderTopLeftRadius: 14, borderTopRightRadius: 14, paddingTop: 12 },
    title: { paddingHorizontal: 20, paddingBottom: 8, fontSize: 13, fontWeight: '600', color: theme.ink2 },
    choice: { flexDirection: 'row', alignItems: 'center', minHeight: 52, paddingHorizontal: 20, gap: 12 },
    texts: { flex: 1, gap: 2 },
    label: { fontSize: 16, color: theme.ink },
    danger: { color: theme.danger },
    detail: { fontSize: 13, color: theme.ink2 },
    check: { fontSize: 16, color: theme.accent },
    cancel: { justifyContent: 'center', borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: theme.line, marginTop: 4 },
    cancelLabel: { fontSize: 16, fontWeight: '600', color: theme.ink2 },
    pressed: { backgroundColor: theme.ground },
  })
}
