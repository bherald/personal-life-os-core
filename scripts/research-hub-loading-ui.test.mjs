import assert from 'node:assert/strict'
import fs from 'node:fs/promises'
import os from 'node:os'
import path from 'node:path'
import { test } from 'node:test'
import { parse, compileScript } from '@vue/compiler-sfc'
import { build } from 'esbuild'
import { chromium } from 'playwright'

const root = path.resolve(import.meta.dirname, '..')

test('actual Research Hub renders queue items while deferred stats fail on desktop and mobile', async () => {
  const viewFile = path.join(root, 'resources/js/src/views/ResearchHubView.vue')
  const styles = [await fs.readFile(path.join(root, 'resources/css/decision-desk.css'), 'utf8')]
  const bundle = await build({
    stdin: {
      contents: "import {createApp} from 'vue';import View from './resources/js/src/views/ResearchHubView.vue';createApp(View).mount('#app')",
      resolveDir: root,
    },
    bundle: true,
    format: 'iife',
    platform: 'browser',
    write: false,
    alias: { '@': path.join(root, 'resources/js/src') },
    define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    plugins: [{ name: 'research-hub-render-fixture', setup(builder) {
      builder.onResolve({ filter: /^vue-router$/ }, () => ({ path: 'router', namespace: 'fixture' }))
      builder.onResolve({ filter: /\/utils\/api$/ }, () => ({ path: 'api', namespace: 'fixture' }))
      builder.onLoad({ filter: /.*/, namespace: 'fixture' }, (args) => ({
        contents: args.path === 'api'
          ? 'export default window.fixtureApi'
          : 'export const useRoute=()=>window.fixtureRoute;export const useRouter=()=>({push:async()=>{},replace:async()=>{}})',
      }))
      builder.onLoad({ filter: /\.vue$/ }, async (args) => {
        if (args.path !== viewFile) {
          if (args.path.endsWith('/DynamicReviewCard.vue')) {
            return { contents: "import {h} from 'vue';export default {props:['item'],setup(props){return()=>h('article',{class:'fixture-review-card'},props.item?.title||'Fixture review')}}", resolveDir: root, loader: 'js' }
          }
          return { contents: "import {h} from 'vue';export default {setup(){return()=>h('div')}}", resolveDir: root, loader: 'js' }
        }
        const { descriptor } = parse(await fs.readFile(args.path, 'utf8'), { filename: args.path })
        styles.push(...descriptor.styles.map((style) => style.content))
        return { contents: compileScript(descriptor, { id: args.path, inlineTemplate: true }).content, resolveDir: path.dirname(args.path), loader: 'js' }
      })
    } }],
  })

  const item = { unified_id: 'privacy:fixture-review-1', title: 'Queue item loads before stats', source: 'privacy', review_type: 'privacy', category: 'privacy', status: 'pending', confidence: 1, actions: ['approve', 'reject'], details: {} }
  const heldPacket = {
    unified_id: 'genealogy_review_packet:fixture-hold',
    title: 'Technical preview hold',
    source: 'genealogy_review_packet',
    review_type: 'genealogy_review_packet',
    category: 'genealogy',
    status: 'pending',
    confidence: 1,
    actions: ['approve', 'reject', 'clarify', 'defer', 'ignore'],
    ui_schema: { inspection_only: false },
    review_focus: { preview_only: true, canonical_mutation: false, approval_ready: true, operator_vetable: true, operator_review_surface: 'review' },
    details: {},
  }
  const fixture = `window.fixtureRoute={query:{},path:'/research-hub'};window.queueResponses={};window.statsCalls=0;window.setInterval=(callback,delay)=>{if(delay===60000)window.refreshStats=callback;return 1};window.fixtureApi={get(path,options){if(path==='/research-hub/stats'){window.statsCalls++;if(window.statsCalls>1)return Promise.resolve({visible_reviews:{_total:1,privacy:{label:'Privacy',category:'privacy',total:1,visible_total:1,raw_total:1},genealogy_review_packet:{label:'Technical previews',category:'genealogy',total:13,visible_total:13,raw_total:13,inspection_only:true}}});window.statsSettled=false;return new Promise((resolve,reject)=>{window.rejectStats=()=>{window.statsSettled=true;reject(new Error('fixture stats timeout'))}})}if(path==='/research-hub/agents/status')return Promise.resolve({reviewer_feedback:[]});if(path==='/research-hub/items'){if(options?.params?.include_inspections)throw new Error('Web Review must not request inspection items');const agentId=options?.params?.agent_id;if(agentId)return new Promise(resolve=>{window.queueResponses[agentId]=resolve});return Promise.resolve({items:[${JSON.stringify(item)},${JSON.stringify(heldPacket)}],total:2,producer_counts:{'agent-1':1,'agent-2':1},has_more:false,types:{genealogy_review_packet:{inspection_only:true,total:13}}})}throw new Error('Unexpected GET '+path)}};`
  await build({ stdin: { contents: fixture, resolveDir: root }, write: false })
  const html = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${styles.join('\n')}body{margin:0;padding:12px;font-family:system-ui;background:var(--desk-canvas)}*{box-sizing:border-box}</style></head><body><main id="app"></main><script>${fixture}</script><script>${bundle.outputFiles[0].text}</script></body></html>`
  const browser = await chromium.launch({ executablePath: process.env.PLOS_REVIEW_CHROME || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] })
  const screenshotRoot = process.env.PLOS_PUBLIC_RENDER_DIR || await fs.mkdtemp(path.join(os.tmpdir(), 'plos-public-render-'))
  await fs.mkdir(screenshotRoot, { recursive: true, mode: 0o700 })
  const report = []
  try {
    for (const [name, width, height] of [['desktop', 1440, 1000], ['mobile', 390, 844]]) {
      const page = await browser.newPage({ viewport: { width, height } })
      const warnings = []
      const pageErrors = []
      page.on('console', (message) => { if (message.type() === 'warning') warnings.push(message.text()) })
      page.on('pageerror', (error) => pageErrors.push(error.message))
      await page.route('**/*', (route) => route.request().url() === 'https://plos.test/'
        ? route.fulfill({ contentType: 'text/html', body: html })
        : route.abort())
      await page.goto('https://plos.test/')
      const card = page.getByText('Queue item loads before stats', { exact: true })
      try {
        await card.waitFor({ state: 'visible', timeout: 5000 })
      } catch (error) {
        assert.fail(`${name}: queue item did not render; page errors=${JSON.stringify(pageErrors)} text=${JSON.stringify((await page.locator('body').innerText()).slice(0, 600))}`)
      }
      assert.equal(await page.getByText('Technical preview hold', { exact: true }).count(), 0, `${name}: preview-only packet is never shown in Review`)
      assert.equal(await page.getByText('genealogy_review_packet', { exact: true }).count(), 0, `${name}: inspection-only type is absent from Review filters`)
      assert.equal(await page.getByText('Diagnostics', { exact: true }).count(), 0, `${name}: Review does not offer a diagnostic mode`)
      assert.equal(await page.evaluate(() => window.statsSettled), false, `${name}: stats must remain pending after item render`)
      assert.equal(await page.getByRole('status').count(), 0, `${name}: no summary warning while stats are pending`)

      await page.evaluate(() => window.rejectStats())
      await page.waitForFunction(() => Array.from(document.querySelectorAll('[role="status"]')).some((node) => node.textContent.trim() === 'Agent summary unavailable.'))
      const warning = page.getByRole('status')
      assert.equal(await warning.isVisible(), true, `${name}: optional summary failure is visible`)
      assert.equal(await warning.innerText(), 'Agent summary unavailable.')
      assert.equal(await card.isVisible(), true, `${name}: queue item remains visible after stats failure`)
      assert(warnings.some((warning) => warning.includes('Failed to load research hub stats')))
      await page.evaluate(() => window.refreshStats())
      await page.locator('details.review-tools').evaluate((element) => { element.open = true })
      await page.getByRole('button', { name: 'All (1)' }).waitFor({ state: 'visible' })
      assert.equal(await page.getByText('Technical previews', { exact: true }).isVisible().catch(() => false), false, `${name}: raw inspection counts stay out of Review stat cards`)

      const producerFilter = page.locator('select[title^="Filter pending review items"]')
      await page.locator('details.review-tools').evaluate((element) => { element.open = true })
      await producerFilter.selectOption('agent-1')
      await page.waitForFunction(() => typeof window.queueResponses['agent-1'] === 'function')
      await producerFilter.selectOption('agent-2')
      await page.waitForFunction(() => typeof window.queueResponses['agent-2'] === 'function')
      await page.evaluate(() => window.queueResponses['agent-1']({
        items: [{ unified_id: 'privacy:stale-queue-item', title: 'Stale older filter result', source: 'privacy', review_type: 'privacy', category: 'privacy', status: 'pending', confidence: 1, actions: ['approve', 'reject'], details: {} }],
        total: 1,
        producer_counts: { 'agent-1': 1, 'agent-2': 1 },
        has_more: false,
        types: {},
      }))
      await page.waitForTimeout(50)
      assert.match(await page.locator('.review-run-status').innerText(), /Loading current review work\./, `${name}: stale request cannot clear current loading state`)
      assert.equal(await page.getByText('Stale older filter result', { exact: true }).count(), 0, `${name}: stale request cannot replace current queue`)
      await page.evaluate(() => window.queueResponses['agent-2']({
        items: [{ unified_id: 'privacy:current-queue-item', title: 'Current filter result', source: 'privacy', review_type: 'privacy', category: 'privacy', status: 'pending', confidence: 1, actions: ['approve', 'reject'], details: {} }],
        total: 1,
        producer_counts: { 'agent-1': 1, 'agent-2': 1 },
        has_more: false,
        types: {},
      }))
      await page.getByText('Current filter result', { exact: true }).waitFor({ state: 'visible' })
      assert.match(await page.locator('.review-run-status').innerText(), /Next: Current filter result/, `${name}: current request clears its loading state`)
      await page.locator('details.review-tools').evaluate((element) => { element.open = false })

      assert.equal(await page.getByRole('button', { name: 'Review next' }).count(), 1, `${name}: supported decision remains next`)
      assert.equal(await page.getByRole('button', { name: 'Inspect next' }).count(), 0, `${name}: Review never offers inspection as an action`)
      assert.match(await page.locator('.review-run-status').innerText(), /Next: Current filter result/, `${name}: actionable decision remains the queue next`)

      const metrics = await page.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth }))
      let decorativeHeaderOverflowPx = 0
      if (metrics.scrollWidth > metrics.width) {
        const overflow = await page.evaluate(() => Array.from(document.querySelectorAll('body *'))
          .map((element) => ({ tag: element.tagName, className: typeof element.className === 'string' ? element.className : '', text: element.innerText?.slice(0, 70), right: Math.round(element.getBoundingClientRect().right) }))
          .filter((element) => element.right > innerWidth + 1)
          .sort((left, right) => right.right - left.right)
          .slice(0, 8))
        assert(overflow.length > 0 && overflow.every((element) => element.className === 'ops-cap'), `${name}: content overflows viewport ${JSON.stringify({ metrics, overflow })}`)
        decorativeHeaderOverflowPx = metrics.scrollWidth - metrics.width
        assert(decorativeHeaderOverflowPx <= 4, `${name}: decorative header overflow increased ${decorativeHeaderOverflowPx}px`)
      }
      await page.screenshot({ path: path.join(screenshotRoot, `${name}.png`), fullPage: true })
      report.push({ name, ...metrics, decorative_header_overflow_px: decorativeHeaderOverflowPx, privacy_decision_remains_review_next: true, queue_item_visible_after_stats_failure: true, current_filter_survives_stale_result: true, preview_only_packet_hidden_from_review: true, warning: 'Agent summary unavailable.' })
      await page.close()
    }
  } finally {
    await browser.close()
  }
  await fs.writeFile(path.join(screenshotRoot, 'report.json'), JSON.stringify(report, null, 2), { mode: 0o600 })
  process.stdout.write(`Desktop/mobile render proof: ${screenshotRoot}\n`)
})
