import { describe, expect, it } from 'vitest'
import { clickedPath } from '../notifier'

describe('clickedPath', () => {
  it('opens the path a desktop notification carries, only within the app', () => {
    expect(clickedPath({ path: '/thread/t1' })).toBe('/thread/t1')
    expect(clickedPath({ path: null })).toBeNull()
    expect(clickedPath({ path: 'https://elsewhere.example/' })).toBeNull()
    expect(clickedPath(undefined)).toBeNull()
  })
})
