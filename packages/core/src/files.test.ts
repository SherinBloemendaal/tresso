import { describe, expect, it } from 'vitest'
import { fileSize, SCAN_TEXT } from './files'

describe('fileSize', () => {
  it('says how large a file is in the nearest unit', () => {
    expect(fileSize(512)).toBe('512 B')
    expect(fileSize(1536)).toBe('1.5 KB')
    expect(fileSize(240 * 1024)).toBe('240 KB')
    expect(fileSize(18 * 1024 * 1024)).toBe('18 MB')
    expect(fileSize(3 * 1024 ** 4)).toBe('3072 GB')
  })

  it('words every scan outcome', () => {
    expect(SCAN_TEXT.clean).toBe('scanned, no threats')
    expect(Object.keys(SCAN_TEXT).sort()).toEqual(['clean', 'failed', 'infected', 'pending'])
  })
})
