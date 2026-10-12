const draftPrefix = 'plos:review-draft:'
const requestPrefix = 'plos:review-request:'

export function reviewStorage(environment = globalThis) {
    try { return environment.sessionStorage } catch { return null }
}

export function saveReviewRequest(storage, reference, request) {
    try { storage.setItem(requestPrefix + reference, JSON.stringify(request)); return true } catch { return false }
}

export function readReviewRequest(storage, reference) {
    try { return JSON.parse(storage.getItem(requestPrefix + reference) || 'null') } catch { return null }
}

export function clearReviewRequest(storage, reference) {
    try { storage.removeItem(requestPrefix + reference) } catch { return false }
    return true
}

export function normalizeFaceRegion(region) {
    if (!region || typeof region !== 'object' || Array.isArray(region)) return null
    if (['x', 'y', 'w', 'h'].some(key => region[key] == null || region[key] === '' || typeof region[key] === 'boolean')) return null
    const values = ['x', 'y', 'w', 'h'].map(key => Number(region[key]))
    if (!values.every(Number.isFinite)) return null
    const [left, top, width, height] = values
    if (left < 0 || top < 0 || width <= 0 || height <= 0 || left + width > 1.000001 || top + height > 1.000001) return null
    return { left, top, width, height }
}

export function evidenceUrl(value, origin, localOnly = false) {
    if (typeof value !== 'string' || !value.trim()) return null
    try {
        const url = new URL(value, origin)
        if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) return null
        if (localOnly && url.origin !== new URL(origin).origin) return null
        return url.href
    } catch { return null }
}

export function currentFaceSourceProof(proof, faceId, origin) {
    if (!Number.isSafeInteger(faceId) || faceId < 1 || proof?.success !== true
        || proof.coordinate_basis !== 'encoded_pixels' || proof.face_region_convention !== 'normalized_top_left'
        || !/^[a-f0-9]{64}$/.test(proof.source_sha256 || '')
        || !Array.isArray(proof.source_dimensions) || proof.source_dimensions.length !== 2
        || !proof.source_dimensions.every(value => Number.isSafeInteger(value) && value > 0)) return null
    const source = evidenceUrl(proof.preview_url, origin, true)
    if (!source) return null
    const url = new URL(source)
    if (url.pathname !== `/api/media/face-match-source/${faceId}` || url.hash
        || url.searchParams.getAll('source_sha256').length !== 1
        || url.searchParams.get('source_sha256') !== proof.source_sha256
        || [...url.searchParams.keys()].some(key => key !== 'source_sha256')) return null
    return { ...proof, preview_url: source }
}

export function currentRegistryFaceSourceProof(proof, faceId, origin) {
    if (!Number.isSafeInteger(faceId) || faceId < 1 || proof?.success !== true || proof.face_id !== faceId
        || proof.coordinate_basis !== 'encoded_pixels' || proof.face_region_convention !== 'normalized_top_left'
        || !/^[a-f0-9]{64}$/.test(proof.source_sha256 || '') || !normalizeFaceRegion(proof.region)
        || !Array.isArray(proof.source_dimensions) || proof.source_dimensions.length !== 2
        || !proof.source_dimensions.every(value => Number.isSafeInteger(value) && value > 0)) return null
    const source = evidenceUrl(proof.preview_url, origin, true)
    if (!source) return null
    const url = new URL(source)
    if (url.pathname !== '/api/media/photo-evidence/preview' || url.hash || [...url.searchParams.keys()].length !== 2
        || url.searchParams.getAll('face_id').length !== 1 || url.searchParams.get('face_id') !== String(faceId)
        || url.searchParams.getAll('source_sha256').length !== 1 || url.searchParams.get('source_sha256') !== proof.source_sha256) return null
    const face = proof.global_face, evidence = face?.global_face_evidence
    if (face?.face_id !== faceId || face.file_status !== 'active' || ![true,1].includes(face.source_current)
        || face.hidden || face.superseded_at || face.source_sha256 !== proof.source_sha256 || face.face_region_convention !== proof.face_region_convention
        || evidence?.face_id !== faceId || evidence.source_sha256 !== proof.source_sha256
        || evidence.face_region_convention !== proof.face_region_convention || evidence.coordinate_basis !== proof.coordinate_basis
        || Object.keys(proof.region).length !== 4 || !evidence.region || Object.keys(evidence.region).length !== 4
        || ['x','y','w','h'].some(axis => typeof proof.region[axis] !== 'number' || !Number.isFinite(proof.region[axis]) || evidence.region[axis] !== proof.region[axis])
        || proof.region.x + proof.region.w > 1 || proof.region.y + proof.region.h > 1
        || !/^[a-f0-9]{64}$/.test(evidence.region_fingerprint || '')) return null
    const crop = evidenceUrl(evidence.crop_url, origin, true)
    if (!crop) return null
    const cropUrl = new URL(crop)
    if (cropUrl.pathname !== `/api/media/faces/registry-crop/${faceId}` || cropUrl.hash || [...cropUrl.searchParams.keys()].length !== 2
        || cropUrl.searchParams.getAll('source_sha256').length !== 1 || cropUrl.searchParams.get('source_sha256') !== proof.source_sha256
        || cropUrl.searchParams.getAll('region_fingerprint').length !== 1 || cropUrl.searchParams.get('region_fingerprint') !== evidence.region_fingerprint) return null
    return { ...proof, preview_url: source }
}

