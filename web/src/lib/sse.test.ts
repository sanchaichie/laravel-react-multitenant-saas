import { describe, expect, it } from 'vitest'
import { parseSseChunk } from './sse'

describe('parseSseChunk', () => {
  it('reads several events from one chunk', () => {
    const { events, rest } = parseSseChunk(
      '',
      'event: delta\ndata: {"text":"Hel"}\n\nevent: delta\ndata: {"text":"lo"}\n\nevent: done\ndata: {"model":"m"}\n\n',
    )

    expect(events).toEqual([
      { event: 'delta', data: { text: 'Hel' } },
      { event: 'delta', data: { text: 'lo' } },
      { event: 'done', data: { model: 'm' } },
    ])
    expect(rest).toBe('')
  })

  it('keeps an event split across chunks until it ends', () => {
    const first = parseSseChunk('', 'event: delta\ndata: {"te')
    expect(first.events).toEqual([])

    const second = parseSseChunk(first.rest, 'xt":"Hi"}\n')
    expect(second.events).toEqual([])

    const third = parseSseChunk(second.rest, '\nevent: done\n')
    expect(third.events).toEqual([{ event: 'delta', data: { text: 'Hi' } }])
    expect(third.rest).toBe('event: done\n')
  })

  it('accepts CRLF line endings, and data that is not JSON', () => {
    const { events } = parseSseChunk('', 'event: delta\r\ndata: {"text":"A"}\r\n\r\nevent: ping\r\ndata: hello\r\n\r\n')

    expect(events).toEqual([
      { event: 'delta', data: { text: 'A' } },
      { event: 'ping', data: null },
    ])
  })

  it('skips comments and events without data', () => {
    expect(parseSseChunk('', ': keep-alive\n\nevent: done\n\n').events).toEqual([])
  })
})
