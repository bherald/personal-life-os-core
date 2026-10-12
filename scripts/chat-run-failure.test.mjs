import assert from 'node:assert/strict'
import test from 'node:test'

import {
  createLocalChatRunFailure,
  normalizeChatRunFailure,
  normalizeChatRunId,
} from '../resources/js/src/utils/chatRunFailure.js'

const runId = '018f6d75-86d1-7a4d-8e9f-98e7f6d5c4b3'

test('accepts a correlated content-free run failure', () => {
  const failure = normalizeChatRunFailure({
    type: 'run_error',
    schema: 'plos.chat_run_failure.v1',
    run_id: runId,
    error_code: 'provider_rate_limited',
    message: 'untrusted server text',
    retryable: true,
    raw_error_included: false,
  }, runId)

  assert.equal(failure.run_id, runId)
  assert.equal(failure.error_code, 'provider_rate_limited')
  assert.equal(failure.message, 'The AI provider is temporarily rate limited.')
  assert.equal(failure.retryable, true)
  assert.equal(JSON.stringify(failure).includes('untrusted server text'), false)
})

test('accepts the fixed content-free tool lifecycle failure', () => {
  const failure = normalizeChatRunFailure({
    type: 'run_error',
    schema: 'plos.chat_run_failure.v1',
    run_id: runId,
    error_code: 'tool_lifecycle_invalid',
    message: 'private lifecycle detail',
    retryable: false,
    raw_error_included: false,
  }, runId)

  assert.equal(failure.error_code, 'tool_lifecycle_invalid')
  assert.equal(failure.message, 'The tool response could not be safely correlated.')
  assert.equal(failure.retryable, false)
  assert.equal(JSON.stringify(failure).includes('private lifecycle detail'), false)
})

test('fails closed on an unknown code, mismatched run, or raw-error marker', () => {
  for (const event of [
    { type: 'run_error', schema: 'plos.chat_run_failure.v1', run_id: runId, error_code: 'secret_error', raw_error_included: false },
    { type: 'run_error', schema: 'plos.chat_run_failure.v1', run_id: '018f6d75-86d1-7a4d-8e9f-98e7f6d5c4b4', error_code: 'chat_run_failed', raw_error_included: false },
    { type: 'run_error', schema: 'plos.chat_run_failure.v1', run_id: runId, error_code: 'chat_run_failed', raw_error_included: true },
  ]) {
    const failure = normalizeChatRunFailure(event, runId)
    assert.equal(failure.error_code, 'chat_failure_contract_invalid')
    assert.equal(failure.retryable, false)
  }
})

test('requires the expected start-event correlation identity', () => {
  const failure = normalizeChatRunFailure({
    type: 'run_error',
    schema: 'plos.chat_run_failure.v1',
    run_id: runId,
    error_code: 'chat_run_failed',
    raw_error_included: false,
  })

  assert.equal(failure.error_code, 'chat_failure_contract_invalid')
  assert.equal(failure.retryable, false)
})

test('normalizes only UUID-shaped run identity', () => {
  assert.equal(normalizeChatRunId(runId.toUpperCase()), runId)
  assert.equal(normalizeChatRunId('../private-run'), null)
})

test('creates a fixed local transport failure without inventing server correlation', () => {
  const failure = createLocalChatRunFailure('provider_connection_failed', true)

  assert.equal(failure.run_id, null)
  assert.equal(failure.error_code, 'provider_connection_failed')
  assert.equal(failure.message, 'The AI provider could not be reached.')
  assert.equal(failure.retryable, true)
  assert.equal(failure.raw_error_included, false)
})
