import type { DraftRequest } from './compose'
import type { AttachmentFile, DraftApi } from './drafts'
import type { DraftAttachment, DraftAttachmentLink, ScanStatus, SyncedDraft } from './sync/types'

/** An API failure with its HTTP status, as both clients' API errors carry it. */
export class ApiFailure extends Error {
  constructor(
    readonly status: number,
    readonly violations: Record<string, string> = {},
  ) {
    super(`HTTP ${status}`)
  }
}

/**
 * The drafts API as the server answers it, for tests: a second create of one id and a stale update are refused,
 * versions go up with every save, files are kept with the draft (forwarding one twice keeps one).
 */
export class FakeDraftApi implements DraftApi {
  readonly drafts = new Map<string, SyncedDraft>()
  readonly calls: string[] = []
  /** Runs while a create or update is under way, to make edits (or hold the request) meanwhile. */
  during: (() => Promise<void>) | null = null
  /** Runs while a file is being attached, to hold it there. */
  duringFile: (() => Promise<void>) | null = null
  /** Every request fails as if the network were gone. */
  offline = false
  /** Files of received messages that can be forwarded, by id; others come out as a PDF named after the id. */
  readonly received = new Map<string, { filename: string; contentType: string; inline: boolean; contentId: string | null }>()
  /** The scan result a file with this name gets; clean otherwise, as a quick scan would leave it. */
  readonly scanOf = new Map<string, ScanStatus>()
  private nextFile = 1

  async create(id: string, request: DraftRequest): Promise<SyncedDraft> {
    this.calls.push(`create ${id}`)
    await this.during?.()
    this.reachable()
    if (this.drafts.has(id)) throw new ApiFailure(409)
    return this.store(id, request, 1, [])
  }

  async update(id: string, request: DraftRequest, expectedVersion: number): Promise<SyncedDraft> {
    this.calls.push(`update ${id} @${expectedVersion}`)
    await this.during?.()
    this.reachable()
    const current = this.drafts.get(id)
    if (current === undefined) throw new ApiFailure(404)
    if (current.version !== expectedVersion) throw new ApiFailure(409)
    return this.store(id, request, current.version + 1, current.attachments)
  }

  async get(id: string): Promise<SyncedDraft & { attachments: DraftAttachmentLink[] }> {
    this.reachable()
    const current = this.drafts.get(id)
    if (current === undefined) throw new ApiFailure(404)
    return { ...current, attachments: current.attachments.map((attachment) => withAccess(attachment)) }
  }

  async remove(id: string): Promise<void> {
    this.calls.push(`remove ${id}`)
    this.reachable()
    if (!this.drafts.delete(id)) throw new ApiFailure(404)
  }

  async attach(id: string, file: AttachmentFile): Promise<DraftAttachmentLink> {
    this.calls.push(`attach ${id} ${file.name}`)
    return this.addFile(id, file.name, file.type, file.size)
  }

  async forward(id: string, attachmentId: string): Promise<DraftAttachmentLink> {
    this.calls.push(`forward ${id} ${attachmentId}`)
    const current = this.drafts.get(id)
    const existing = current?.attachments.find((attachment) => attachment.id === `forwarded-${attachmentId}`)
    if (current !== undefined && existing !== undefined) {
      return { ...withAccess(existing), draftVersion: current.version }
    }
    const source = this.received.get(attachmentId) ?? { filename: `${attachmentId}.pdf`, contentType: 'application/pdf', inline: false, contentId: null }
    return this.addFile(id, source.filename, source.contentType, 100, `forwarded-${attachmentId}`, source.inline, source.contentId)
  }

  async unscannedLink(attachmentId: string): Promise<{ url: string }> {
    this.calls.push(`unscanned-link ${attachmentId}`)
    const attachment = [...this.drafts.values()].flatMap((draft) => draft.attachments).find((file) => file.id === attachmentId)
    if (attachment === undefined) throw new ApiFailure(404)
    if (attachment.scanStatus !== 'failed') throw new ApiFailure(attachment.scanStatus === 'pending' ? 409 : 403)
    return { url: `https://api.example.test/content/draft-attachments/${attachmentId}?unscanned=1&signed` }
  }

  async detach(id: string, attachmentId: string): Promise<{ draftVersion: number }> {
    this.calls.push(`detach ${id} ${attachmentId}`)
    this.reachable()
    const current = this.drafts.get(id)
    if (current === undefined || !current.attachments.some((attachment) => attachment.id === attachmentId)) throw new ApiFailure(404)
    const version = current.version + 1
    this.drafts.set(id, { ...current, attachments: current.attachments.filter((attachment) => attachment.id !== attachmentId), version })
    return { draftVersion: version }
  }

  private async addFile(id: string, filename: string, contentType: string, size: number, fileId = `file-${this.nextFile++}`, inline = false, contentId: string | null = null): Promise<DraftAttachmentLink> {
    await this.duringFile?.()
    this.reachable()
    const current = this.drafts.get(id)
    if (current === undefined) throw new ApiFailure(404)
    const attachment: DraftAttachment = { id: fileId, filename, contentType, size, scanStatus: this.scanOf.get(filename) ?? 'clean', inline, contentId }
    // Files are part of the draft: a change to them raises its version, as on the server.
    const version = current.version + 1
    this.drafts.set(id, { ...current, attachments: [...current.attachments, attachment], version })
    return { ...withAccess(attachment), draftVersion: version }
  }

  private reachable(): void {
    if (this.offline) throw new Error('Network request failed')
  }

  private store(id: string, request: DraftRequest, version: number, attachments: SyncedDraft['attachments']): SyncedDraft {
    const saved: SyncedDraft = {
      id,
      mailAccountId: request.mailAccountId,
      kind: request.kind,
      to: request.to,
      cc: request.cc,
      bcc: request.bcc,
      subject: request.subject,
      html: request.html,
      signatureId: request.signatureId,
      quoteHtml: request.quoteHtml,
      inReplyToMessageId: request.inReplyToMessageId,
      version,
      updatedAt: '2026-10-19T08:00:00Z',
      attachments,
    }
    this.drafts.set(id, saved)
    return saved
  }
}

/** Links as the server gives them: only for clean files. */
function withAccess(attachment: DraftAttachment): DraftAttachmentLink {
  const access = ({ clean: 'allowed', pending: 'scanning', infected: 'blocked', failed: 'confirm' } as const)[attachment.scanStatus]
  return { ...attachment, access, url: access === 'allowed' ? `https://api.example.test/content/draft-attachments/${attachment.id}?signed` : null }
}
