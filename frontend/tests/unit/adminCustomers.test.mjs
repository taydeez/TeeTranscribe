import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, reactive, ref, watch } from 'vue'
import ts from 'typescript'

const source = await readFile(new URL('../../app/composables/useAdminCustomers.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const { useAdminCustomers } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)

function setup(t, { query = {}, permitted = true, fetch = async () => ({ data: [], meta: { currentPage: 1, lastPage: 3, perPage: 20, total: 41 } }) } = {}) {
  const route = reactive({ query })
  const requests = [], navigations = [], stops = []
  const original = {}
  const globals = { ref, computed, watch: (...args) => { const stop = watch(...args); stops.push(stop); return stop },
    useRoute: () => route,
    useRouter: () => ({ replace: async value => { navigations.push(value) } }),
    useAuthStore: () => ({ isAdmin: true, user: { permissions: permitted ? ['ViewAny_User'] : [] } }),
    useAuthenticatedFetch: async (url, options) => { requests.push({ url, options }); return fetch() },
    onMounted: () => {}, onBeforeUnmount: () => {} }
  for (const [key, value] of Object.entries(globals)) { original[key] = globalThis[key]; globalThis[key] = value }
  t.after(() => { stops.forEach(stop => stop()); Object.assign(globalThis, original) })
  return { state: useAdminCustomers(), requests, navigations, route }
}

test('customer search sort and pagination come from the URL and use the authenticated endpoint', async t => {
  const h = setup(t, { query: { search: 'Ada', sort: 'oldest', page: '2' } })
  await h.state.load()
  assert.deepEqual(h.requests[0], { url: '/api/taydeez/customers', options: { query: { page: 2, per_page: 20, search: 'Ada', sort: 'oldest' } } })
  h.state.searchInput.value = '  Jane  '
  await h.state.submitSearch()
  assert.deepEqual(h.navigations[0].query, { search: 'Jane', sort: 'oldest', page: 1 })
  await h.state.changeSort('name_asc')
  assert.equal(h.navigations[1].query.page, 1)
  await h.state.goToPage(3)
  assert.equal(h.navigations[2].query.page, 3)
})

test('customers are not fetched without permission', async t => {
  const h = setup(t, { permitted: false })
  await h.state.load()
  assert.equal(h.state.canView.value, false)
  assert.equal(h.requests.length, 0)
})

test('customer list reports errors and supports retry', async t => {
  let attempts = 0
  const h = setup(t, { fetch: async () => { if (!attempts++) throw { data: { message: 'Unavailable' } }; return { data: [], meta: { currentPage: 1, lastPage: 1, perPage: 20, total: 0 } } } })
  await h.state.load()
  assert.equal(h.state.error.value, 'Unavailable')
  assert.equal(h.state.loading.value, false)
  await h.state.load()
  assert.equal(h.state.error.value, '')
  assert.deepEqual(h.state.result.value.data, [])
})

test('a slower earlier customer request cannot replace newer results', async t => {
  const finishes = []
  const h = setup(t, { fetch: () => new Promise(resolve => finishes.push(resolve)) })
  const first = h.state.load()
  const second = h.state.load()
  finishes[1]({ data: [{ id: 2 }], meta: { lastPage: 1 } })
  await second
  finishes[0]({ data: [{ id: 1 }], meta: { lastPage: 1 } })
  await first
  assert.equal(h.state.result.value.data[0].id, 2)
})
