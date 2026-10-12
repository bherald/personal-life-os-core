const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
const selectorKeys = ['asset_uuid', 'file_registry_id', 'face_match_id', 'face_id', 'media_id', 'tree_id']

function positiveId(value) {
  const id = typeof value === 'string' && /^\d+$/.test(value) ? Number(value) : value
  return Number.isSafeInteger(id) && id > 0 ? id : null
}

export function normalizePhotoEvidenceSelector(input) {
  if (!input || typeof input !== 'object' || Array.isArray(input)) return null
  const keys = Object.keys(input)
  if (!keys.length || keys.some(key => !selectorKeys.includes(key))) return null

  if (keys.length === 1 && keys[0] === 'asset_uuid' && typeof input.asset_uuid === 'string' && UUID.test(input.asset_uuid)) {
    return { asset_uuid: input.asset_uuid.toLowerCase() }
  }
  for (const key of ['file_registry_id', 'face_match_id', 'face_id']) {
    if (keys.length === 1 && keys[0] === key) {
      const id = positiveId(input[key])
      return id ? { [key]: id } : null
    }
  }
  if (keys.length === 2 && keys.includes('media_id') && keys.includes('tree_id')) {
    const mediaId = positiveId(input.media_id)
    const treeId = positiveId(input.tree_id)
    return mediaId && treeId ? { media_id: mediaId, tree_id: treeId } : null
  }
  return null
}

function detailsSelector(details) {
  if (typeof details?.asset_uuid === 'string' && UUID.test(details.asset_uuid)) return { asset_uuid: details.asset_uuid }
  const fileRegistryId = positiveId(details?.file_registry_id)
  return fileRegistryId ? { file_registry_id: fileRegistryId } : null
}

export function photoEvidenceSelector(context, activeItem = null) {
  const reviewType = context?.item?.review_type || activeItem?.review_type
  if (reviewType === 'global_face_identity') {
    const faceId = positiveId(context?.global_identity_review?.selected_face_evidence?.face_id)
    return faceId ? { face_id: faceId } : null
  }
  if (reviewType === 'face') {
    const faceMatchId = positiveId(context?.item?.id)
    return faceMatchId ? { face_match_id: faceMatchId } : null
  }
  const exactSources = [
    context?.item?.details,
    context?.details,
    context?.item?.source_hold,
    context?.source_hold,
  ]
  for (const source of exactSources) {
    const selector = detailsSelector(source)
    if (selector) return selector
  }
  return null
}

export function assetUuidPhotoEvidenceSelector(assetUuid) {
  return normalizePhotoEvidenceSelector({ asset_uuid: assetUuid })
}
