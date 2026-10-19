import { z } from 'zod'

/** Mirrors the API's limit on signature HTML (SignatureHtmlSanitizer::MAX_INPUT_BYTES). */
export const SIGNATURE_MAX_BYTES = 200_000

export const signatureSchema = z.object({
  name: z.string().trim().min(1, 'Give the signature a name.').max(120, 'Use at most 120 characters.'),
  html: z.string().refine((html) => new TextEncoder().encode(html).length <= SIGNATURE_MAX_BYTES, `Signatures can be at most ${SIGNATURE_MAX_BYTES / 1000} KB.`),
  defaultFor: z.array(z.string()),
})

export type SignatureValues = z.infer<typeof signatureSchema>
