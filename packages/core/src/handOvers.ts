import { composeBody, escapeHtml, replySubject } from './compose'
import type { Composer } from './composer'
import { defaultSignatureOf } from './drafts'
import type { Draft } from './store/mailStore'
import type { Address, SyncedHandOver, SyncedMailAccount, SyncedMessage, SyncedOutgoingMessage, SyncedSignature } from './sync/types'

/** What the API accepts for a note; the web form checks the same. */
export const MAX_NOTE = 10_000
/** A due date is at most this far ahead (the API refuses later ones). */
export const MAX_DUE_DAYS = 365
/** The hour of the day a due date falls on: the end of a working day. */
export const DUE_HOUR = 17

/** POST /api/v1/hand-overs */
export interface CreateHandOverRequest {
  messageId: string
  delegate: Address
  note: string
  /** ISO 8601. */
  dueAt: string
  /** The outgoing message that forwarded it. */
  forwardId: string
}

/** PATCH /api/v1/hand-overs/{id} */
export interface UpdateHandOverRequest {
  dueAt?: string
  /** True marks it done; false puts it back in "Waiting on". */
  done?: boolean
}

/** POST /api/v1/hand-overs/{id}/nudge */
export interface NudgeRequest {
  html: string
  /** ISO 8601; a few seconds ahead leaves room to undo. */
  sendAt?: string
}

export interface HandOverApi {
  create(request: CreateHandOverRequest): Promise<SyncedHandOver>
  update(id: string, request: UpdateHandOverRequest): Promise<SyncedHandOver>
  withdraw(id: string): Promise<void>
  nudge(id: string, request: NudgeRequest): Promise<SyncedHandOver>
}

/**
 * Whether a stored hand-over just became overdue, which is when the user is told: open, past its due date without a
 * reply, and not overdue in the copy before. A new due date clears `overdueAt`, so passing that one tells again.
 */
export function becameOverdue(previous: SyncedHandOver | null | undefined, next: SyncedHandOver): boolean {
  return next.state === 'open' && next.overdueAt !== null && (previous?.overdueAt ?? null) === null
}

/** "Waiting on" order: open hand-overs by due date first, then closed ones, most recently closed first. */
export function compareHandOvers(a: SyncedHandOver, b: SyncedHandOver): number {
  const aOpen = a.state === 'open'
  const bOpen = b.state === 'open'
  if (aOpen !== bOpen) {
    return aOpen ? -1 : 1
  }
  if (aOpen) {
    return Date.parse(a.dueAt) - Date.parse(b.dueAt) || a.id.localeCompare(b.id)
  }
  return Date.parse(b.closedAt ?? b.updatedAt) - Date.parse(a.closedAt ?? a.updatedAt) || b.id.localeCompare(a.id)
}

export type HandOverStatus = 'overdue' | 'waiting' | 'replied' | 'done'

/** How a hand-over stands now: an open one whose due date passed is overdue, also before the server marked it. */
export function handOverStatus(handOver: SyncedHandOver, now: Date = new Date()): HandOverStatus {
  if (handOver.state !== 'open') {
    return handOver.state
  }
  return Date.parse(handOver.dueAt) <= now.getTime() ? 'overdue' : 'waiting'
}

/** The open hand-overs of a conversation, soonest due first. */
export function openHandOversOf(handOvers: SyncedHandOver[], threadId: string): SyncedHandOver[] {
  return handOvers.filter((handOver) => handOver.state === 'open' && handOver.threadId === threadId).sort(compareHandOvers)
}

/** Whether its latest nudge counts: it was not cancelled and did not fail. */
export function nudgeStands(handOver: SyncedHandOver, nudge: SyncedOutgoingMessage | null): boolean {
  if (handOver.nudgedAt === null) {
    return false
  }
  return nudge === null || (nudge.status !== 'cancelled' && nudge.status !== 'failed')
}

/** The name to greet someone by: the first word of their name, else their address before the @. */
export function firstName(person: Address): string {
  const name = person.name?.trim().split(/\s+/)[0]
  return name !== undefined && name !== '' ? name : (person.address.split('@')[0] ?? person.address)
}

/** Plain text as HTML paragraphs: a blank line starts a paragraph, a line break stays one. */
export function textToHtml(text: string): string {
  return text
    .trim()
    .split(/\n\s*\n/)
    .map((paragraph) => `<p>${escapeHtml(paragraph.trim()).replace(/\n/g, '<br>')}</p>`)
    .join('')
}

