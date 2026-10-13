import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, reactive, ref, watch } from 'vue'
import ts from 'typescript'

const source = await readFile(new URL('../../app/composables/useAdminDashboard.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const { useAdminDashboard } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)

function setup(t, { query = {}, permissions = ['View_Dashboard'], fetch = async () => ({ data: { daily: [], summary: {} } }) } = {}) {
  const route = reactive({ query }), calls = [], navigations = [], stops = [], cleanup = []
  const globals = { computed, ref, watch: (...args) => { const stop = watch(...args); stops.push(stop); return stop },
    useRoute: () => route, useRouter: () => ({ replace: async value => { navigations.push(value) } }),
    useAuthStore: () => ({ isAdmin: true, user: { permissions } }),
    useAuthenticatedFetch: async (url, options) => { calls.push({ url, options }); return fetch(url, options) },
    onMounted: () => {}, onBeforeUnmount: callback => cleanup.push(callback) }
  const originals = Object.fromEntries(Object.keys(globals).map(key => [key, globalThis[key]]))
  Object.assign(globalThis, globals)
  t.after(() => { stops.forEach(stop => stop()); Object.assign(globalThis, originals) })
  return { state: useAdminDashboard(), calls, navigations, route, cleanup }
}

test('dashboard filters use the URL and keep payment environments separate', async t => {
  const h = setup(t, { query: { from: '2026-10-01', to: '2026-10-10', environment: 'test', revenue_currency: 'USD' } })
  await h.state.load()
  assert.deepEqual(h.calls[0], { url: '/api/taydeez/dashboard', options: { query: { from: '2026-10-01', to: '2026-10-10', environment: 'test' } } })
  h.state.environment.value = 'live'
  await h.state.apply()
  assert.equal(h.navigations[0].query.environment, 'live')
  assert.equal(h.navigations[0].query.revenue_currency, 'USD')
})

test('dashboard data is not fetched without dashboard permission', async t => {
  const h = setup(t, { permissions: [] })
  await h.state.load()
  assert.equal(h.calls.length, 0)
})

test('date presets include today and send the selected range to the URL', async t => {
  const h = setup(t)
  await h.state.range(7)
  const expected = new Date(h.state.today + 'T00:00:00Z')
  expected.setUTCDate(expected.getUTCDate() - 6)
  assert.equal(h.navigations[0].query.from, expected.toISOString().slice(0, 10))
  assert.equal(h.navigations[0].query.to, h.state.today)
})

test('a slow dashboard request cannot overwrite results for a newer period', async t => {
  const finishes = []
  const h = setup(t, { fetch: () => new Promise(resolve => finishes.push(resolve)) })
  const first = h.state.load(), second = h.state.load()
  finishes[1]({ data: { from: 'newer' } }); await second
  finishes[0]({ data: { from: 'older' } }); await first
  assert.equal(h.state.report.value.from, 'newer')
  assert.equal(h.state.loading.value, false)
})

test('dashboard errors allow retry and unmounting discards in flight results', async t => {
  let finish, attempts = 0
  const h = setup(t, { fetch: async () => {
    if (!attempts++) throw { data: { message: 'Temporarily unavailable' } }
    return new Promise(resolve => { finish = resolve })
  } })
  await h.state.load()
  assert.equal(h.state.error.value, 'Temporarily unavailable')
  const request = h.state.load()
  h.cleanup.forEach(callback => callback())
  finish({ data: { from: 'ignored' } }); await request
  assert.equal(h.state.report.value, null)
})
