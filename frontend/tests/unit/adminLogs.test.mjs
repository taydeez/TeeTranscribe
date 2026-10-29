import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, reactive, ref, watch } from 'vue'
import ts from 'typescript'

const compile = source => ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const moduleUrl = source => `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`
const configUrl = moduleUrl(compile(await readFile(new URL('../../app/config/adminLogs.ts', import.meta.url), 'utf8')))
const source = compile(await readFile(new URL('../../app/composables/useAdminLogs.ts', import.meta.url), 'utf8')).replace(/(['"])~\/config\/adminLogs\1/g, JSON.stringify(configUrl))
const { useAdminLogs } = await import(moduleUrl(source))
const empty = () => ({ data: [], meta: { currentPage: 1, lastPage: 3, perPage: 25, total: 50 } })

function setup(t, { query = {}, permissions = ['ViewAny_OperationalLog', 'View_OperationalLog'], fetch = async () => empty() } = {}) {
  const route = reactive({ query }), calls = [], navigations = [], stops = []
  const globals = { computed, reactive, ref, watch: (...args) => { const stop = watch(...args); stops.push(stop); return stop },
    useRoute: () => route, useRouter: () => ({ replace: async value => { navigations.push(value) } }),
    useAuthStore: () => ({ isAdmin: true, user: { permissions } }),
    useAuthenticatedFetch: async (url, options) => { calls.push({ url, options }); return fetch(url, options) },
    onMounted: () => {}, onBeforeUnmount: () => {} }
  const originals = Object.fromEntries(Object.keys(globals).map(key => [key, globalThis[key]]))
  Object.assign(globalThis, globals)
  t.after(() => { stops.forEach(stop => stop()); Object.assign(globalThis, originals) })
  return { state: useAdminLogs(), calls, navigations, route }
}

test('admin log filters and pagination use authenticated requests and survive in route queries', async t => {
  const h = setup(t, { query: { type: 'provider', provider: 'deepgram', operation_id: 'one', sort: 'oldest', page: '2' } })
  await h.state.load()
  assert.deepEqual(h.calls[0], { url: '/api/taydeez/logs', options: { query: { type: 'provider', provider: 'deepgram', operation_id: 'one', sort: 'oldest', page: 2, per_page: 25 } } })
  h.state.filters.level = 'error'
  await h.state.apply()
  assert.equal(h.navigations[0].query.level, 'error')
  assert.equal(h.navigations[0].query.page, 1)
  await h.state.page(3)
  assert.equal(h.navigations[1].query.page, 3)
  await h.state.follow({ operation_type: 'transcription', operation_id: 'two' })
  assert.equal(h.navigations[2].query.operation_id, 'two')
  assert.equal(h.navigations[2].query.sort, 'oldest')
  assert.equal(h.navigations[2].query.level, undefined)
})

test('log list and details enforce their respective permissions', async t => {
  const h = setup(t, { permissions: [] })
  await h.state.load(); await h.state.inspect({ id: 'one' })
  assert.equal(h.calls.length, 0)
})

test('closing log details discards an in-flight response', async t => {
  let finish
  const h = setup(t, { fetch: () => new Promise(resolve => { finish = resolve }) })
  const request = h.state.inspect({ id: 'one' })
  assert.equal(h.state.detailLoading.value, true)
  h.state.close()
  finish({ data: { id: 'one', context: { private: false } } })
  await request
  assert.equal(h.state.selected.value, null)
  assert.equal(h.state.detailLoading.value, false)
})

test('failed log requests can retry and late earlier requests cannot replace newer results', async t => {
  const finishes = []
  const h = setup(t, { fetch: () => new Promise((resolve, reject) => finishes.push({ resolve, reject })) })
  const first = h.state.load()
  finishes[0].reject({ data: { message: 'Unavailable' } })
  await first
  assert.equal(h.state.error.value, 'Unavailable')
  const second = h.state.load(), third = h.state.load()
  finishes[2].resolve({ ...empty(), data: [{ id: 'newest' }] })
  await third
  finishes[1].resolve({ ...empty(), data: [{ id: 'old' }] })
  await second
  assert.equal(h.state.result.value.data[0].id, 'newest')
  assert.equal(h.state.error.value, '')
})
