/** One server-sent event: its name and its data, parsed as JSON (null if it isn't). */
export interface SseEvent {
  event: string
  data: unknown
}

/**
 * Adds a chunk of an event stream to what's left over from the previous ones and
 * returns the events it completes. An event ends at a blank line, so one may be
 * split across chunks (`rest` keeps the unfinished part), and one chunk may hold several.
 */
export function parseSseChunk(rest: string, chunk: string): { events: SseEvent[]; rest: string } {
  const blocks = (rest + chunk).replace(/\r\n/g, '\n').split('\n\n')
  // The last piece has no blank line after it yet: keep it for the next chunk.
  const unfinished = blocks.pop() ?? ''
  const events: SseEvent[] = []

  for (const block of blocks) {
    let event = 'message'
    const data: string[] = []
    for (const line of block.split('\n')) {
      if (line.startsWith('event:')) event = line.slice(6).trim()
      else if (line.startsWith('data:')) data.push(line.slice(5).replace(/^ /, ''))
    }
    if (data.length === 0) continue
    events.push({ event, data: parseJson(data.join('\n')) })
  }

  return { events, rest: unfinished }
}

function parseJson(text: string): unknown {
  try {
    return JSON.parse(text)
  } catch {
    return null
  }
}
