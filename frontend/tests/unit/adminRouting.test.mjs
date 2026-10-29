import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import ts from 'typescript'

async function loadMiddleware(path) {
  const source = (await readFile(new URL(path, import.meta.url), 'utf8')).replaceAll('import.meta.server', 'false')
  const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
  return (await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)).default
}

globalThis.defineNuxtRouteMiddleware = handler => handler
const admin = await loadMiddleware('../../app/middleware/admin.ts')
const workspace = await loadMiddleware('../../app/middleware/auth.global.ts')

function setup(t, overrides = {}) {
  const auth = { isAdmin: true, isEmailVerified: true, user: null, async initialize() { return true }, async logout() { this.user = null }, ...overrides }
  const navigations = [], requests = []
  const globals = {
    useAuthStore: () => auth,
    navigateTo: url => { navigations.push(url); return url },
    useAuthenticatedFetch: async url => { requests.push(url); return { is_admin: true, permissions: ['ViewAny_User'] } },
    createError: value => Object.assign(new Error(value.message), value),
  }
  const originals = Object.fromEntries(Object.keys(globals).map(key => [key, globalThis[key]]))
  Object.assign(globalThis, globals)
  t.after(() => Object.assign(globalThis, originals))
  return { auth, navigations, requests }
}

test('admin page validates the administrator session with the backend before allowing access', async t => {
  const h = setup(t)
  await admin({ path: '/taydeez' })
  assert.deepEqual(h.requests, ['/api/taydeez/user'])
  assert.deepEqual(h.auth.user.permissions, ['ViewAny_User'])
  assert.deepEqual(h.navigations, [])
})

test('unauthenticated and regular accounts cannot enter the admin dashboard', async t => {
  const h = setup(t, { initialize: async () => false })
  await admin({ path: '/taydeez' })
  assert.deepEqual(h.navigations, ['/taydeez/login'])
  h.auth.initialize = async () => true
  h.auth.isAdmin = false
  await admin({ path: '/taydeez' })
  assert.deepEqual(h.navigations, ['/taydeez/login', '/dashboard'])
  assert.deepEqual(h.requests, [])
})

test('administrator entering the regular dashboard is redirected to the admin overview', async t => {
  const h = setup(t)
  await workspace({ path: '/dashboard/transcriptions' })
  assert.deepEqual(h.navigations, ['/taydeez'])
})

test('an invalid admin session is rejected rather than rendering the dashboard', async t => {
  const h = setup(t)
  globalThis.useAuthenticatedFetch = async () => { throw { statusCode: 403 } }
  await admin({ path: '/taydeez' })
  assert.deepEqual(h.navigations, ['/taydeez/login'])
})

test('first password change is required before an administrator can access other pages', async t => {
  const h = setup(t)
  globalThis.useAuthenticatedFetch = async () => ({ is_admin: true, must_change_password: true })
  await admin({ path: '/taydeez/accounts' })
  assert.deepEqual(h.navigations, ['/taydeez/change-password'])
  h.navigations.length = 0
  await admin({ path: '/taydeez/change-password' })
  assert.deepEqual(h.navigations, [])
})
