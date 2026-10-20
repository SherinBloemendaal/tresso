import { describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import type { SyncedMessage } from '@tresso/core'
import HandOverScreen from '@/app/hand-over'

const { params, handOvers, mail } = vi.hoisted(() => {
  const message = (id: string, subject: string, from: string, at: string) =>
    ({ id, threadId: 't1', subject, from: { address: from, name: null }, receivedAt: at }) as unknown as SyncedMessage
  return {
    params: { threadId: 't1', messageId: undefined as string | undefined },
    handOvers: { handOver: vi.fn(), closed: vi.fn() },
    mail: {
      thread: async () => ({
        thread: { id: 't1' },
        messages: [message('m1', 'Budget', 'anna@partner.example', '2026-10-19T08:00:00Z'), message('m2', 'Re: Budget', 'lisa@partner.example', '2026-10-19T09:00:00Z')],
      }),
      mailAccounts: async () => [{ emailAddress: 'kim@company.example' }],
      suggestContacts: async () => [],
    },
  }
})
vi.mock('expo-router', () => ({ Stack: { Screen: () => null }, useLocalSearchParams: () => params, useRouter: () => ({ back: vi.fn() }) }))
vi.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, bottom: 0, left: 0, right: 0 }) }))
vi.mock('@/handOver/HandOverProvider', () => ({ useHandOvers: () => handOvers }))
vi.mock('@/mail/MailProvider', () => ({ useMail: () => mail }))
vi.mock('@/compose/RecipientField', () => ({ RecipientField: () => null }))

describe('the hand-over screen', () => {
  it('hands over the message triage chose, not the newest', async () => {
    params.messageId = 'm1'
    render(<HandOverScreen />)

    await waitFor(() => expect(screen.getByText('Budget, from anna@partner.example')).toBeTruthy())
  })

  it('hands over the newest message from someone else when none was chosen', async () => {
    params.messageId = undefined
    render(<HandOverScreen />)

    await waitFor(() => expect(screen.getByText('Re: Budget, from lisa@partner.example')).toBeTruthy())
  })

  it('tells the hand-overs when it closes, so a wait for it settles', () => {
    handOvers.closed.mockClear()
    const { unmount } = render(<HandOverScreen />)
    expect(handOvers.closed).not.toHaveBeenCalled()

    unmount()

    expect(handOvers.closed).toHaveBeenCalledWith('t1')
  })
})
