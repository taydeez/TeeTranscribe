import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { ref } from 'vue'
import ts from 'typescript'

async function load(path) {
  const source = await readFile(new URL(path, import.meta.url), 'utf8')
  const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
  return await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)
}
const { useAccountSecurity } = await load('../../app/composables/useAccountSecurity.ts')

function harness(t, fetch) {
  const auth = { isAuthenticated: false, isEmailVerified: false, cleared: 0, refreshed: 0,
    clearSession() { this.cleared++ }, async refreshUser() { this.refreshed++ } }
  const navigations = []
  const globals = ['ref', 'useAuthStore', '$fetch', 'useAuthenticatedFetch', 'navigateTo']
  const original = Object.fromEntries(globals.map(key => [key, globalThis[key]]))
  globalThis.ref = ref; globalThis.useAuthStore = () => auth; globalThis.$fetch = fetch
  globalThis.useAuthenticatedFetch = fetch; globalThis.navigateTo = async url => { navigations.push(url) }
  t.after(() => { for (const key of globals) globalThis[key] = original[key] })
  return { state: useAccountSecurity(), auth, navigations }
}

test('rapid reset link requests send one request and preserve generic success messaging', async t => {
  let finish, requests = 0
  const h = harness(t, () => { requests++; return new Promise(resolve => { finish = resolve }) })
  const pending = h.state.requestReset('user@example.com')
  assert.equal(await h.state.requestReset('user@example.com'), false)
  assert.equal(requests, 1)
  finish({ message: 'If an account exists, a link has been sent.' }); await pending
  assert.equal(h.state.message.value, 'If an account exists, a link has been sent.')
  assert.equal(h.state.busy.value, false)
})

test('expired password reset preserves the session and a successful reset clears it', async t => {
  let fail = true
  const h = harness(t, async () => {
    if (fail) throw { data: { message: 'This password reset link has expired.' } }
    return { message: 'Password reset.' }
  })
  const body = { email: 'user@example.com', token: 'token', password: 'password1', password_confirmation: 'password1' }
  await h.state.resetPassword(body)
  assert.equal(h.auth.cleared, 0)
  assert.equal(h.state.resetComplete.value, false)
  assert.equal(h.state.error.value, 'This password reset link has expired.')
  fail = false; await h.state.resetPassword(body)
  assert.equal(h.auth.cleared, 1)
  assert.equal(h.state.resetComplete.value, true)
})

test('signed verification works without login and refreshes an existing session', async t => {
  const h = harness(t, async () => ({ message: 'Email verified.' }))
  const query = { id: '1', hash: 'hash', expires: 'expiry', signature: 'signature' }
  await h.state.verifyEmail(query)
  assert.equal(h.state.verified.value, true)
  assert.equal(h.auth.refreshed, 0)
  h.auth.isAuthenticated = true
  await h.state.verifyEmail(query)
  assert.equal(h.auth.refreshed, 1)
})

test('resend cooldown prevents repeated emails and verification check refreshes before navigation', async t => {
  let requests = 0
  const h = harness(t, async () => { requests++; return { message: 'Verification sent.' } })
  h.auth.isAuthenticated = true
  await h.state.resendVerification(); await h.state.resendVerification()
  assert.equal(requests, 1)
  await h.state.checkVerification()
  assert.deepEqual(h.navigations, [])
  h.auth.isEmailVerified = true
  await h.state.checkVerification()
  assert.deepEqual(h.navigations, ['/dashboard'])
  assert.equal(h.auth.refreshed, 2)
})

test('expired verification links show an error without claiming verification succeeded', async t => {
  const h = harness(t, async () => { throw { data: { message: 'Verification link expired.' } } })
  await h.state.verifyEmail({ id: '1', hash: 'hash', expires: 'expiry', signature: 'signature' })
  assert.equal(h.state.verified.value, false)
  assert.equal(h.state.error.value, 'Verification link expired.')
})

test('dashboard route guard redirects unverified users while keeping recovery pages public', async t => {
  const globals = ['defineNuxtRouteMiddleware', 'useAuthStore', 'navigateTo']
  const original = Object.fromEntries(globals.map(key => [key, globalThis[key]]))
  let initialized = 0
  const auth = { isEmailVerified: false, async initialize() { initialized++; return true } }
  globalThis.defineNuxtRouteMiddleware = fn => fn
  globalThis.useAuthStore = () => auth
  globalThis.navigateTo = url => url
  t.after(() => { for (const key of globals) globalThis[key] = original[key] })
  const { default: guard } = await load('../../app/middleware/auth.global.ts')
  assert.equal(await guard({ path: '/dashboard' }), '/auth/verify-email')
  auth.isEmailVerified = true
  assert.equal(await guard({ path: '/dashboard' }), undefined)
  assert.equal(await guard({ path: '/auth/reset-password' }), undefined)
  assert.equal(initialized, 2)
})
