import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, nextTick, reactive, ref, watch } from 'vue'
import ts from 'typescript'

async function load(name) {
  const source = await readFile(new URL(`../../app/composables/${name}.ts`, import.meta.url), 'utf8')
  const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
  return (await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`))[name]
}
const useAdminAccounts = await load('useAdminAccounts')
const useAdminRoles = await load('useAdminRoles')
const useAdminInitialPassword = await load('useAdminInitialPassword')
function setup(t, fetch, permissions = []) {
  const calls = [], navigations = [], stops = []
  const route = reactive({ query: { role: '1' } })
  const auth = { user: { permissions }, cleared: false, clearSession() { this.cleared = true }, async refreshUser() {} }
  const globals = { ref, computed, watch: (...args) => { const stop = watch(...args); stops.push(stop); return stop },
    useRoute: () => route, useRouter: () => ({ replace: async value => { route.query = value.query } }), useAuthStore: () => auth,
    onMounted: () => {}, onBeforeUnmount: () => {},
    navigateTo: async value => { navigations.push(value) },
    useAuthenticatedFetch: async (url, options) => { calls.push({ url, options }); return fetch(url, options) } }
  const originals = Object.fromEntries(Object.keys(globals).map(key => [key, globalThis[key]]))
  Object.assign(globalThis, globals)
  t.after(() => { stops.forEach(stop => stop()); Object.assign(globalThis, originals) })
  return { calls, navigations, auth }
}
test('initial password change blocks duplicate submits and clears the session only after success', async t => {
  let finish
  const h = setup(t, () => new Promise(resolve => { finish = resolve }))
  const state = useAdminInitialPassword()
  const first = state.save('InitialPassword123', 'OwnPassword123', 'OwnPassword123')
  assert.equal(await state.save('InitialPassword123', 'OwnPassword123', 'OwnPassword123'), false)
  assert.equal(h.calls.length, 1)
  assert.equal(h.auth.cleared, false)
  finish({})
  assert.equal(await first, true)
  assert.equal(h.auth.cleared, true)
  assert.deepEqual(h.navigations, ['/taydeez/login?passwordChanged=1'])
})
test('incorrect initial password keeps the current session and shows the server error', async t => {
  const h = setup(t, async () => { throw { data: { message: 'Incorrect current password.' } } })
  const state = useAdminInitialPassword()
  assert.equal(await state.save('wrong', 'OwnPassword123', 'OwnPassword123'), false)
  assert.equal(h.auth.cleared, false)
  assert.equal(state.error.value, 'Incorrect current password.')
})
test('administrator list permissions cannot be used to create or delete accounts', async t => {
  const h = setup(t, async () => ({}), ['ViewAny_AdminAccount'])
  const state = useAdminAccounts()
  assert.equal(await state.create({ name: 'New', username: 'new', email: 'new@example.com', password: 'Password12345', role_id: 1 }), false)
  state.deleting.value = { id: 2, name: 'Admin' }
  assert.equal(await state.remove(), false)
  assert.equal(h.calls.length, 0)
})
test('role permission saves send only the selected permissions and cannot submit twice', async t => {
  let finish
  const h = setup(t, (url, options) => options?.method === 'PUT' ? new Promise(resolve => { finish = resolve }) : Promise.resolve({ data: [], meta: { lastPage: 1 } }), ['Update_Role', 'ViewAny_Role', 'View_User'])
  const state = useAdminRoles()
  state.roles.value = [{ id: 1, name: 'support', permissions: ['View_User'] }]
  await nextTick()
  const first = state.save()
  assert.equal(await state.save(), false)
  assert.deepEqual(h.calls[0].options.body, { permissions: ['View_User'] })
  finish({})
  assert.equal(await first, true)
})
test('system super admin permissions remain read only', async t => {
  const h = setup(t, async () => ({}), ['Update_Role'])
  const state = useAdminRoles()
  state.roles.value = [{ id: 1, name: 'super_admin', permissions: ['View_User'] }]
  await nextTick()
  assert.equal(await state.save(), false)
  assert.equal(h.calls.length, 0)
})
