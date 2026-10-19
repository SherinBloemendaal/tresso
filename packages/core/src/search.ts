/** Wire format of GET /api/v1/search, and helpers for the search box syntax. */

/** A piece of result text; `match` marks what matched the query. Render as text, never as HTML. */
export interface HighlightSegment {
  text: string
  match: boolean
}

export interface SearchAttachment {
  id: string
  filename: HighlightSegment[]
  contentType: string
  size: number
  /** Whether the filename matched the query. */
  matched: boolean
}

export interface SearchHit {
  id: string
  threadId: string
  accountId: string
  folderIds: string[]
  fromAddress: string
  fromName: string | null
  receivedAt: string
  seen: boolean
  flagged: boolean
  hasAttachments: boolean
  subject: HighlightSegment[]
  /** The sender's name, or the address without one. */
  from: HighlightSegment[]
  /** The passage of the text around the first match, or the snippet. */
  preview: HighlightSegment[]
  attachments: SearchAttachment[]
}

export interface SearchResults {
  query: string
  page: number
  perPage: number
  /** Exact up to 1000. */
  totalHits: number
  totalPages: number
  messages: SearchHit[]
}

/** The filters the API understands; see the API documentation of /api/v1/search. */
export const SEARCH_FILTERS = ['from', 'to', 'in', 'has', 'after', 'before'] as const
export type SearchFilterName = (typeof SEARCH_FILTERS)[number]

export interface SearchFilterToken {
  name: SearchFilterName
  value: string
  /** The token as typed, e.g. from:"Anna de Vries". */
  raw: string
}

const TOKEN = /(?:[^\s"]+|"[^"]*"?)+/g
const FILTER = /^([a-z]+):(.+)$/i

/** The filters in a query, in the order typed. Words and unknown name:value pairs are not filters. */
export function searchFilters(query: string): SearchFilterToken[] {
  const tokens: SearchFilterToken[] = []
  for (const raw of query.match(TOKEN) ?? []) {
    const match = FILTER.exec(raw)
    const name = match?.[1]?.toLowerCase()
    const value = match?.[2]?.replace(/"/g, '').trim()
    if (name && value && (SEARCH_FILTERS as readonly string[]).includes(name)) {
      tokens.push({ name: name as SearchFilterName, value, raw })
    }
  }

  return tokens
}

/**
 * The query with every filter of this name replaced by one with the given value, or removed when the value is null.
 * The new filter goes at the end; values with spaces are quoted.
 */
export function withSearchFilter(query: string, name: SearchFilterName, value: string | null): string {
  const kept = (query.match(TOKEN) ?? []).filter((raw) => {
    const match = FILTER.exec(raw)

    return match?.[1]?.toLowerCase() !== name
  })
  if (value !== null && value.trim() !== '') {
    const clean = value.replace(/"/g, '').trim()
    kept.push(`${name}:${/\s/.test(clean) ? `"${clean}"` : clean}`)
  }

  return kept.join(' ')
}

/** The day `days` days before `now`, as the date after: and before: take. */
export function searchDay(now: Date, days = 0): string {
  const day = new Date(now.getFullYear(), now.getMonth(), now.getDate() - days)
  const pad = (n: number) => String(n).padStart(2, '0')

  return `${day.getFullYear()}-${pad(day.getMonth() + 1)}-${pad(day.getDate())}`
}
