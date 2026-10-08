import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { effectScope, reactive, ref, watch } from 'vue'
import ts from 'typescript'
const source = await readFile(new URL('../../app/composables/useDubbingWorkflow.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const { useDubbingWorkflow } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)
function harness(t, fetch) {
  const scope = effectScope(), route = reactive({ query: {} }), timers = new Map(), calls = []
  const timeout = globalThis.setTimeout, clear = globalThis.clearTimeout
  let unmount, uploads = 0, acknowledgements = 0
  globalThis.ref = ref; globalThis.watch = watch
  globalThis.useRoute = () => route
  globalThis.useRouter = () => ({ replace: async value => { route.query = value.query } })
  globalThis.onMounted = () => {}
  globalThis.onBeforeUnmount = fn => { unmount = fn }
  globalThis.useResumableUpload = () => ({
    upload: async () => { uploads++; return { audio_url: 'https://r2.example/video.mp4', audio_storage_path: 'audio/video.mp4' } },
    acknowledge: async () => { acknowledgements++ },
  })
  globalThis.useAuthenticatedFetch = async (path, options) => { calls.push({ path, options }); return fetch(path, options) }
  globalThis.setTimeout = (fn, delay) => { const id = Symbol(); timers.set(id, { fn, delay }); return id }
  globalThis.clearTimeout = id => timers.delete(id)
  t.after(() => { unmount(); scope.stop(); globalThis.setTimeout = timeout; globalThis.clearTimeout = clear })
  const state = scope.run(useDubbingWorkflow)
  state.file.value = { name: 'Video.mp4', size: 123 }; state.configured.value = true
  return { state, calls, timers, uploads: () => uploads, acknowledgements: () => acknowledgements, unmount: () => unmount() }
}
const quote = { id: 'quote', status: 'ready', enough_credits: true, credit_units: 1000, expires_at: '2099-01-01T00:00:00Z' }
const settle = async () => { for (let i = 0; i < 8; i++) await Promise.resolve() }
test('dubbing uploads to storage then quotes and submits only once after confirmation', async t => {
  let finish
  const h = harness(t, async path => path.endsWith('/quotes') ? quote : new Promise(resolve => { finish = resolve }))
  await h.state.checkPrice()
  assert.equal(h.uploads(), 1)
  assert.equal(h.calls.length, 1)
  assert.equal(h.calls[0].options.body.video_storage_path, 'audio/video.mp4')
  const submitted = h.state.submit(); await h.state.submit()
  assert.equal(h.calls.length, 2)
  finish({ id: 'dub', status: 'pending' }); await submitted
  assert.equal(h.acknowledgements(), 1)
  assert.equal([...h.timers.values()][0].delay, 5000)
})
test('lost confirmation responses retain the quote and uploaded video for an idempotent retry', async t => {
  let confirms = 0
  const h = harness(t, async path => {
    if (path.endsWith('/quotes')) return quote
    if (++confirms === 1) throw new Error('Offline')
    return { id: 'dub', status: 'complete' }
  })
  await h.state.checkPrice(); await h.state.submit()
  assert.equal(h.state.quote.value.id, 'quote')
  assert.equal(h.acknowledgements(), 0)
  await h.state.submit()
  assert.equal(h.uploads(), 1)
  assert.equal(h.acknowledgements(), 1)
  assert.deepEqual(h.calls.filter(item => item.path === '/api/dubbings').map(item => item.options.body.quote_id), ['quote', 'quote'])
})
test('dubbing inspection polling stops when ready and output polling stops when complete', async t => {
  const h = harness(t, async path => {
    if (path === '/api/dubbings/quotes') return { ...quote, status: 'measuring' }
    if (path.startsWith('/api/dubbings/quotes/')) return quote
    return { id: 'dub', status: path === '/api/dubbings' ? 'processing' : 'complete' }
  })
  await h.state.checkPrice()
  let [id, timer] = [...h.timers][0]
  assert.equal(timer.delay, 2000)
  h.timers.delete(id); timer.fn(); await settle()
  assert.equal(h.state.quote.value.status, 'ready'); assert.equal(h.timers.size, 0)
  await h.state.submit()
  ;[id, timer] = [...h.timers][0]
  h.timers.delete(id); timer.fn(); await settle()
  assert.equal(h.state.record.value.status, 'complete'); assert.equal(h.timers.size, 0)
})
test('changing dubbing language invalidates an in-flight quote and leaving clears pending checks', async t => {
  let finish
  const h = harness(t, () => new Promise(resolve => { finish = resolve }))
  const pending = h.state.checkPrice(); await settle()
  h.state.targetLanguage.value = 'fr'
  finish(quote); await pending
  assert.equal(h.state.quote.value, null)
  h.unmount(); assert.equal(h.timers.size, 0)
})
