import { describe, expect, it } from 'vitest'
import { MAX_BODY_HEIGHT, nextBodyHeight } from '../bodyHeight'

function settle(reports: number[], start = 80): number {
  const growths: number[] = []
  let height = start
  for (const measured of reports) {
    height = nextBodyHeight(height, measured, growths)
  }
  return height
}

describe('nextBodyHeight', () => {
  it('follows content as it loads, and ignores a pixel of noise', () => {
    expect(settle([400, 900, 1300, 1301])).toBe(1300)
  })

  it('stops a view that grows with its own height', () => {
    // A 100vh hero plus a 120px footer: every report is the view height plus 120.
    const growths: number[] = []
    let height = 80
    for (let i = 0; i < 50; i++) {
      height = nextBodyHeight(height, height + 120, growths)
    }
    expect(height).toBeLessThan(600)
  })

  it('keeps content that loads in three equal steps', () => {
    expect(settle([280, 480, 680])).toBe(680)
  })

  it('caps very long mail', () => {
    expect(settle([5_000, 50_000])).toBe(MAX_BODY_HEIGHT)
  })
})
