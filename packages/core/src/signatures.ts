/** Wire format of /api/v1/signatures. */

export interface Signature {
  id: string
  name: string
  /** Sanitized by the API on save. */
  html: string
  /** Mail accounts that insert this signature by default. */
  defaultFor: string[]
  updatedAt: string
}

export interface NewSignature {
  name: string
  html: string
  defaultFor?: string[]
}

/**
 * JSON merge patch: omitted fields stay as they are. `defaultFor` is the complete list; accounts left out that used
 * this signature end up without a default.
 */
export interface SignatureUpdate {
  name?: string
  html?: string
  defaultFor?: string[]
}
