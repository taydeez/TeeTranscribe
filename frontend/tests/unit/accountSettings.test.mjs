import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, reactive, ref } from 'vue'
import ts from 'typescript'

const source = await readFile(new URL('../../app/composables/useAccountSettings.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const { useAccountSettings } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)

function harness(t, fetch) {
  const auth = reactive({ user: { name: 'Old Name' }, cleared: 0, refreshed: 0,
    clearSession() { this.cleared++ }, async refreshUser() { this.refreshed++; this.user.name = 'Updated Name' } })
  const navigations = []
  const globals = ['ref', 'computed', 'useAuthStore', 'useAuthenticatedFetch', 'navigateTo']
  const original = Object.fromEntries(globals.map(key => [key, globalThis[key]]))
  globalThis.ref = ref; globalThis.computed = computed; globalThis.useAuthStore = () => auth
  globalThis.useAuthenticatedFetch = fetch; globalThis.navigateTo = async url => { navigations.push(url) }
  t.after(() => { for (const key of globals) globalThis[key] = original[key] })
  return { auth, navigations, state: useAccountSettings() }
}

test('saving a name submits once and refreshes the shared profile', async t => {
  let finish
  const calls = []
  const h = harness(t, (path, options) => { calls.push({ path, options }); return new Promise(resolve => { finish = resolve }) })
  h.state.name.value = ' Updated Name '
  const pending = h.state.saveName(); await h.state.saveName()
  assert.equal(calls.length, 1)
  assert.equal(calls[0].options.method, 'PATCH')
  assert.deepEqual(calls[0].options.body, { name: 'Updated Name' })
  finish({ message: 'Name saved.' }); await pending
  assert.equal(h.auth.refreshed, 1)
  assert.equal(h.state.nameChanged.value, false)
  assert.equal(h.state.message.value, 'Name saved.')
})

test('a failed profile update preserves the edited name and shows the server error', async t => {
  const h = harness(t, async () => { throw { data: { message: 'Please try again.' } } })
  h.state.name.value = 'Unsaved Name'
  await h.state.saveName()
  assert.equal(h.state.name.value, 'Unsaved Name')
  assert.equal(h.state.error.value, 'Please try again.')
  assert.equal(h.auth.refreshed, 0)
})

test('successful password changes submit once then clear secrets and return to login', async t => {
  let finish, calls = 0
  const h = harness(t, () => { calls++; return new Promise(resolve => { finish = resolve }) })
  h.state.currentPassword.value = 'oldPassword1'; h.state.password.value = 'newPassword1'; h.state.passwordConfirmation.value = 'newPassword1'
  const pending = h.state.changePassword(); await h.state.changePassword()
  assert.equal(calls, 1)
  finish({ message: 'Password changed.' }); await pending
  assert.equal(h.auth.cleared, 1)
  assert.deepEqual(h.navigations, ['/?login=1&passwordChanged=1'])
  assert.equal(h.state.currentPassword.value, '')
  assert.equal(h.state.password.value, '')
  assert.equal(h.state.passwordConfirmation.value, '')
})

test('an incorrect current password leaves the session active and allows a retry', async t => {
  const h = harness(t, async () => { throw { data: { message: 'Your current password is incorrect.' } } })
  h.state.currentPassword.value = 'incorrect'; h.state.password.value = 'newPassword1'; h.state.passwordConfirmation.value = 'newPassword1'
  await h.state.changePassword()
  assert.equal(h.auth.cleared, 0)
  assert.equal(h.state.error.value, 'Your current password is incorrect.')
  assert.equal(h.state.busy.value, false)
  assert.deepEqual(h.navigations, [])
})
