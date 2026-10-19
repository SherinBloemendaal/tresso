/** The outcome every email converges on: something to do, to hand over, or to know. */
export type Verdict = 'do' | 'delegate' | 'know' | 'none'

export type BuiltinCategory = 'spam' | 'notification' | 'advertise' | 'todo' | 'informational'

/** Mirrors App\Enum\BuiltinCategory::defaultVerdict() in the API. */
export function defaultVerdict(category: BuiltinCategory): Verdict {
  switch (category) {
    case 'todo':
      return 'do'
    case 'informational':
    case 'notification':
    case 'advertise':
      return 'know'
    case 'spam':
      return 'none'
  }
}

export interface Me {
  id: string
  email: string
  displayName: string
  createdAt: string
}
