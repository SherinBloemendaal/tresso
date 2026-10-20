import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, render } from '@testing-library/react'
import { HandOverFlow, type HandedOver, type SyncedHandOver, type SyncedMessage } from '@tresso/core'
import { HandOverProvider, useHandOvers } from '@/handOver/HandOverProvider'

const { router, notices, mail } = vi.hoisted(() => ({
  router: { push: vi.fn(), back: vi.fn() },
  notices: { notify: vi.fn(), setBottomInset: vi.fn() },
  mail: { overdue: [], clearOverdue: () => {}, handOverApi: {}, cancelOutgoing: async () => {}, outgoing: async () => null, syncNow: async () => {} },
}))
vi.mock('expo-router', () => ({ useRouter: () => router }))
vi.mock('@/components/Notices', () => ({ useNotices: () => notices }))
vi.mock('@/mail/MailProvider', () => ({ useMail: () => mail }))
vi.mock('@/compose/ComposerProvider', () => ({ useComposer: () => ({ handOverComposer: {}, accounts: [], signatures: [] }) }))

const original = { id: 'm1', threadId: 't1' } as SyncedMessage
const handOver = { id: 'h1', delegate: { address: 'lisa@company.example', name: 'Lisa' } } as SyncedHandOver
const handed: HandedOver = { handOver, forwardId: 'f1', draftId: 'd1', until: Date.now() + 5000 }
const input = { delegate: handOver.delegate, note: '', dueAt: new Date('2026-10-23T15:00:00Z'), dueLabel: 'Friday' }

function mount() {
  let handOvers!: ReturnType<typeof useHandOvers>
  function Probe() {
    handOvers = useHandOvers()
    return null
  }
  render(
    <HandOverProvider>
      <Probe />
    </HandOverProvider>,
  )
  return () => handOvers
}

describe('HandOverProvider for triage', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    router.push.mockClear()
    notices.notify.mockClear()
  })

  it('opens the hand-over screen for the message triage chose and settles with the hand-over, giving no notice itself', async () => {
    vi.spyOn(HandOverFlow.prototype, 'handOver').mockResolvedValue(handed)
    const handOvers = mount()

    let waiting!: Promise<HandedOver | null>
    act(() => {
      waiting = handOvers().handOverAndWait('t1', 'm1')
    })
    expect(router.push).toHaveBeenCalledWith({ pathname: '/hand-over', params: { threadId: 't1', messageId: 'm1' } })
    await act(() => handOvers().handOver(original, input))

    expect(await waiting).toBe(handed)
    expect(notices.notify).not.toHaveBeenCalled()
  })

  it('settles with nothing when the hand-over screen closes without a hand-over', async () => {
    const handOvers = mount()

    let waiting!: Promise<HandedOver | null>
    act(() => {
      waiting = handOvers().handOverAndWait('t1', 'm1')
    })
    act(() => handOvers().closed('t1'))

    expect(await waiting).toBeNull()
  })

  it('gives its own notice with an undo when nobody waits', async () => {
    vi.spyOn(HandOverFlow.prototype, 'handOver').mockResolvedValue(handed)
    const handOvers = mount()

    await act(() => handOvers().handOver(original, input))

    expect(notices.notify).toHaveBeenCalledWith(expect.objectContaining({ text: 'Handed over to Lisa.' }), expect.any(Number))
  })
})
