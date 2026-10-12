import assert from 'node:assert/strict'
import test from 'node:test'

import {
  applyChatToolEvent,
  normalizeChatToolActivities,
} from '../resources/js/src/utils/chatToolActivity.js'

test('correlates tool results without dropping earlier iterations', () => {
  let activities = []
  activities = applyChatToolEvent(activities, {
    type: 'tool_call',
    tool_call_id: 'tool_1_1',
    server: 'plos',
    tool: 'search',
    arguments: { query: 'one' },
  })
  activities = applyChatToolEvent(activities, {
    type: 'tool_result',
    tool_call_id: 'tool_1_1',
  })
  activities = applyChatToolEvent(activities, {
    type: 'tool_call',
    tool_call_id: 'tool_2_1',
    server: 'plos',
    tool: 'read',
    arguments: { id: 7 },
  })

  assert.deepEqual(activities, [
    {
      tool_call_id: 'tool_1_1',
      server: 'plos',
      tool: 'search',
      arguments: { query: 'one' },
      status: 'succeeded',
    },
    {
      tool_call_id: 'tool_2_1',
      server: 'plos',
      tool: 'read',
      arguments: { id: 7 },
      status: 'running',
    },
  ])
})

test('marks only the correlated tool call failed and retains no raw error', () => {
  let activities = normalizeChatToolActivities([
    { tool_call_id: 'tool_1_1', server: 'plos', tool: 'first', arguments: {}, status: 'running' },
    { tool_call_id: 'tool_1_2', server: 'plos', tool: 'second', arguments: {}, status: 'running' },
  ])
  activities = applyChatToolEvent(activities, {
    type: 'tool_error',
    tool_call_id: 'tool_1_2',
    error: 'private upstream response',
  })

  assert.equal(activities[0].status, 'running')
  assert.equal(activities[1].status, 'failed')
  assert.equal('error' in activities[1], false)
})

test('keeps the first terminal result and labels uncorrelated history unknown', () => {
  let activities = normalizeChatToolActivities([
    { server: 'legacy', tool: 'lookup', arguments: { id: 9 } },
    { tool_call_id: 'tool_1_1', server: 'plos', tool: 'current', status: 'running' },
  ])
  activities = applyChatToolEvent(activities, {
    type: 'tool_result',
    tool_call_id: 'tool_1_1',
  })
  activities = applyChatToolEvent(activities, {
    type: 'tool_error',
    tool_call_id: 'tool_1_1',
    error: 'late duplicate',
  })

  assert.equal(activities[0].tool_call_id, 'legacy_1')
  assert.equal(activities[0].status, 'unknown')
  assert.equal(activities[1].status, 'succeeded')
  assert.equal('error' in activities[1], false)
})

test('normalizes stored JSON and rejects malformed lifecycle rows', () => {
  const activities = normalizeChatToolActivities(JSON.stringify([
    { tool_call_id: 'tool_1_1', server: 'plos', tool: 'search', arguments: [], status: 'succeeded' },
    { tool_call_id: '../bad', server: 'plos', tool: 'unsafe', status: 'failed' },
    { tool_call_id: 'tool_1_2', server: '', tool: 'missing-server', status: 'running' },
  ]))

  assert.deepEqual(activities, [
    {
      tool_call_id: 'tool_1_1',
      server: 'plos',
      tool: 'search',
      arguments: [],
      status: 'succeeded',
    },
  ])
})