/** What the forward says above the original: the note, then when an answer is due. */
export function noteHtml(note: string, dueLabel: string): string {
  const due = `<p>Could you get back to me by ${escapeHtml(dueLabel)}?</p>`
  return note.trim() === '' ? due : `${textToHtml(note)}${due}`
}

/** A suggested follow-up for the delegate, in plain text the user can change before it goes. */
export function nudgeText(handOver: SyncedHandOver, dueLabel: string, now: Date = new Date()): string {
  const name = firstName(handOver.delegate)
  const overdue = Date.parse(handOver.dueAt) <= now.getTime()
  const when = overdue ? `It was due ${dueLabel}.` : `It is due ${dueLabel}.`
  return `Hi ${name},\n\nJust checking in on "${handOver.subject}", which I forwarded to you. ${when} Could you let me know where it stands?\n\nThanks`
}

/** The same day at the hour due dates fall on. */
export function dueOn(day: Date): Date {
  const at = new Date(day)
  at.setHours(DUE_HOUR, 0, 0, 0)
  return at
}

export type DueChoice = 'today' | 'tomorrow' | 'friday' | 'nextWeek'

/**
 * The due dates offered with one click: today (while there is still an hour of it left), tomorrow, and this Friday when
 * it lies beyond tomorrow, else Monday next week.
 */
export function dueChoices(now: Date = new Date()): { key: DueChoice; at: Date }[] {
  const day = (offset: number) => {
    const at = new Date(now)
    at.setDate(at.getDate() + offset)
    return dueOn(at)
  }
  const choices: { key: DueChoice; at: Date }[] = []
  const today = dueOn(now)
  if (today.getTime() - now.getTime() >= 60 * 60 * 1000) {
    choices.push({ key: 'today', at: today })
  }
  choices.push({ key: 'tomorrow', at: day(1) })
  const toFriday = (5 - now.getDay() + 7) % 7
  if (toFriday > 1) {
    choices.push({ key: 'friday', at: day(toFriday) })
  } else {
    choices.push({ key: 'nextWeek', at: day(((1 - now.getDay() + 7) % 7) || 7) })
  }
  return choices
}

/** What the hand-over form collects. */
export interface HandOverInput {
  delegate: Address
  note: string
  dueAt: Date
  /** How the due date reads in the forward, in the writer's words ("Friday 24 October"). */
  dueLabel: string
}

/** A hand-over just made, which can be taken back until its forward leaves. */
export interface HandedOver {
  handOver: SyncedHandOver
  forwardId: string
  draftId: string
  /** Epoch ms when the forward leaves. */
  until: number
}

/** A nudge just queued, which can be taken back until it leaves. */
export interface Nudged {
  handOver: SyncedHandOver
  nudgeId: string
  until: number
}

export class HandOverError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'HandOverError'
  }
}

export interface HandOverFlowDeps {
  /** A composer of its own, not the one on screen: handing over writes and sends the forward in the background. */
  composer: Composer
  api: HandOverApi
  /** Cancels an outgoing message (undo send). */
  cancel(outgoingId: string): Promise<SyncedOutgoingMessage>
  accounts(): SyncedMailAccount[]
  signatures(): SyncedSignature[]
  /** Pulls the delta, so the copy shows what changed. */
  sync(): Promise<void>
  /** What a failed request means for the user. */
  explain(error: unknown): { message: string }
  /** Seconds a nudge waits before it goes out, so it can be undone; the composer's own window applies to forwards. */
  undoSeconds?: number
}

/**
 * Handing over, the same on every client: the conversation is forwarded to the delegate with the note through the
 * shared compose flow (its files go along), and the hand-over is recorded once the forward is queued. Until the forward
 * leaves, both can be taken back at once. Nudges reply to the delegate in the same conversation.
 */
export class HandOverFlow {
  constructor(private readonly deps: HandOverFlowDeps) {}

