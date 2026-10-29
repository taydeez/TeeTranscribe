import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { effectScope, ref, shallowRef, watch } from 'vue'
import ts from 'typescript'

const source = await readFile(new URL('../../app/composables/useActivityCancellation.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const { useActivityCancellation } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)
const cancelled = { id: 'task-one', status: 'failed', cancelledAt: '2026-10-21T12:00:00Z', failureReason: 'Cancelled by you.' }

function harness(t, fetch) {
  const scope = effectScope()
  const task = ref({ type: 'transcription', id: 'task-one' })
  const calls = [], changed = []
  let unmount
  globalThis.shallowRef = shallowRef; globalThis.watch = watch
  globalThis.onBeforeUnmount = fn => { unmount = fn }
  globalThis.useAuthenticatedFetch = async (path, options) => { calls.push({ path, options }); return fetch(path, options) }
  const state = scope.run(() => useActivityCancellation(() => task.value, result => changed.push(result)))
  t.after(() => { unmount(); scope.stop() })
  return { state, task, calls, changed, unmount: () => unmount() }
}

test('opening confirmation does not cancel and repeated confirm clicks send one request', async t => {
  let finish
  const h = harness(t, () => new Promise(resolve => { finish = resolve }))
  h.state.confirming.value = true
  assert.equal(h.calls.length, 0)
  const pending = h.state.confirm()
  await h.state.confirm()
  assert.equal(h.calls.length, 1)
  assert.equal(h.calls[0].path, '/api/activities/transcription/task-one/cancel')
  assert.equal(h.state.busy.value, true)
  finish(cancelled); await pending
  assert.equal(h.state.confirming.value, false)
  assert.equal(h.state.busy.value, false)
  assert.deepEqual(h.changed, [cancelled])
})

test('failed cancellation preserves confirmation and can be retried', async t => {
  let failed = true
  const h = harness(t, async () => { if (failed) throw { data: { message: 'Temporarily unavailable' } }; return cancelled })
  h.state.confirming.value = true
  await h.state.confirm()
  assert.equal(h.state.confirming.value, true)
  assert.equal(h.state.busy.value, false)
  assert.equal(h.state.error.value, 'Temporarily unavailable')
  assert.equal(h.changed.length, 0)
  failed = false
  await h.state.confirm()
  assert.equal(h.state.error.value, '')
  assert.equal(h.changed.length, 1)
})

test('late cancellation responses do not change a different selected task or an unmounted view', async t => {
  let finish
  const h = harness(t, () => new Promise(resolve => { finish = resolve }))
  const pending = h.state.confirm()
  h.task.value = { type: 'dubbing', id: 'task-two' }
  finish(cancelled); await pending
  assert.equal(h.changed.length, 0)
  const next = h.state.confirm()
  assert.equal(h.calls[1].path, '/api/activities/dubbing/task-two/cancel')
  h.unmount()
  finish(cancelled); await next
  assert.equal(h.changed.length, 0)
})

test('completion winning the race refreshes the actual completed state', async t => {
  const completed = { ...cancelled, status: 'complete', cancelledAt: null, failureReason: null }
  const h = harness(t, async () => completed)
  await h.state.confirm()
  assert.deepEqual(h.changed, [completed])
})
