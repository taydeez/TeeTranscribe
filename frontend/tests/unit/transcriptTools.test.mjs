import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, effectScope, ref, watch } from 'vue'
import ts from 'typescript'

const source = await readFile(new URL('../../app/composables/useTranscriptTools.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const { useTranscriptTools } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)
const quote = { id: 'quote-one', status: 'ready', quantity: 20, credit_units: 200, expires_at: '2099-01-01T00:00:00Z', available_units: 1000, enough_credits: true }
const record = (overrides = {}) => ({ id: 'tool-one', transcriptionId: 'transcript-one', operation: 'cleanup', status: 'complete', stale: false, source: { text: overrides.stale ? 'Older text' : 'hello world', segments: [] }, result: { text: 'Hello, world.' }, failureReason: null, createdAt: null, ...overrides })
const settle = async () => { for (let i = 0; i < 12; i++) await Promise.resolve() }

function harness(t, fetch) {
  const scope = effectScope()
  const transcription = ref({ id: 'transcript-one', transcript: 'hello world', segments: [] })
  const blocked = ref(false)
  const draft = ref({ text: 'hello world', segments: [] })
  const calls = []
  const timers = new Map()
  const timeout = globalThis.setTimeout, clear = globalThis.clearTimeout
  let mount, unmount
  globalThis.ref = ref; globalThis.computed = computed; globalThis.watch = watch
  globalThis.onMounted = fn => { mount = fn }
  globalThis.onBeforeUnmount = fn => { unmount = fn }
  globalThis.useAuthenticatedFetch = async (path, options) => { calls.push({ path, options }); return fetch(path, options) }
  globalThis.setTimeout = (fn, delay) => { const id = Symbol(); timers.set(id, { fn, delay }); return id }
  globalThis.clearTimeout = id => timers.delete(id)
  const state = scope.run(() => useTranscriptTools(() => transcription.value, () => blocked.value, () => draft.value))
  scope.run(mount)
  t.after(() => { unmount(); scope.stop(); globalThis.setTimeout = timeout; globalThis.clearTimeout = clear })
  const poll = async () => { const [id, timer] = [...timers][0]; timers.delete(id); timer.fn(); await settle() }
  return { state, transcription, draft, blocked, timers, calls, poll, unmount: () => unmount() }
}

test('saved cleanup and summary results can be reopened without requesting another paid operation', async t => {
  const h = harness(t, async () => ({ configured: true, data: [record(), record({ id: 'summary-one', operation: 'summary', result: { summary: 'A greeting.', keyPoints: ['Said hello'], actionItems: [] } })] }))
  await settle()
  assert.equal(h.state.canApply.value, true)
  await h.state.checkPrice()
  h.state.select('summary')
  await h.state.checkPrice()
  assert.equal(h.state.record.value.id, 'summary-one')
  assert.equal(h.state.canApply.value, false)
  assert.equal(h.calls.length, 1)
  assert.equal(h.timers.size, 0)
})

test('active requests for older drafts and other tool operations remain available to cancel', async t => {
  const h = harness(t, async () => ({ configured: true, data: [
    record({ id: 'old-cleanup', stale: true, status: 'processing', result: null }),
    record({ id: 'summary-one', operation: 'summary', status: 'pending', result: null }),
    record(),
  ] }))
  await settle()
  assert.equal(h.state.record.value.id, 'tool-one')
  assert.equal(h.state.canApply.value, true)
  assert.deepEqual(h.state.pendingRecords.value.map(item => item.id), ['old-cleanup', 'summary-one'])
})

test('saving blocks requests and discards an in-flight price quote', async t => {
  let finish
  const h = harness(t, async (path, options) => options ? new Promise(resolve => { finish = resolve }) : ({ configured: true, data: [] }))
  await settle()
  h.blocked.value = true
  await h.state.checkPrice()
  assert.equal(h.calls.length, 1)
  h.blocked.value = false
  const pending = h.state.checkPrice()
  const firstKey = h.calls[1].options.body.client_key
  h.blocked.value = true
  finish(quote); await pending
  assert.equal(h.state.quote.value, null)
  h.blocked.value = false
  const next = h.state.checkPrice()
  assert.notEqual(h.calls[2].options.body.client_key, firstKey)
  finish(quote); await next
  assert.equal(h.state.quote.value.id, quote.id)
})

test('unsaved editor text is quoted and generated without saving, including while polling', async t => {
  const snapshot = { text: 'my unsaved changes', segments: [] }
  const h = harness(t, async (path, options) => {
    if (path.endsWith('/quotes')) return quote
    if (path.endsWith('/tool-one')) return record({ stale: true, source: snapshot })
    if (options?.method === 'POST') return record({ stale: true, source: snapshot, status: 'processing', result: null })
    return { configured: true, data: [] }
  })
  await settle()
  h.draft.value = snapshot
  await h.state.checkPrice()
  assert.deepEqual(h.calls[1].options.body.draft, { transcript: snapshot.text, segments: [] })
  assert.equal(h.transcription.value.transcript, 'hello world')
  await h.state.confirm()
  assert.equal(h.state.working.value, true)
  await h.poll()
  assert.equal(h.state.canApply.value, true)
  assert.equal(h.state.record.value.status, 'complete')
  assert.equal(h.calls.some(call => call.options?.method === 'PATCH'), false)
})

test('typing invalidates a quote and ignores its late response without fetching history per keystroke', async t => {
  let finish
  const h = harness(t, async (path, options) => options ? new Promise(resolve => { finish = resolve }) : ({ configured: true, data: [] }))
  await settle()
  const pending = h.state.checkPrice()
  const firstKey = h.calls[1].options.body.client_key
  h.draft.value.text = 'Updated before the price returned'
  finish(quote); await pending
  assert.equal(h.state.quote.value, null)
  assert.equal(h.state.busy.value, false)
  assert.equal(h.calls.length, 2)
  const next = h.state.checkPrice()
  assert.notEqual(h.calls[2].options.body.client_key, firstKey)
  assert.equal(h.calls[2].options.body.draft.transcript, h.draft.value.text)
  finish(quote); await next
  assert.equal(h.state.quote.value.id, quote.id)
})

test('editing after generation makes cleanup stale until the matching draft is restored', async t => {
  const h = harness(t, async () => ({ configured: true, data: [record()] }))
  await settle()
  h.draft.value.text = 'New edits'
  assert.equal(h.state.canApply.value, false)
  h.draft.value.text = 'hello world'
  assert.equal(h.state.canApply.value, true)
})

test('price quotes require confirmation, rapid clicks submit once, and polling stops on completion', async t => {
  let finish
  const h = harness(t, async (path, options) => {
    if (path.endsWith('/quotes')) return quote
    if (options?.method === 'POST') return new Promise(resolve => { finish = resolve })
    if (path.endsWith('/tool-one')) return record()
    return { configured: true, data: [] }
  })
  await settle(); await h.state.checkPrice()
  assert.equal(h.calls.length, 2)
  const pending = h.state.confirm()
  await h.state.confirm()
  assert.equal(h.calls.length, 3)
  assert.deepEqual(h.calls[2].options.body, { quote_id: quote.id })
  finish(record({ status: 'processing', result: null })); await pending
  assert.equal([...h.timers.values()][0].delay, 5000)
  await h.poll()
  assert.equal(h.state.record.value.status, 'complete')
  assert.equal(h.state.canApply.value, true)
  assert.equal(h.timers.size, 0)
})

test('typing while confirmation is in flight keeps the accepted job visible but prevents applying cleanup', async t => {
  let finish
  const h = harness(t, async (path, options) => path.endsWith('/quotes') ? quote : options ? new Promise(resolve => { finish = resolve }) : ({ configured: true, data: [] }))
  await settle(); await h.state.checkPrice()
  const pending = h.state.confirm()
  h.blocked.value = true
  assert.equal(h.state.busy.value, true)
  finish(record()); await pending
  assert.equal(h.state.record.value.id, 'tool-one')
  assert.equal(h.state.canApply.value, false)
  h.blocked.value = false
  assert.equal(h.state.canApply.value, true)
})

test('changing transcripts discards an older poll response and cancels its scheduled work', async t => {
  let finish
  const h = harness(t, async path => path.endsWith('/tool-one') ? new Promise(resolve => { finish = resolve }) : ({ configured: true, data: path.includes('transcript-one') ? [record({ status: 'processing', result: null })] : [] }))
  await settle()
  const [id, timer] = [...h.timers][0]
  h.timers.delete(id); timer.fn(); await settle()
  h.transcription.value = { id: 'transcript-two', transcript: 'Another recording.', segments: [] }
  await settle()
  finish(record()); await settle()
  assert.equal(h.state.record.value, null)
  assert.equal(h.timers.size, 0)
  assert.equal(h.state.canApply.value, false)
})

test('stale results stay reviewable but require a fresh quote and cannot be applied', async t => {
  const h = harness(t, async (path, options) => options ? quote : ({ configured: true, data: [record({ stale: true })] }))
  await settle()
  assert.equal(h.state.record.value.result.text, 'Hello, world.')
  assert.equal(h.state.fresh.value, false)
  assert.equal(h.state.canApply.value, false)
  await h.state.checkPrice()
  assert.equal(h.state.quote.value.id, quote.id)
})

test('a job for an older saved transcript does not block quoting the new saved version', async t => {
  const h = harness(t, async (path, options) => options ? quote : ({ configured: true, data: [record({ stale: true, status: 'processing', result: null })] }))
  await settle()
  assert.equal(h.state.working.value, false)
  assert.equal(h.timers.size, 0)
  await h.state.checkPrice()
  assert.equal(h.state.quote.value.id, quote.id)
})

test('expired quotes never submit and failed jobs stop polling', async t => {
  const h = harness(t, async (path, options) => path.endsWith('/quotes') ? { ...quote, expires_at: '2000-01-01T00:00:00Z' } : options ? record() : ({ configured: true, data: [record({ status: 'failed', result: null, failureReason: 'Service unavailable.' })] }))
  await settle()
  assert.equal(h.timers.size, 0)
  await h.state.checkPrice(); await h.state.confirm()
  assert.equal(h.calls.length, 2)
  assert.equal(h.state.quote.value, null)
  assert.match(h.state.error.value, /expired/)
})

test('closing the transcript stops polling and prevents late responses from changing the panel', async t => {
  let finish
  const h = harness(t, async path => path.endsWith('/tool-one') ? new Promise(resolve => { finish = resolve }) : ({ configured: true, data: [record({ status: 'pending', result: null })] }))
  await settle()
  const [id, timer] = [...h.timers][0]
  h.timers.delete(id); timer.fn(); await settle()
  h.unmount()
  finish(record()); await settle()
  assert.equal(h.state.record.value.status, 'pending')
  assert.equal(h.timers.size, 0)
})
