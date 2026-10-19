import { describe, expect, it } from 'vitest'
import { photoName, photoType } from '../photoName'

describe('photo names', () => {
  it('gives a photo the extension of what it is now', () => {
    expect(photoName('IMG_0001.HEIC', 'image/jpeg')).toBe('IMG_0001.jpg')
    expect(photoName('Photo 1', 'image/png')).toBe('Photo 1.png')
    expect(photoName('beach.JPEG', 'image/jpeg')).toBe('beach.JPEG')
    expect(photoName('scan.pdf', 'application/pdf')).toBe('scan.pdf')
  })

  it('knows the type of a photo by its extension', () => {
    expect(photoType('jpg')).toBe('image/jpeg')
    expect(photoType('heic')).toBe('image/heic')
    expect(photoType('txt')).toBeUndefined()
    expect(photoType(undefined)).toBeUndefined()
  })
})
