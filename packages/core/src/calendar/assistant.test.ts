import { describe, expect, it } from 'vitest'
import { describeSlotEdge, type SlotEdge } from './assistant'

const edge = (reason: SlotEdge['reason'], label = ''): SlotEdge => ({ at: '2027-03-22T09:00:00+00:00', reason, label })

describe('describeSlotEdge', () => {
  it('names the busy time a slot sits next to', () => {
    expect(describeSlotEdge(edge('busy', 'Standup'), 'from')).toBe('after Standup')
    expect(describeSlotEdge(edge('busy', 'Standup'), 'until')).toBe('before Standup')
    expect(describeSlotEdge(edge('busy'), 'until')).toBe('before busy time')
  })

  it('names the buffer and travel time of busy time', () => {
    expect(describeSlotEdge(edge('buffer', 'Lunch'), 'until')).toBe('before the buffer of Lunch')
    expect(describeSlotEdge(edge('travel', 'Client visit'), 'from')).toBe('after travel time of Client visit')
  })

  it('names working hours, focus blocks, notice and the search window', () => {
    expect(describeSlotEdge(edge('working_hours'), 'from')).toBe('working hours start')
    expect(describeSlotEdge(edge('working_hours'), 'until')).toBe('working hours end')
    expect(describeSlotEdge(edge('focus', 'Focus: report'), 'until')).toBe('before Focus: report')
    expect(describeSlotEdge(edge('focus'), 'from')).toBe('after focus time')
    expect(describeSlotEdge(edge('notice'), 'from')).toBe('your minimum notice')
    expect(describeSlotEdge(edge('window'), 'until')).toBe('end of the search')
  })

  it('names a busy attendee', () => {
    expect(describeSlotEdge(edge('attendee', 'sam@example.test'), 'from')).toBe('sam@example.test is busy')
  })
})
