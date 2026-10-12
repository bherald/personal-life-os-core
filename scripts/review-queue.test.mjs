import assert from 'node:assert/strict'
import test from 'node:test'

import {
  nextReviewStreamItem,
  sortReviewQueueItems,
} from '../resources/js/src/utils/reviewQueue.js'

const items = [
  { unified_id: 'review:1' },
  { unified_id: 'review:2' },
  { unified_id: 'review:3' },
]

test('advances to the next item in displayed order', () => {
  assert.equal(nextReviewStreamItem(items, 'review:2')?.unified_id, 'review:3')
})

test('falls back to the previous pending item at the end of a stream', () => {
  assert.equal(nextReviewStreamItem(items, 'review:3')?.unified_id, 'review:2')
})

test('does not invent a stream item for missing or singleton rows', () => {
  assert.equal(nextReviewStreamItem(items, 'review:missing'), null)
  assert.equal(nextReviewStreamItem([{ unified_id: 'review:1' }], 'review:1'), null)
  assert.equal(nextReviewStreamItem(null, 'review:1'), null)
})

test('review priority sorts high priority first and oldest within a priority', () => {
  const queue = [
    { unified_id: 'review:3', priority: 1, created_at: '2026-07-03T00:00:00Z' },
    { unified_id: 'review:2', priority: 3, created_at: '2026-07-02T00:00:00Z' },
    { unified_id: 'review:1', priority: 3, created_at: '2026-07-01T00:00:00Z' },
  ]

  assert.deepEqual(
    sortReviewQueueItems(queue).map(item => item.unified_id),
    ['review:1', 'review:2', 'review:3'],
  )
  assert.deepEqual(queue.map(item => item.unified_id), ['review:3', 'review:2', 'review:1'])
})

test('review queue retains explicit confidence, age, and person sort modes', () => {
  const queue = [
    { unified_id: 'review:2', person_id: 2, confidence: 0.9, created_at: '2026-07-02T00:00:00Z' },
    { unified_id: 'review:1', person_id: 1, confidence: 0.7, created_at: '2026-07-01T00:00:00Z' },
  ]

  assert.deepEqual(sortReviewQueueItems(queue, 'confidence_desc').map(item => item.unified_id), ['review:2', 'review:1'])
  assert.deepEqual(sortReviewQueueItems(queue, 'oldest_first').map(item => item.unified_id), ['review:1', 'review:2'])
  assert.deepEqual(sortReviewQueueItems(queue, 'recent_first').map(item => item.unified_id), ['review:2', 'review:1'])
  assert.deepEqual(sortReviewQueueItems(queue, 'by_person').map(item => item.unified_id), ['review:1', 'review:2'])
})
