import { Alert } from 'react-native'
import type { TodoPlanAction } from '@/calendar/todoPlan'

/** Asks what to do with a todo's time in the calendar, as a native menu; `title` and `message` say where it is now. */
export function askTodoPlan(title: string, message: string, choices: { action: TodoPlanAction; label: string }[], run: (action: TodoPlanAction) => void): void {
  Alert.alert(title, message, [
    ...choices.map((choice) => ({ text: choice.label, style: choice.action === 'unplan' ? ('destructive' as const) : ('default' as const), onPress: () => run(choice.action) })),
    { text: 'Cancel', style: 'cancel' as const },
  ])
}
