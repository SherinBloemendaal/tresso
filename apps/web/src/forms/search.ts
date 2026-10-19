import { z } from 'zod'

/** Mirrors the API's limit on the q parameter of /api/v1/search. */
export const SEARCH_MAX_LENGTH = 1000

export const searchSchema = z.object({
  q: z.string().trim().min(1, 'Type something to search for.').max(SEARCH_MAX_LENGTH, `Use at most ${SEARCH_MAX_LENGTH} characters.`),
})

export type SearchValues = z.infer<typeof searchSchema>
