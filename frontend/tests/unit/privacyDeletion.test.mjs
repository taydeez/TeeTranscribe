import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, effectScope, ref, watch } from 'vue'
import ts from 'typescript'

const source = await readFile(new URL('../../app/composables/usePrivacyDeletion.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const { usePrivacyDeletion } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)
const deletion = (status = 'pending') => ({ id: 'deletion-one', resourceType: 'transcription', resourceId: 'project-one', scope: 'project', category: null, status, failureReason: null })
const settle = async () => { for (let i = 0; i < 12; i++) await Promise.resolve() }

function harness(t, fetch) {
  const scope = effectScope(), target = ref({ resource_type: 'transcription', resource_id: 'project-one', scope: 'project' })
  const calls = [], accepted = [], completed = [], timers = new Map()
  const timeout = globalThis.setTimeout, clear = globalThis.clearTimeout
  let unmount
  globalThis.ref = ref; globalThis.computed = computed; globalThis.watch = watch
  globalThis.onBeforeUnmount = callback => { unmount = callback }
  globalThis.useAuthenticatedFetch = async (path, options) => { calls.push({ path, options }); return fetch(path, options) }
  globalThis.setTimeout = (callback, delay) => { const id = Symbol(); timers.set(id, { callback, delay }); return id }
  globalThis.clearTimeout = id => timers.delete(id)
  const state = scope.run(() => usePrivacyDeletion(() => target.value, item => accepted.push(item), item => completed.push(item)))
  t.after(() => { unmount(); scope.stop(); globalThis.setTimeout = timeout; globalThis.clearTimeout = clear })
  return { state, target, calls, accepted, completed, timers }
}

test('deletion requires explicit confirmation and repeated clicks send only one request', async t => {
  let finish
  const h = harness(t, async () => new Promise(resolve => { finish = resolve }))
  await h.state.confirm()
  assert.equal(h.calls.length, 0)
  h.state.open()
  const pending = h.state.confirm()
  await h.state.confirm()
  assert.equal(h.calls.length, 1)
  finish(deletion()); await pending
  assert.equal(h.accepted.length, 1)
  assert.equal(h.state.working.value, true)
  assert.equal([...h.timers.values()][0].delay, 5000)
})

test('completed deletion refreshes once and stops polling', async t => {
  const h = harness(t, async (_path, options) => options ? deletion() : deletion('completed'))
  h.state.open(); await h.state.confirm()
  const [id, timer] = [...h.timers][0]
  h.timers.delete(id); timer.callback(); await settle()
  assert.equal(h.completed.length, 1)
  assert.equal(h.state.working.value, false)
  assert.equal(h.timers.size, 0)
})

test('changing projects discards late deletion responses and clears confirmation', async t => {
  let finish
  const h = harness(t, async () => new Promise(resolve => { finish = resolve }))
  h.state.open(); const pending = h.state.confirm()
  h.target.value = { ...h.target.value, resource_id: 'project-two' }
  finish(deletion()); await pending
  assert.equal(h.accepted.length, 0)
  assert.equal(h.state.record.value, null)
  assert.equal(h.state.confirming.value, false)
  assert.equal(h.timers.size, 0)
})

test('failed cleanup retries the saved deletion rather than creating a fresh project request', async t => {
  const h = harness(t, async (path) => path.endsWith('/retry') ? deletion() : deletion('failed'))
  h.state.open(); await h.state.confirm()
  assert.equal(h.state.record.value.status, 'failed')
  assert.equal(h.timers.size, 0)
  await h.state.retry()
  assert.equal(h.calls[1].path, '/api/privacy/deletions/deletion-one/retry')
  assert.equal(h.state.record.value.status, 'pending')
})
