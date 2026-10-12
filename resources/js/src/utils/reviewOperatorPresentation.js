const readinessStates = [
    { value: 'ready', label: 'Ready to decide' },
    { value: 'needs_evidence', label: 'Needs evidence' },
    { value: 'needs_research', label: 'Needs research' },
    { value: 'operational_followup', label: 'Operational follow-up' },
    { value: 'source_review', label: 'Source-specific review' },
]

export { readinessStates }

export function boundedText(value, limit = 2400) {
    if (typeof value !== 'string') return 'Not available in this review. Open the source-specific details.'
    const text = value.trim()
    if (!text) return 'Not provided.'
    return text.length > limit ? `${text.slice(0, limit)}… [Additional text omitted here; open the exact source.]` : text
}

export function operatorPresentation(item) {
    const view = item?.operator_view
    const sourceEvidence = value => typeof value === 'string' && /(?:original photo|usable crop).*(?:unavailable|missing)/i.test(value)
        ? 'A source photo or crop was not returned for this review. Verify the media record before deciding.' : value
    if (view?.schema !== 'plos.review_desk_operator.v1') return {
        available: false,
        summary: 'A structured operator view is not available for this item. Use the exact source review or request follow-up; do not treat this as ready to approve.',
        sections: [], proposedActions: [], missingEvidence: [], observedAt: '', omissions: [],
    }
    const sections = Array.isArray(view.sections) ? view.sections : []
    const proposedActions = Array.isArray(view.proposed_actions) ? view.proposed_actions : []
    const missingEvidence = Array.isArray(view.missing_evidence) ? view.missing_evidence : []
    const omissions = []
    if (sections.length > 100) omissions.push(`${sections.length - 100} additional evidence sections are not displayed here; open the exact source.`)
    const omittedFields = sections.slice(0, 100).reduce((count, section) => count + Math.max(0, (Array.isArray(section?.fields) ? section.fields.length : 0) - 100), 0)
    if (omittedFields) omissions.push(`${omittedFields} additional evidence fields are not displayed here; open the exact source.`)
    if (proposedActions.length > 30) omissions.push(`${proposedActions.length - 30} additional proposed actions are not displayed here; open the exact source.`)
    if (missingEvidence.length > 100) omissions.push(`${missingEvidence.length - 100} additional evidence requirements are not displayed here; open the exact source.`)
    return {
        available: true,
        summary: boundedText(view.summary),
        sections: sections.slice(0, 100).map(section => ({
            title: boundedText(section?.title, 160),
            fields: (Array.isArray(section?.fields) ? section.fields : []).slice(0, 100).map(field => ({ label: boundedText(field?.label, 160), value: boundedText(field?.label === 'Photo' && field?.value === 'Unavailable' ? 'Not returned for this review; verify the media record.' : field?.value) })),
        })),
        proposedActions: proposedActions.slice(0, 30).map(action => ({
            label: boundedText(action?.label, 160), description: boundedText(action?.description), effect: boundedText(action?.effect), sourceUrl: action?.source_url || '',
        })),
        missingEvidence: missingEvidence.slice(0, 100).map(value => boundedText(sourceEvidence(value), 500)),
        observedAt: typeof view.observed_at === 'string' ? view.observed_at : '',
        omissions,
    }
}

export function readinessCounts(value) {
    const counts = value && typeof value === 'object' ? value : {}
    return Object.fromEntries(readinessStates.map(state => [state.value, Math.max(0, Number(counts[state.value]) || 0)]))
}

export function readinessLabel(item) {
    const state = readinessStates.find(entry => entry.value === item?.readiness?.state)
    return state ? boundedText(item.readiness.label || state.label, 80) : 'Readiness not assessed'
}
