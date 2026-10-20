import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useSelectionStore } from '../selection'

describe('selection', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('keeps its Set when removing or adding changes nothing, so its watchers do not run again', () => {
    const selection = useSelectionStore()
    selection.set(['t1', 't2'])
    const before = selection.threadIds

    selection.remove(['t9'])
    selection.remove([])
    selection.add(['t1'])

    expect(selection.threadIds).toBe(before)
  })

  it('takes a new Set when it changes', () => {
    const selection = useSelectionStore()
    selection.set(['t1', 't2'])
    const before = selection.threadIds

    selection.remove(['t2'])
    expect([...selection.threadIds]).toEqual(['t1'])
    expect(selection.threadIds).not.toBe(before)

    selection.add(['t3'])
    expect([...selection.threadIds]).toEqual(['t1', 't3'])
  })
})
