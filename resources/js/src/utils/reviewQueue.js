export function nextReviewStreamItem(items, currentUnifiedId) {
  if (!Array.isArray(items) || !currentUnifiedId) return null

  const currentIndex = items.findIndex((item) => item?.unified_id === currentUnifiedId)
  if (currentIndex < 0) return null

  return items[currentIndex + 1] || items[currentIndex - 1] || null
}

function timestamp(value, fallback) {
  const parsed = new Date(value).getTime()

  return Number.isFinite(parsed) ? parsed : fallback
}

function stableId(item) {
  return String(item?.unified_id ?? '')
}

export function sortReviewQueueItems(items, mode = 'review_priority') {
  if (!Array.isArray(items)) return []

  return [...items].sort((a, b) => {
    if (mode === 'confidence_desc') {
      const confidence = Number(b?.confidence ?? -1) - Number(a?.confidence ?? -1)
      if (confidence !== 0) return confidence
    } else if (mode === 'oldest_first') {
      const createdAt = timestamp(a?.created_at, Number.MAX_SAFE_INTEGER)
        - timestamp(b?.created_at, Number.MAX_SAFE_INTEGER)
      if (createdAt !== 0) return createdAt
    } else if (mode === 'recent_first') {
      const createdAt = timestamp(b?.created_at, Number.MIN_SAFE_INTEGER)
        - timestamp(a?.created_at, Number.MIN_SAFE_INTEGER)
      if (createdAt !== 0) return createdAt
    } else if (mode === 'by_person') {
      const person = Number(a?.person_id ?? 0) - Number(b?.person_id ?? 0)
      if (person !== 0) return person

      const confidence = Number(b?.confidence ?? -1) - Number(a?.confidence ?? -1)
      if (confidence !== 0) return confidence
    } else {
      const priority = Number(b?.priority ?? 0) - Number(a?.priority ?? 0)
      if (priority !== 0) return priority

      const createdAt = timestamp(a?.created_at, Number.MAX_SAFE_INTEGER)
        - timestamp(b?.created_at, Number.MAX_SAFE_INTEGER)
      if (createdAt !== 0) return createdAt
    }

    return stableId(a).localeCompare(stableId(b))
  })
}
