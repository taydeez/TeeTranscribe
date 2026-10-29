import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { ref } from 'vue'
import ts from 'typescript'

const source = await readFile(new URL('../../app/composables/useFolderTranscriptions.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const { useFolderTranscriptions } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)

function harness(t, responses) {
  const timers = new Map()
  let mounted, unmount, routeChanged, statusChanged
  const originalTimeout = globalThis.setTimeout
  const originalClear = globalThis.clearTimeout
  t.after(() => { globalThis.setTimeout = originalTimeout; globalThis.clearTimeout = originalClear })
  globalThis.ref = ref
  globalThis.onMounted = fn => { mounted = fn }
  globalThis.onBeforeUnmount = fn => { unmount = fn }
  globalThis.watch = (_, fn) => { if (!statusChanged) statusChanged = fn; else routeChanged = fn }
  globalThis.setTimeout = (fn, delay) => { const id = Symbol(); timers.set(id, { fn, delay }); return id }
  globalThis.clearTimeout = id => timers.delete(id)
  const calls = []
  globalThis.useAuthenticatedFetch = async (path, options) => {
    calls.push({ path, signal: options.signal })
    const response = responses.shift()
    if (response instanceof Error) throw response
    return response
  }
  let id = 'folder-one'
  const state = useFolderTranscriptions(() => id)
  return { state, calls, timers, mount: () => mounted(), unmount: () => unmount(), change: value => { id = value; routeChanged() }, statusChanged: value => statusChanged(value) }
}

const folder = status => ({ id: 'folder-one', name: 'Interviews', transcriptions: [{ id: 'transcription-one', status }] })
const settle = async () => { await Promise.resolve(); await Promise.resolve() }

test('folders containing only translations and dubbed media poll until all projects finish', async t => {
  const h = harness(t, [
    { id: 'folder-one', transcriptions: [], projects: [{ id: 'translation', status: 'pending' }, { id: 'dub', status: 'processing' }] },
    { id: 'folder-one', transcriptions: [], projects: [{ id: 'translation', status: 'complete' }, { id: 'dub', status: 'processing' }] },
    { id: 'folder-one', transcriptions: [], projects: [{ id: 'translation', status: 'complete' }, { id: 'dub', status: 'failed' }] },
  ])
  h.mount(); await settle()
  assert.equal(h.timers.size, 1)
  for (let i = 0; i < 2; i++) {
    const [id, timer] = [...h.timers][0]
    assert.equal(timer.delay, 15000)
    h.timers.delete(id); timer.fn(); await settle()
  }
  assert.equal(h.calls.length, 3)
  assert.equal(h.timers.size, 0)
  h.unmount()
})

test('polls pending and processing items every 15 seconds without hiding the list and stops when complete', async t => {
  const h = harness(t, [folder('pending'), folder('processing'), folder('complete')])
  h.mount(); await settle()
  assert.equal(h.calls.length, 1)
  assert.equal(h.state.loading.value, false)
  const [id, timer] = [...h.timers][0]
  assert.equal(timer.delay, 15000)
  h.timers.delete(id); timer.fn()
  assert.equal(h.state.loading.value, false)
  await settle()
  assert.equal(h.calls.length, 2)
  assert.equal(h.state.folder.value.transcriptions[0].status, 'processing')
  const [nextId, nextTimer] = [...h.timers][0]
  assert.equal(nextTimer.delay, 15000)
  h.timers.delete(nextId); nextTimer.fn()
  await settle()
  assert.equal(h.calls.length, 3)
  assert.equal(h.state.folder.value.transcriptions[0].status, 'complete')
  assert.equal(h.timers.size, 0)
})

test('retries a temporary polling failure and cleans up on leaving the folder', async t => {
  const h = harness(t, [folder('pending'), new Error('Offline')])
  h.mount(); await settle()
  const [id, timer] = [...h.timers][0]
  h.timers.delete(id)
  timer.fn(); await settle()
  assert.equal(h.state.folder.value.transcriptions[0].status, 'pending')
  assert.equal(h.timers.size, 1)
  assert.equal(h.state.error.value, 'Could not load this folder.')
  h.unmount()
  assert.equal(h.timers.size, 0)
  assert.equal(h.calls[1].signal.aborted, true)
})

test('changing folders cancels the old poll and completed folders do not poll', async t => {
  const h = harness(t, [folder('pending'), folder('complete')])
  h.mount(); await settle()
  h.change('folder-two'); await settle()
  assert.equal(h.calls[0].signal.aborted, true)
  assert.equal(h.calls[1].path, '/api/folders/folder-two')
  assert.equal(h.timers.size, 0)
})

test('editing a completed transcript restarts polling when its status becomes processing', async t => {
  const h = harness(t, [folder('complete')])
  h.mount(); await settle()
  assert.equal(h.timers.size, 0)
  h.state.folder.value.transcriptions[0].status = 'processing'
  h.statusChanged(true)
  assert.equal(h.timers.size, 1)
  assert.equal([...h.timers.values()][0].delay, 15000)
  h.unmount()
})