  /** @throws HandOverError with the reason, after taking back whatever was done already */
  async handOver(message: SyncedMessage, input: HandOverInput): Promise<HandedOver> {
    const composer = this.deps.composer
    await composer.openReply(message, 'forward')
    const draft = composer.state.draft
    if (draft === null) {
      throw new HandOverError('You were signed out. Sign in again to hand this over.')
    }
    if (composer.state.error !== null) {
      // A file of the original could not go along: rather than forward without it, nothing is sent.
      const reason = composer.state.error
      await composer.discard()
      throw new HandOverError(reason)
    }
    composer.update({ to: [input.delegate], html: noteHtml(input.note, input.dueLabel) })
    const forward = await composer.send()
    if (forward === null) {
      const reason = composer.state.error ?? 'The forward could not be sent.'
      await composer.discard()
      throw new HandOverError(reason)
    }
    const undoable = composer.state.undoable
    let handOver: SyncedHandOver
    try {
      handOver = await this.deps.api.create({
        messageId: message.id,
        delegate: input.delegate,
        note: input.note.trim(),
        dueAt: input.dueAt.toISOString(),
        forwardId: forward.id,
      })
    } catch (error) {
      // Not recorded: the forward is stopped too, so nothing is handed over that "Waiting on" would not show.
      await this.takeBackForward(forward.id, draft.id).catch(() => undefined)
      throw new HandOverError(this.deps.explain(error).message)
    }
    await this.deps.sync().catch(() => undefined)
    return { handOver, forwardId: forward.id, draftId: draft.id, until: undoable?.until ?? Date.now() }
  }

  /**
   * Stops the forward and withdraws the hand-over. Once the forward has left it is too late: the hand-over stays.
   *
   * @throws HandOverError when it is too late or the server cannot be reached
   */
  async undo(handed: HandedOver): Promise<void> {
    try {
      await this.takeBackForward(handed.forwardId, handed.draftId)
    } catch (error) {
      throw new HandOverError(`Too late to undo: ${this.deps.explain(error).message}`)
    }
    try {
      await this.deps.api.withdraw(handed.handOver.id)
    } finally {
      await this.deps.sync().catch(() => undefined)
    }
  }

  /** Queues the follow-up (plain text, signed with the account's default signature) as a reply to the delegate. */
  async nudge(handOver: SyncedHandOver, text: string): Promise<Nudged> {
    const signatureId = defaultSignatureOf(this.deps.accounts(), this.deps.signatures(), handOver.mailAccountId)
    const signature = this.deps.signatures().find((candidate) => candidate.id === signatureId)?.html ?? null
    const until = Date.now() + (this.deps.undoSeconds ?? 5) * 1000
    const nudged = await this.deps.api.nudge(handOver.id, { html: composeBody(textToHtml(text), signature, ''), sendAt: new Date(until).toISOString() })
    await this.deps.sync().catch(() => undefined)
    if (nudged.lastNudgeId === null) {
      throw new HandOverError('The nudge was not queued.')
    }
    return { handOver: nudged, nudgeId: nudged.lastNudgeId, until }
  }

  async undoNudge(nudged: Nudged): Promise<void> {
    try {
      await this.deps.cancel(nudged.nudgeId)
    } catch (error) {
      throw new HandOverError(`Too late to undo: ${this.deps.explain(error).message}`)
    } finally {
      await this.deps.sync().catch(() => undefined)
    }
  }

  markDone(handOver: SyncedHandOver): Promise<SyncedHandOver> {
    return this.change(handOver, { done: true })
  }

  reopen(handOver: SyncedHandOver): Promise<SyncedHandOver> {
    return this.change(handOver, { done: false })
  }

  reschedule(handOver: SyncedHandOver, dueAt: Date): Promise<SyncedHandOver> {
    return this.change(handOver, { dueAt: dueAt.toISOString() })
  }

  async withdraw(handOver: SyncedHandOver): Promise<void> {
    await this.deps.api.withdraw(handOver.id)
    await this.deps.sync().catch(() => undefined)
  }

  private async change(handOver: SyncedHandOver, request: UpdateHandOverRequest): Promise<SyncedHandOver> {
    const changed = await this.deps.api.update(handOver.id, request)
    await this.deps.sync().catch(() => undefined)
    return changed
  }

  /** Cancels the forward and throws away the draft it came back as. */
  private async takeBackForward(forwardId: string, draftId: string): Promise<void> {
    await this.deps.cancel(forwardId)
    await this.deps.composer.openDraft(draftId)
    await this.deps.composer.discard()
  }
}

/** A follow-up to write in the composer ("Edit first"): to the delegate, in the conversation that was handed over. */
export function followUpDraft(handOver: SyncedHandOver, text: string, forwardSubject: string | null): Pick<Draft, 'kind' | 'mailAccountId' | 'to' | 'subject' | 'html' | 'inReplyToMessageId'> {
  return {
    kind: 'reply',
    mailAccountId: handOver.mailAccountId,
    to: [handOver.delegate],
    subject: replySubject(forwardSubject ?? handOver.subject),
    html: textToHtml(text),
    inReplyToMessageId: handOver.messageId,
  }
}
