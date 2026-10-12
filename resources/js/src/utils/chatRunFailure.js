const FAILURE_SCHEMA = 'plos.chat_run_failure.v1'
const RUN_ID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

const FAILURE_MESSAGES = Object.freeze({
  provider_connection_failed: 'The AI provider could not be reached.',
  provider_rate_limited: 'The AI provider is temporarily rate limited.',
  provider_timeout: 'The AI provider did not respond in time.',
  provider_unavailable: 'The AI provider is temporarily unavailable.',
  provider_authentication_failed: 'The AI provider could not authenticate.',
  provider_request_failed: 'The AI provider rejected the request.',
  provider_transport_failed: 'The AI provider connection failed.',
  tool_lifecycle_invalid: 'The tool response could not be safely correlated.',
  chat_persistence_failed: 'The chat response could not be saved.',
  chat_run_failed: 'The AI request failed before completion.',
  chat_failure_contract_invalid: 'The AI request ended with an invalid failure receipt.',
})

export const normalizeChatRunId = (value) => {
  return typeof value === 'string' && RUN_ID_PATTERN.test(value) ? value.toLowerCase() : null
}

export const normalizeChatRunFailure = (event, expectedRunId = null) => {
  const runId = normalizeChatRunId(event?.run_id)
  const expected = normalizeChatRunId(expectedRunId)
  const suppliedCode = typeof event?.error_code === 'string' ? event.error_code : ''
  const valid = event?.type === 'run_error'
    && event?.schema === FAILURE_SCHEMA
    && runId !== null
    && expected !== null
    && runId === expected
    && Object.hasOwn(FAILURE_MESSAGES, suppliedCode)
    && event?.raw_error_included === false

  const errorCode = valid ? suppliedCode : 'chat_failure_contract_invalid'

  return {
    schema: FAILURE_SCHEMA,
    run_id: runId,
    error_code: errorCode,
    message: FAILURE_MESSAGES[errorCode],
    retryable: valid && event?.retryable === true,
    raw_error_included: false,
  }
}

export const createLocalChatRunFailure = (errorCode, retryable = false) => {
  const safeCode = Object.hasOwn(FAILURE_MESSAGES, errorCode)
    ? errorCode
    : 'chat_run_failed'

  return {
    schema: FAILURE_SCHEMA,
    run_id: null,
    error_code: safeCode,
    message: FAILURE_MESSAGES[safeCode],
    retryable: retryable === true,
    raw_error_included: false,
  }
}
