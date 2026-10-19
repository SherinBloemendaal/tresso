import type { SyncedFolder } from '@tresso/core'
import type { AppliedAction } from '@/mail/db'

/** Moves that put messages back: from the folder an action took them to, into the one it took them from. */
export interface UndoMove {
  fromFolderId: string
  folderId: string
  messageIds: string[]
}

export interface UndoPlan {
  moves: UndoMove[]
  /** Whether the moves put every changed message back exactly; when not, no undo is offered. */
  exact: boolean
}

/**
 * How to undo an archive, trash or move. Only messages the action changed are put back, each from where it went into
 * the one folder it came from. A message that came from several folders (Gmail's All Mail aside, which holds every
 * message anyway) cannot be put back exactly, because a move takes every copy along; neither can mail archived into
 * Gmail's All Mail, where moving out would delete it from Gmail. Then the plan is not exact.
 */
export function undoPlan(applied: AppliedAction, action: 'archive' | 'trash' | 'move', folders: SyncedFolder[]): UndoPlan {
  const roles = new Map(folders.map((folder) => [folder.id, folder.role]))
  const groups = new Map<string, UndoMove>()
  let exact = true

  applied.before.forEach((before, index) => {
    const after = applied.after[index]
    if (after === undefined || sameFolders(before.folderIds, after.folderIds)) {
      return
    }
    const original = before.folderIds.filter((id) => roles.get(id) !== 'all')
    let from: string | undefined
    let to: string | undefined
    if (action === 'archive') {
      const left = original.filter((id) => roles.get(id) === 'inbox')
      from = after.folderIds.find((id) => roles.get(id) === 'archive')
      to = left.length === 1 ? left[0] : undefined
    } else {
      from = after.folderIds.find((id) => !before.folderIds.includes(id)) ?? after.folderIds[0]
      to = original.length === 1 ? original[0] : undefined
    }
    if (from === undefined || to === undefined || roles.get(to) === 'drafts' || roles.get(to) === 'all') {
      exact = false
      return
    }
    const key = `${from}>${to}`
    const group = groups.get(key) ?? { fromFolderId: from, folderId: to, messageIds: [] }
    group.messageIds.push(before.id)
    groups.set(key, group)
  })

  return { moves: [...groups.values()], exact }
}

function sameFolders(a: string[], b: string[]): boolean {
  return a.length === b.length && a.every((id) => b.includes(id))
}
