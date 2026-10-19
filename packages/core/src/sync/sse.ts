/**
 * Incremental parser for text/event-stream (WHATWG HTML, "Server-sent events"). Feed it decoded text as it arrives;
 * chunks may split lines, and a CR at the end of one chunk may be followed by the LF of the next.
 */

export interface ServerSentEvent {
  type: string
  data: string
  id: string | null
}

export class SseParser {
  private buffer = ''
  private pendingCr = false
  private data: string[] = []
  private type = ''
  private lastId: string | null = null
  private hasData = false

  /** Returns the events completed by this chunk. */
  push(chunk: string): ServerSentEvent[] {
    const events: ServerSentEvent[] = []
    let text = chunk
    if (this.pendingCr && text.startsWith('\n')) {
      text = text.slice(1)
    }
    this.pendingCr = false

    this.buffer += text
    let start = 0
    for (let i = 0; i < this.buffer.length; i++) {
      const char = this.buffer[i]
      if (char !== '\n' && char !== '\r') {
        continue
      }
      const line = this.buffer.slice(start, i)
      if (char === '\r') {
        if (i + 1 === this.buffer.length) {
          this.pendingCr = true
        } else if (this.buffer[i + 1] === '\n') {
          i++
        }
      }
      start = i + 1
      const event = this.line(line)
      if (event !== null) {
        events.push(event)
      }
    }
    this.buffer = this.buffer.slice(start)

    return events
  }

  private line(line: string): ServerSentEvent | null {
    if (line === '') {
      return this.dispatch()
    }
    if (line.startsWith(':')) {
      return null
    }
    const colon = line.indexOf(':')
    const field = colon === -1 ? line : line.slice(0, colon)
    let value = colon === -1 ? '' : line.slice(colon + 1)
    if (value.startsWith(' ')) {
      value = value.slice(1)
    }

    switch (field) {
      case 'event':
        this.type = value
        break
      case 'data':
        this.data.push(value)
        this.hasData = true
        break
      case 'id':
        if (!value.includes('\0')) {
          this.lastId = value
        }
        break
    }

    return null
  }

  private dispatch(): ServerSentEvent | null {
    const event = this.hasData ? { type: this.type === '' ? 'message' : this.type, data: this.data.join('\n'), id: this.lastId } : null
    this.data = []
    this.type = ''
    this.hasData = false

    return event
  }
}
