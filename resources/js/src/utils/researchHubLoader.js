export function loadResearchHubData(api, itemParams, handlers, isCurrent = () => true) {
  void api.get('/research-hub/stats')
    .then((response) => {
      if (isCurrent()) handlers.onStats(response)
    })
    .catch((error) => {
      if (isCurrent()) handlers.onStatsError(error)
    })

  void api.get('/research-hub/agents/status')
    .then((response) => {
      if (isCurrent()) handlers.onAgentStatus(response)
    })
    .catch((error) => {
      if (isCurrent()) handlers.onAgentStatusError(error)
    })

  return api.get('/research-hub/items', { params: itemParams })
    .then((response) => {
      if (isCurrent()) handlers.onItems(response)
      return response
    })
}