export function canBulkReview(item, action = 'approve') {
    return item?.status === 'pending' && item.bulk_eligible === true && item.sensitive === false && Array.isArray(item.actions) && item.actions.includes(action)
        && (item.bulk_actions === undefined || (Array.isArray(item.bulk_actions) && item.bulk_actions.includes(action)))
}

export function reviewImages(review, context, origin) {
    const item = context?.item || {}
    if (review?.review_type === 'face') {
        const identity = item.unified_id || review.unified_id
        return /^face:[1-9]\d*$/.test(identity || '')
            ? [{ src: evidenceUrl(`/api/media/face-match-source/${identity.split(':')[1]}`, origin, true), title: 'Source photo with proposed face region' }]
            : []
    }
    const candidates = [
        { image_url: review?.image_url || item.image_url || item.details?.image_url || item.details?.thumbnail_url, title: 'Original image evidence' },
        ...(Array.isArray(context?.media_refs) ? context.media_refs : []),
    ]
    const found = new Map()
    for (const candidate of candidates) {
        if (!candidate || candidate.file_available === false) continue
        const image = candidate.image_url || candidate.thumbnail_url || (typeof candidate.mime_type === 'string' && candidate.mime_type.startsWith('image/') ? candidate.view_url : null)
        const src = evidenceUrl(image, origin, true)
        const title = [candidate.title, candidate.label].find(value => typeof value === 'string' && value.trim()) || 'Source image'
        if (src && !found.has(src)) found.set(src, { src, title })
    }
    return [...found.values()]
}

export function reviewRequestKey(cryptoSource = globalThis.crypto) {
    const bytes = cryptoSource.getRandomValues(new Uint8Array(16))
    bytes[6] = (bytes[6] & 15) | 64
    bytes[8] = (bytes[8] & 63) | 128
    const hex = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('')
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

export function saveReviewDraft(storage, reference, revision, notes) {
    try {
        if (!notes) storage.removeItem(draftPrefix + reference)
        else storage.setItem(draftPrefix + reference, JSON.stringify({ revision, notes, updated_at: new Date().toISOString() }))
        return true
    } catch { return false }
}

export function readReviewDraft(storage, reference) {
    try {
        const draft = JSON.parse(storage.getItem(draftPrefix + reference) || 'null')
        return draft && typeof draft.notes === 'string' ? draft : null
    } catch { return null }
}

export function clearReviewDrafts(storage) {
    try {
        for (let index = storage.length - 1; index >= 0; index--) {
            const key = storage.key(index)
            if (key?.startsWith(draftPrefix) || key?.startsWith(requestPrefix)) storage.removeItem(key)
        }
    } catch { return false }
    return true
}

export function reviewError(error) {
    const payload = error?.response?.data
    return payload?.message || (typeof payload?.error === 'string' ? payload.error : payload?.error?.message) || error?.message || 'The review could not be loaded. Try again.'
}

export function readableValue(value) {
    if (value == null || value === '') return 'Not recorded'
    if (typeof value === 'string' || typeof value === 'number') return String(value)
    if (typeof value === 'boolean') return value ? 'Yes' : 'No'
    if (Array.isArray(value)) return value.map(readableValue).join('; ')
    return Object.entries(value).map(([key, entry]) => `${key.replaceAll('_', ' ')}: ${readableValue(entry)}`).join('; ')
}

export function aiReviewHandoff(item, notes = '') {
    return `Review ${item.reference}, revision ${item.revision}. Question: ${item.question || item.title}. Status: ${item.status}. Explain the evidence, uncertainty and exact effect using read-only plos_review_lookup. ${notes ? `My question: ${notes}. ` : ''}Do not approve or change anything.`
}
