import { isPlausibleAddress, MAX_NOTE, type Address } from '@tresso/core'

/** Why the hand-over cannot go as it is, or null when it can; the API checks the due date and the note again. */
export function handOverProblem(delegate: Address[], note: string): string | null {
  if (delegate.length === 0) {
    return 'Choose who to hand it over to.'
  }
  if (delegate.length > 1) {
    return 'Hand it over to one person.'
  }
  if (!isPlausibleAddress(delegate[0]!.address)) {
    return `${delegate[0]!.address} is not a valid address.`
  }
  return note.length > MAX_NOTE ? `Use at most ${MAX_NOTE} characters in the note.` : null
}
