import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, ref } from 'vue'
import { createPinia } from 'pinia'
import ts from 'typescript'

const source = (await readFile(new URL('../../app/stores/auth.ts', import.meta.url), 'utf8')).replaceAll('import.meta.client', 'true')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText.replace("'pinia'", JSON.stringify(import.meta.resolve('pinia')))
const { useAuthStore } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)
const customer = { id: 1, name: 'Customer', email: 'customer@example.com', email_verified: true }

function harness(t, fetch, savedToken = 'saved-token') {
  const values = new Map(savedToken ? [['auth_token', savedToken]] : [])
  const original = Object.fromEntries(['ref', 'computed', '$fetch', 'localStorage'].map(key => [key, globalThis[key]]))
  globalThis.ref = ref
  globalThis.computed = computed
  globalThis.$fetch = fetch
  globalThis.localStorage = { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) }
  t.after(() => { for (const key of Object.keys(original)) globalThis[key] = original[key] })
  return { auth: useAuthStore(createPinia()), values }
}

for (const [label, failure] of [['network error', new TypeError('Network request failed')], ['server error', { statusCode: 503 }], ['timeout', { statusCode: 504 }]]) {
  test(`reopening after a ${label} retains the token and permits successful restoration`, async t => {
    let unavailable = true
    const { auth, values } = harness(t, async () => { if (unavailable) throw failure; return customer })
    assert.equal(await auth.initialize(), false)
    assert.equal(values.get('auth_token'), 'saved-token')
    assert.equal(auth.initialized, false)
    assert.equal(auth.isAuthenticated, false)
    unavailable = false
    assert.equal(await auth.initialize(), true)
    assert.equal(auth.user.id, customer.id)
  })
}

test('a confirmed unauthorized response removes an invalid saved session', async t => {
  const { auth, values } = harness(t, async () => { throw { response: { status: 401 } } })
  assert.equal(await auth.initialize(), false)
  assert.equal(values.has('auth_token'), false)
  assert.equal(auth.token, '')
  assert.equal(auth.initialized, true)
})

test('concurrent restoration requests share one profile check', async t => {
  let resolveProfile, requests = 0
  const { auth } = harness(t, () => { requests++; return new Promise(resolve => { resolveProfile = resolve }) })
  const first = auth.initialize()
  const second = auth.initialize()
  assert.equal(requests, 1)
  resolveProfile(customer)
  assert.deepEqual(await Promise.all([first, second]), [true, true])
})

test('a temporary profile refresh failure preserves the signed-in customer', async t => {
  let unavailable = false
  const { auth, values } = harness(t, async () => { if (unavailable) throw { statusCode: 502 }; return customer })
  await auth.initialize()
  unavailable = true
  await assert.rejects(auth.refreshUser())
  assert.equal(auth.isAuthenticated, true)
  assert.equal(auth.user.id, customer.id)
  assert.equal(values.get('auth_token'), 'saved-token')
})

test('a delayed profile response cannot restore a session after logout', async t => {
  let resolveProfile
  const { auth, values } = harness(t, () => new Promise(resolve => { resolveProfile = resolve }))
  const pending = auth.initialize()
  auth.clearSession()
  resolveProfile(customer)
  assert.equal(await pending, false)
  assert.equal(auth.isAuthenticated, false)
  assert.equal(values.has('auth_token'), false)
})

test('an old unauthorized profile check cannot clear a newer login', async t => {
  let rejectOld
  const { auth, values } = harness(t, (_url, options) => options.headers.Authorization === 'Bearer saved-token' ? new Promise((_resolve, reject) => { rejectOld = reject }) : Promise.resolve(customer))
  const pending = auth.initialize()
  await auth.establishSession('new-token')
  rejectOld({ statusCode: 401 })
  await pending
  assert.equal(auth.isAuthenticated, true)
  assert.equal(values.get('auth_token'), 'new-token')
})
