import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { ref } from 'vue'
import ts from 'typescript'

const source = await readFile(new URL('../../app/composables/useAdminLogin.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const { useAdminLogin } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)

function setup(t, fetch) {
  const auth = { isAdmin: true, isEmailVerified: true, token: '', loggedOut: 0,
    async establishSession(token) { this.token = token }, async logout() { this.loggedOut++; this.token = '' } }
  const navigations = [], calls = []
  const keys = ['ref', 'useAuthStore', '$fetch', 'navigateTo']
  const original = Object.fromEntries(keys.map(key => [key, globalThis[key]]))
  globalThis.ref = ref
  globalThis.useAuthStore = () => auth
  globalThis.$fetch = (url, options) => { calls.push({ url, options }); return fetch(url, options) }
  globalThis.navigateTo = async path => { navigations.push(path) }
  t.after(() => { for (const key of keys) globalThis[key] = original[key] })
  return { state: useAdminLogin(), auth, calls, navigations }
}

test('administrator sign in uses dedicated prefix and requires an email code before creating a session', async t => {
  const h = setup(t, async url => url.endsWith('/login')
    ? { requires_two_factor: true, email: 'admin@example.com' } : { token: 'verified-token' })
  assert.equal(await h.state.login({ email: 'admin@example.com', password: 'secret' }), true)
  assert.equal(h.state.step.value, 'code')
  assert.equal(h.auth.token, '')
  assert.equal(h.calls[0].url, '/api/taydeez/login')
  assert.equal(h.calls[0].options.timeout, 35_000)
  assert.equal(await h.state.verify('123456'), true)
  assert.equal(h.calls[1].url, '/api/taydeez/verify')
  assert.equal(h.calls[1].options.timeout, 35_000)
  assert.deepEqual(h.calls[1].options.body, { email: 'admin@example.com', code: '123456' })
  assert.equal(h.auth.token, 'verified-token')
  assert.deepEqual(h.navigations, ['/taydeez'])
})

test('duplicate sign in clicks issue one request', async t => {
  let finish
  const h = setup(t, () => new Promise(resolve => { finish = resolve }))
  const first = h.state.login({ email: 'admin@example.com', password: 'secret' })
  assert.equal(await h.state.login({ email: 'admin@example.com', password: 'secret' }), false)
  assert.equal(h.calls.length, 1)
  finish({ requires_two_factor: true, email: 'admin@example.com' })
  assert.equal(await first, true)
})

test('code errors preserve the verification step and requesting a new code returns to the password step', async t => {
  const h = setup(t, async () => { throw { data: { message: 'Invalid or expired code.' } } })
  h.state.resumeVerification('admin@example.com')
  assert.equal(await h.state.verify('000000'), false)
  assert.equal(h.state.step.value, 'code')
  assert.equal(h.state.error.value, 'Invalid or expired code.')
  assert.equal(h.auth.token, '')
  h.state.requestNewCode()
  assert.equal(h.state.step.value, 'password')
  assert.equal(h.state.error.value, '')
})

test('non administrator sessions are rejected and signed out', async t => {
  const h = setup(t, async () => ({ token: 'not-admin' }))
  h.auth.isAdmin = false
  h.state.resumeVerification('user@example.com')
  assert.equal(await h.state.verify('123456'), false)
  assert.equal(h.auth.loggedOut, 1)
  assert.equal(h.auth.token, '')
  assert.deepEqual(h.navigations, [])
})

test('new administrator is routed to initial password change after email verification', async t => {
  const h = setup(t, async () => ({ token: 'verified-token' }))
  h.auth.user = { must_change_password: true }
  h.state.resumeVerification('new-admin@example.com')
  assert.equal(await h.state.verify('123456'), true)
  assert.deepEqual(h.navigations, ['/taydeez/change-password'])
})

test('a timed out sign in releases the form and allows a retry without changing steps', async t => {
  let attempts = 0
  const h = setup(t, async () => {
    if (++attempts === 1) throw { cause: { name: 'TimeoutError' } }
    return { requires_two_factor: true, email: 'admin@example.com' }
  })
  const credentials = { email: 'admin@example.com', password: 'secret' }
  assert.equal(await h.state.login(credentials), false)
  assert.equal(h.state.busy.value, false)
  assert.equal(h.state.step.value, 'password')
  assert.equal(h.state.error.value, 'Sign-in took too long. Please try again.')
  assert.deepEqual(h.navigations, [])
  assert.equal(await h.state.login(credentials), true)
  assert.equal(h.state.step.value, 'code')
  assert.equal(h.state.error.value, '')
})
