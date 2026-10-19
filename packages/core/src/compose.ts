import type { Address, SyncedMessage } from './sync/types'

/** POST /api/v1/outgoing-messages */
export interface SendMessageRequest {
  mailAccountId: string
  to: Address[]
  cc: Address[]
  bcc: Address[]
  subject: string
  html: string
  inReplyToMessageId?: string
  /** ISO 8601; omitted sends right away. */
  sendAt?: string
}

const REPLY_PREFIX = /^\s*((re|aw|sv|antw|vs|r)\s*(\[\d+\])?\s*:\s*)+/i
const FORWARD_PREFIX = /^\s*((fwd?|wg|doorst|tr|rv)\s*:\s*)+/i

export function replySubject(subject: string): string {
  const base = subject.replace(REPLY_PREFIX, '').trim()
  return `Re: ${base}`
}

export function forwardSubject(subject: string): string {
  const base = subject.replace(FORWARD_PREFIX, '').trim()
  return `Fwd: ${base}`
}

function key(address: Address): string {
  return address.address.trim().toLowerCase()
}

function unique(addresses: Address[], exclude: Set<string>): Address[] {
  const seen = new Set(exclude)
  const result: Address[] = []
  for (const address of addresses) {
    const k = key(address)
    if (k === '' || seen.has(k)) continue
    seen.add(k)
    result.push(address)
  }
  return result
}

/**
 * Who a reply goes to. A reply goes to Reply-To (or the sender); reply all adds everyone else on To and Cc. The
 * user's own addresses never get mail from themselves, except when they reply to a message they sent: then it goes
 * to that message's recipients again.
 */
export function replyRecipients(message: SyncedMessage, ownAddresses: string[], all: boolean): { to: Address[]; cc: Address[] } {
  const own = new Set(ownAddresses.map((a) => a.trim().toLowerCase()))
  const fromSelf = own.has(key(message.from))
  const primary = fromSelf ? message.recipients.to : message.recipients.replyTo.length > 0 ? message.recipients.replyTo : [message.from]
  const to = unique(primary, own)
  if (!all) {
    return { to: to.length > 0 ? to : unique([message.from], new Set()), cc: [] }
  }
  const taken = new Set([...own, ...to.map(key)])
  const others = fromSelf ? message.recipients.cc : [...message.recipients.to, ...message.recipients.cc]

  return { to: to.length > 0 ? to : unique([message.from], new Set()), cc: unique(others, taken) }
}

function escapeHtml(text: string): string {
  return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}

function formatAddress(address: Address): string {
  return address.name ? `${address.name} <${address.address}>` : address.address
}

/**
 * The quoted original below a reply or forward. `body` is the original's sanitized HTML from the API (or its text,
 * escaped). Dates are written in the writer's locale.
 */
export function quotedOriginal(message: SyncedMessage, body: { html: string | null; text: string | null }, kind: 'reply' | 'forward', locale?: string): string {
  const date = new Date(message.receivedAt).toLocaleString(locale, { dateStyle: 'full', timeStyle: 'short' })
  const content = body.html ?? `<p>${escapeHtml(body.text ?? '').replace(/\n/g, '<br>')}</p>`
  if (kind === 'reply') {
    return `<p>On ${escapeHtml(date)}, ${escapeHtml(formatAddress(message.from))} wrote:</p><blockquote>${content}</blockquote>`
  }
  const lines = [
    `From: ${formatAddress(message.from)}`,
    `Date: ${date}`,
    `Subject: ${message.subject}`,
    `To: ${message.recipients.to.map(formatAddress).join(', ')}`,
    ...(message.recipients.cc.length > 0 ? [`Cc: ${message.recipients.cc.map(formatAddress).join(', ')}`] : []),
  ]
  return `<p>---------- Forwarded message ----------<br>${lines.map(escapeHtml).join('<br>')}</p>${content}`
}

/** A rough check before sending; the API validates for real. */
export function isPlausibleAddress(value: string): boolean {
  return /^[^\s@<>()",;]+@[^\s@<>()",;]+\.[^\s@<>()",;]+$/.test(value.trim())
}

/** Parses what users paste: "Name <a@b.c>", "a@b.c", several separated by commas or semicolons. */
export function parseAddresses(input: string): Address[] {
  const result: Address[] = []
  for (const part of input.split(/[,;\n]+/)) {
    const trimmed = part.trim()
    if (trimmed === '') continue
    const match = /^(.*?)<([^>]+)>$/.exec(trimmed)
    if (match) {
      const name = match[1]!.trim().replace(/^"|"$/g, '')
      result.push({ address: match[2]!.trim(), name: name === '' ? null : name })
    } else {
      result.push({ address: trimmed, name: null })
    }
  }
  return result
}

/**
 * The HTML that is sent: what the user wrote, then the signature, then the quoted original, as mail clients expect
 * in a reply. The signature and quote are kept apart while writing so changing the sending account can swap the
 * signature without touching the text.
 */
export function composeBody(html: string, signatureHtml: string | null, quoteHtml: string): string {
  const parts = [html]
  if (signatureHtml !== null && signatureHtml.trim() !== '') {
    parts.push(`<div class="tresso-signature">${signatureHtml}</div>`)
  }
  if (quoteHtml.trim() !== '') {
    parts.push(`<div class="tresso-quote">${quoteHtml}</div>`)
  }
  return parts.join('')
}
