import { afterEach } from 'vitest'
import { cleanup } from '@testing-library/react'

// Screens rendered by one test are gone before the next.
afterEach(() => {
  cleanup()
})
