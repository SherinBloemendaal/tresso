import { useMemo, useState } from 'react'
import { Modal, Platform, Pressable, StyleSheet, Text, useColorScheme, View } from 'react-native'
import DateTimePicker, { DateTimePickerAndroid } from '@react-native-community/datetimepicker'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { isFutureTime } from '@/compose/time'
import { useTheme, type Theme } from '@/theme'


/** Android asks for the date and then the time in its own dialogs; null when either is dismissed. */
export function pickTimeOnAndroid(initial: Date): Promise<Date | null> {
  return new Promise((resolve) => {
    DateTimePickerAndroid.open({
      value: initial,
      mode: 'date',
      minimumDate: new Date(),
      onChange: (dateEvent, date) => {
        if (dateEvent.type !== 'set' || date === undefined) {
          resolve(null)
          return
        }
        DateTimePickerAndroid.open({
          value: date,
          mode: 'time',
          onChange: (timeEvent, time) => resolve(timeEvent.type === 'set' && time !== undefined ? time : null),
        })
      },
    })
  })
}

interface Props {
  initial: Date
  onPick(at: Date): void
  onClose(): void
}

/** On iOS: one picker for the date and the time, in a sheet. Shown while mounted. */
export function TimeSheet({ initial, onPick, onClose }: Props) {
  const theme = useTheme()
  const styles = useMemo(() => makeStyles(theme), [theme])
  const insets = useSafeAreaInsets()
  const scheme = useColorScheme()
  // The sheet's own moment: nothing earlier can be picked.
  const [opened] = useState(() => new Date())
  const [value, setValue] = useState(initial)
  const [error, setError] = useState<string | null>(null)

  if (Platform.OS !== 'ios') {
    return null
  }

  function done(): void {
    if (!isFutureTime(value)) {
      setError('Pick a time in the future.')
      return
    }
    onPick(value)
  }

  return (
    <Modal visible transparent animationType="slide" onRequestClose={onClose}>
      <Pressable style={styles.backdrop} onPress={onClose} accessibilityLabel="Close" accessibilityRole="button" />
      <View style={[styles.sheet, { paddingBottom: insets.bottom + 8 }]} accessibilityViewIsModal>
        <Text style={styles.title} accessibilityRole="header">
          Send at
        </Text>
        <DateTimePicker value={value} mode="datetime" display="inline" minimumDate={opened} onChange={(_, next) => next !== undefined && setValue(next)} themeVariant={scheme === 'dark' ? 'dark' : 'light'} />
        {error !== null && (
          <Text style={styles.error} accessibilityRole="alert">
            {error}
          </Text>
        )}
        <View style={styles.buttons}>
          <Pressable style={styles.button} onPress={onClose} accessibilityRole="button">
            <Text style={styles.buttonLabel}>Cancel</Text>
          </Pressable>
          <Pressable style={[styles.button, styles.primary]} onPress={done} accessibilityRole="button">
            <Text style={[styles.buttonLabel, styles.primaryLabel]}>Schedule</Text>
          </Pressable>
        </View>
      </View>
    </Modal>
  )
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    backdrop: { flex: 1, backgroundColor: 'rgba(15, 17, 21, 0.4)' },
    sheet: { backgroundColor: theme.surface, borderTopLeftRadius: 14, borderTopRightRadius: 14, paddingTop: 12, paddingHorizontal: 12 },
    title: { paddingHorizontal: 8, paddingBottom: 4, fontSize: 13, fontWeight: '600', color: theme.ink2 },
    error: { paddingHorizontal: 8, fontSize: 13, color: theme.danger },
    buttons: { flexDirection: 'row', justifyContent: 'flex-end', gap: 8, paddingTop: 8 },
    button: { minHeight: 44, paddingHorizontal: 16, borderRadius: 999, justifyContent: 'center' },
    buttonLabel: { fontSize: 15, fontWeight: '600', color: theme.ink2 },
    primary: { backgroundColor: theme.ink },
    primaryLabel: { color: theme.surface },
  })
}
