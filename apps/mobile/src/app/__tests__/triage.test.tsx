import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import type { SyncedMessage, TriageProgress } from '@tresso/core'
import TriageScreen from '@/app/triage'

type Triage = {
  current: string | null
  conversation: { thread: { id: string; subject: string; smartInboxId: string | null; classificationReason: string | null }; messages: SyncedMessage[] } | null
  progress: TriageProgress | null
  canUndo: boolean
  saving: boolean
  more: boolean
  decide: ReturnType<typeof vi.fn>
  skip: ReturnType<typeof vi.fn>
  undo: ReturnType<typeof vi.fn>
  nextRound: ReturnType<typeof vi.fn>
}

const { triage, router, mail } = vi.hoisted(() => ({
  triage: {} as Triage,
  router: { back: vi.fn(), replace: vi.fn(), canGoBack: () => true },
  mail: { smartInboxes: async () => [{ id: 'invoices', name: 'Invoices' }], version: 0 },
}))
vi.mock('@/mail/useTriage', () => ({ useTriage: () => triage }))
vi.mock('expo-router', () => ({ Stack: { Screen: () => null }, useFocusEffect: () => {}, useRouter: () => router }))
vi.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, bottom: 0, left: 0, right: 0 }) }))
vi.mock('@/components/Notices', () => ({ useNotices: () => ({ setBottomInset: () => {}, notify: () => {} }) }))
vi.mock('@/mail/MailProvider', () => ({ useMail: () => mail }))
vi.mock('@/components/MessageBodyView', async () => {
  const { Text } = await import('react-native')
  return { MessageBodyView: ({ messageId }: { messageId: string }) => <Text>{`Body of ${messageId}`}</Text> }
})

function message(id: string, from: string, snippet: string): SyncedMessage {
  return { id, threadId: 't1', subject: 'Budget', from: { address: from, name: null }, snippet, receivedAt: '2026-10-19T08:00:00Z' } as unknown as SyncedMessage
}

const progress: TriageProgress = { shown: 1, total: 2, counts: { do: 0, delegate: 0, know: 0 } }

describe('the triage screen', () => {
  beforeEach(() => {
    Object.assign(triage, {
      current: 't1',
      conversation: { thread: { id: 't1', subject: 'Budget', smartInboxId: 'invoices', classificationReason: null }, messages: [message('m1', 'anna@partner.example', 'Can you look?'), message('m2', 'lisa@partner.example', 'Any news?')] },
      progress,
      canUndo: false,
      saving: false,
      more: false,
      decide: vi.fn(),
      skip: vi.fn(),
      undo: vi.fn(),
      nextRound: vi.fn(),
    })
  })

  it('shows the whole conversation: the newest message open, earlier ones folded until tapped', () => {
    render(<TriageScreen />)

    expect(screen.getByText('Body of m2')).toBeTruthy()
    expect(screen.queryByText('Body of m1')).toBeNull()
    expect(screen.getByText('One earlier message, tap to read it.')).toBeTruthy()

    fireEvent.click(screen.getByText('Can you look?'))

    expect(screen.getByText('Body of m1')).toBeTruthy()
  })

  it('decides, skips and undoes with its buttons', () => {
    triage.canUndo = true
    render(<TriageScreen />)

    fireEvent.click(screen.getByText('Do it myself'))
    fireEvent.click(screen.getByText('Hand over'))
    fireEvent.click(screen.getByText('Just know'))
    fireEvent.click(screen.getByText('Skip'))
    fireEvent.click(screen.getByText('Undo'))

    expect(triage.decide.mock.calls).toEqual([['do'], ['delegate'], ['know']])
    expect(triage.skip).toHaveBeenCalledOnce()
    expect(triage.undo).toHaveBeenCalledOnce()
  })

  it('offers no undo when there is nothing to undo', () => {
    render(<TriageScreen />)

    fireEvent.click(screen.getByText('Undo'))

    expect(triage.undo).not.toHaveBeenCalled()
  })

  it('offers no decision while the next email still loads', () => {
    triage.conversation = null
    render(<TriageScreen />)

    expect(screen.getByLabelText('Loading the next email')).toBeTruthy()
    expect(screen.queryByText('Do it myself')).toBeNull()
  })

  it('ends with a summary and offers the next round when more mail waits', () => {
    Object.assign(triage, { current: null, conversation: null, more: true, progress: { shown: 2, total: 2, counts: { do: 1, delegate: 0, know: 1 } } })
    render(<TriageScreen />)

    expect(screen.getByText('Round done')).toBeTruthy()
    expect(screen.getByText('1 to do, 0 handed over, 1 noted. More mail waits for the next round.')).toBeTruthy()
    fireEvent.click(screen.getByText('Start the next round'))
    expect(triage.nextRound).toHaveBeenCalledOnce()
  })

  it('says when nothing waits', () => {
    Object.assign(triage, { current: null, conversation: null, progress: { shown: 0, total: 0, counts: { do: 0, delegate: 0, know: 0 } } })
    render(<TriageScreen />)

    expect(screen.getByText('Nothing to triage')).toBeTruthy()
  })
})
