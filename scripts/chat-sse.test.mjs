import assert from 'node:assert/strict'
import test from 'node:test'

import { SseEventDecoder } from '../resources/js/src/utils/sseEventDecoder.js'

const encoder = new TextEncoder()

function feedByBytes(text, splitAt = []) {
  const decoder = new SseEventDecoder()
  const bytes = encoder.encode(text)
  const events = []
  let start = 0

  for (const end of [...splitAt, bytes.length]) {
    events.push(...decoder.feed(bytes.slice(start, end)))
    start = end
  }
  events.push(...decoder.finish())

  return events
}

test('preserves Unicode line and paragraph separators across byte chunks', () => {
  const payload = JSON.stringify({ type: 'content', content: 'alpha\u0085beta\u2028gamma\u2029delta' })
  const bytes = encoder.encode(`data: ${payload}\n\n`)
  const separatorOffset = bytes.indexOf(0xe2)
  const events = feedByBytes(`data: ${payload}\n\n`, [separatorOffset + 1, separatorOffset + 2])

  assert.equal(events.length, 1)
  assert.deepEqual(JSON.parse(events[0].data), {
    type: 'content',
    content: 'alpha\u0085beta\u2028gamma\u2029delta',
  })
})

test('accepts CR, LF, and CRLF boundaries without inventing extra events', () => {
  const events = feedByBytes(
    'data:{"type":"start"}\r\rdata: {"type":"content","content":"x"}\r\n\r\ndata: {"type":"done"}\n\n',
    [22, 23, 61],
  )

  assert.deepEqual(events.map(event => JSON.parse(event.data).type), ['start', 'content', 'done'])
})

test('joins multi-line data, ignores comments, and retains event identity', () => {
  const events = feedByBytes(
    ': heartbeat\nid: 42\nevent: update\ndata: {"type":"content",\ndata: "content":"hello"}\n\n',
  )

  assert.equal(events.length, 1)
  assert.equal(events[0].event, 'update')
  assert.equal(events[0].id, '42')
  assert.deepEqual(JSON.parse(events[0].data), { type: 'content', content: 'hello' })
})

test('flushes a final frame and trailing multi-byte character at EOF', () => {
  const text = 'data: {"type":"content","content":"done ✓"}\r'
  const bytes = encoder.encode(text)
  const checkOffset = bytes.lastIndexOf(0xe2)
  const events = feedByBytes(text, [checkOffset + 1, checkOffset + 2])

  assert.equal(events.length, 1)
  assert.equal(JSON.parse(events[0].data).content, 'done ✓')
})
