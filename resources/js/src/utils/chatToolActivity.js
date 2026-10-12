const TOOL_CALL_ID_PATTERN = /^(?:tool_[1-9]\d*_[1-9]\d*|legacy_[1-9]\d*)$/
const TOOL_STATUSES = new Set(['running', 'succeeded', 'failed', 'unknown'])

const boundedName = (value) => {
  if (typeof value !== 'string') return null
  const normalized = value.trim()
  if (!normalized || normalized.length > 191 || /[\u0000-\u001f\u007f]/.test(normalized)) return null
  return normalized
}

const normalizedArguments = (value) => {
  return value !== null && typeof value === 'object' ? value : {}
}

export const normalizeChatToolActivities = (value) => {
  let rows = value
  if (typeof rows === 'string') {
    try {
      rows = JSON.parse(rows)
    } catch {
      return []
    }
  }
  if (!Array.isArray(rows)) return []

  return rows.flatMap((row, index) => {
    if (!row || typeof row !== 'object') return []
    const suppliedId = row.tool_call_id
    const toolCallId = suppliedId === undefined || suppliedId === null
      ? `legacy_${index + 1}`
      : String(suppliedId)
    const server = boundedName(row.server)
    const tool = boundedName(row.tool)
    if (!TOOL_CALL_ID_PATTERN.test(toolCallId) || !server || !tool) return []

    return [{
      tool_call_id: toolCallId,
      server,
      tool,
      arguments: normalizedArguments(row.arguments),
      status: TOOL_STATUSES.has(row.status) ? row.status : 'unknown',
    }]
  })
}

export const applyChatToolEvent = (current, event) => {
  const activities = normalizeChatToolActivities(current)
  const toolCallId = typeof event?.tool_call_id === 'string' ? event.tool_call_id : ''
  if (!TOOL_CALL_ID_PATTERN.test(toolCallId) || toolCallId.startsWith('legacy_')) return activities

  if (event.type === 'tool_call') {
    const server = boundedName(event.server)
    const tool = boundedName(event.tool)
    if (!server || !tool || activities.some(item => item.tool_call_id === toolCallId)) return activities

    return [...activities, {
      tool_call_id: toolCallId,
      server,
      tool,
      arguments: normalizedArguments(event.arguments),
      status: 'running',
    }]
  }

  if (event.type !== 'tool_result' && event.type !== 'tool_error') return activities

  return activities.map((activity) => {
    if (activity.tool_call_id !== toolCallId || ['succeeded', 'failed'].includes(activity.status)) {
      return activity
    }
    return { ...activity, status: event.type === 'tool_result' ? 'succeeded' : 'failed' }
  })
}
