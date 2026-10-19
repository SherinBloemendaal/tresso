import { createContext, useContext, useEffect, useMemo, useState, useSyncExternalStore, type ReactNode } from 'react'
import { usePathname, useRouter } from 'expo-router'
import { Composer, type ComposerState, type SyncedMailAccount, type SyncedSignature } from '@tresso/core'
import { useNotices } from '@/components/Notices'
import { useMail } from '@/mail/MailProvider'

interface ComposerContext {
  composer: Composer
  accounts: SyncedMailAccount[]
  signatures: SyncedSignature[]
}

const Context = createContext<ComposerContext | null>(null)

interface Loaded {
  accounts: SyncedMailAccount[]
  signatures: SyncedSignature[]
}

type Mail = ReturnType<typeof useMail>
type Notices = ReturnType<typeof useNotices>

/** What the composer reads when it is called, updated after every render (it lives as long as the provider). */
class Current {
  constructor(
    public mail: Mail,
    public notices: Notices,
    public loaded: Loaded,
    public onCompose: boolean,
  ) {}

  update(mail: Mail, notices: Notices, loaded: Loaded, onCompose: boolean): void {
    this.mail = mail
    this.notices = notices
    this.loaded = loaded
    this.onCompose = onCompose
  }
}

function createComposer(current: Current, drafts: Mail['drafts']): Composer {
  return new Composer({
    store: () => current.mail.store(),
    drafts,
    accounts: () => current.loaded.accounts,
    signatures: () => current.loaded.signatures,
    defaultAccount: () => current.loaded.accounts[0]?.id ?? null,
    body: (messageId) => current.mail.body(messageId, false),
    send: (request) => current.mail.send(request),
    cancel: (outgoingId) => current.mail.cancelOutgoing(outgoingId),
    notify: (notice, ms) =>
      current.notices.notify(notice.action === undefined ? { text: notice.text, tone: notice.tone } : { text: notice.text, tone: notice.tone, action: notice.action }, ms),
    explain: (error) => ({ message: error instanceof Error ? error.message : String(error), violations: {} }),
  })
}

/**
 * Writing mail on the phone, with the same Composer the web client uses (`packages/core/src/composer.ts`): drafts are
 * saved on the device and uploaded, a sent message waits a few seconds so it can be undone, and what became of it is
 * announced once. Whenever a draft opens, the compose screen shows it.
 */
export function ComposerProvider({ children }: { children: ReactNode }) {
  const mail = useMail()
  const notices = useNotices()
  const router = useRouter()
  const pathname = usePathname()
  const [loaded, setLoaded] = useState<Loaded>({ accounts: [], signatures: [] })

  const [current] = useState(() => new Current(mail, notices, loaded, pathname === '/compose'))
  useEffect(() => current.update(mail, notices, loaded, pathname === '/compose'))
  const [composer] = useState(() => createComposer(current, mail.drafts))
  useEffect(() => () => composer.dispose(), [composer])

  const { mailAccounts, signatures: loadSignatures, version, ready } = mail
  useEffect(() => {
    let current = true
    void Promise.all([mailAccounts(), loadSignatures()]).then(([accounts, signatures]) => {
      if (current) {
        setLoaded({ accounts, signatures })
      }
    })
    return () => {
      current = false
    }
  }, [mailAccounts, loadSignatures, version])

  useEffect(() => {
    let opened = composer.state.revision
    let undoable = composer.state.undoable
    return composer.subscribe(() => {
      const { draft, revision, undoable: next } = composer.state
      // A new message, a reply, an undo or "edit and send again" opened a draft: show it.
      if (revision !== opened) {
        opened = revision
        if (draft !== null && !current.onCompose) {
          router.push('/compose')
        }
      }
      // A message just sent waits a few seconds; until then it can be stopped and edited again.
      if (next !== null && next !== undoable) {
        current.notices.notify(
          { text: 'Sending…', tone: 'info', action: { label: 'Undo', accessibilityLabel: 'Undo sending', run: () => void composer.undo() } },
          Math.max(0, next.until - Date.now()),
        )
      }
      undoable = next
    })
  }, [composer, current, router])

  // Every change to the copy may bring what became of a sent message.
  useEffect(() => {
    if (ready) {
      void composer.followUp()
    }
  }, [composer, ready, version])

  const value = useMemo(() => ({ composer, accounts: loaded.accounts, signatures: loaded.signatures }), [composer, loaded])
  return <Context.Provider value={value}>{children}</Context.Provider>
}

/** The composer, what it is writing now, and the accounts and signatures to choose from. */
export function useComposer(): ComposerContext & { state: ComposerState } {
  const context = useContext(Context)
  if (context === null) {
    throw new Error('useComposer() is used outside <ComposerProvider>.')
  }
  const { composer } = context
  const state = useSyncExternalStore(
    (listener) => composer.subscribe(listener),
    () => composer.state,
  )
  return { ...context, state }
}
