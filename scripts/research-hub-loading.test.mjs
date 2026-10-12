import assert from 'node:assert/strict'
import test from 'node:test'
import { loadResearchHubData } from '../resources/js/src/utils/researchHubLoader.js'

function deferred() {
  let resolve
  let reject
  const promise = new Promise((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

test('review items resolve while optional stats and agent status are still pending', async () => {
  const stats = deferred()
  const status = deferred()
  const seen = []
  const api = {
    get(path, options) {
      if (path.endsWith('/stats')) return stats.promise
      if (path.endsWith('/agents/status')) return status.promise
      assert.equal(path, '/research-hub/items')
      assert.deepEqual(options, { params: { limit: 50, offset: 0 } })
      return Promise.resolve({ items: [{ unified_id: 'review-1' }], total: 1 })
    },
  }

  const response = await loadResearchHubData(api, { limit: 50, offset: 0 }, {
    onItems: (value) => seen.push(['items', value.items.length]),
    onStats: (value) => seen.push(['stats', value]),
    onStatsError: (error) => seen.push(['stats-error', error.message]),
    onAgentStatus: (value) => seen.push(['status', value]),
    onAgentStatusError: (error) => seen.push(['status-error', error.message]),
  })

  assert.equal(response.total, 1)
  assert.deepEqual(seen, [['items', 1]])

  stats.reject(new Error('stats timed out'))
  status.resolve({ reviewer_feedback: [] })
  await new Promise((resolve) => setImmediate(resolve))
  assert.deepEqual(seen[0], ['items', 1])
  assert.deepEqual(seen.slice(1).sort(([left], [right]) => left.localeCompare(right)), [
    ['stats-error', 'stats timed out'],
    ['status', { reviewer_feedback: [] }],
  ].sort(([left], [right]) => left.localeCompare(right)))
})

test('item failure rejects independently and optional failures are handled', async () => {
  const seen = []
  const api = {
    get(path) {
      if (path.endsWith('/items')) return Promise.reject(new Error('items unavailable'))
      return Promise.reject(new Error(`${path} unavailable`))
    },
  }

  await assert.rejects(loadResearchHubData(api, {}, {
    onItems: () => assert.fail('failed item request must not publish items'),
    onStats: () => assert.fail('stats failed'),
    onStatsError: (error) => seen.push(error.message),
    onAgentStatus: () => assert.fail('status failed'),
    onAgentStatusError: (error) => seen.push(error.message),
  }), /items unavailable/)

  await new Promise((resolve) => setImmediate(resolve))
  assert.deepEqual(seen.sort(), ['/research-hub/stats unavailable', '/research-hub/agents/status unavailable'].sort())
})

test('late results from an older load cannot replace the current queue or summaries', async () => {
  const oldStats = deferred()
  const oldStatus = deferred()
  const oldItems = deferred()
  let generation = 1
  const seen = []
  const handlers = (label) => ({
    onItems: (response) => seen.push([label, 'items', response.items[0].unified_id]),
    onStats: (response) => seen.push([label, 'stats', response.label]),
    onStatsError: (error) => seen.push([label, 'stats-error', error.message]),
    onAgentStatus: (response) => seen.push([label, 'status', response.label]),
    onAgentStatusError: (error) => seen.push([label, 'status-error', error.message]),
  })
  const oldApi = {
    get(path) {
      if (path.endsWith('/stats')) return oldStats.promise
      if (path.endsWith('/agents/status')) return oldStatus.promise
      return oldItems.promise
    },
  }
  const oldLoad = loadResearchHubData(oldApi, {}, handlers('old'), () => generation === 1)

  generation = 2
  const currentApi = {
    get(path) {
      if (path.endsWith('/stats')) return Promise.resolve({ label: 'current' })
      if (path.endsWith('/agents/status')) return Promise.resolve({ label: 'current' })
      return Promise.resolve({ items: [{ unified_id: 'current-item' }] })
    },
  }
  await loadResearchHubData(currentApi, {}, handlers('current'), () => generation === 2)
  await new Promise((resolve) => setImmediate(resolve))

  oldItems.resolve({ items: [{ unified_id: 'stale-item' }] })
  oldStats.resolve({ label: 'stale' })
  oldStatus.resolve({ label: 'stale' })
  await oldLoad
  await new Promise((resolve) => setImmediate(resolve))

  assert.deepEqual(seen.sort((left, right) => left.join(':').localeCompare(right.join(':'))), [
    ['current', 'items', 'current-item'],
    ['current', 'stats', 'current'],
    ['current', 'status', 'current'],
  ].sort((left, right) => left.join(':').localeCompare(right.join(':'))))
})
