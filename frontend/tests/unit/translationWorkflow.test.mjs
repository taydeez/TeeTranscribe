import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { effectScope, reactive, ref, watch } from 'vue'
import ts from 'typescript'

const source = await readFile(new URL('../../app/composables/useTranslationWorkflow.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const { useTranslationWorkflow } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)

function harness(t, fetch) {
  const scope = effectScope()
  const draft = reactive({ text: 'Hello', sourceName: 'Interview', transcriptionId: '', segments: [] })
  const route = reactive({ query: {} })
  const timers = new Map()
  const timeout = globalThis.setTimeout, clear = globalThis.clearTimeout
  let unmount
  globalThis.ref = ref; globalThis.watch = watch
  globalThis.useTranslationDraftStore = () => draft
  globalThis.useRoute = () => route
  globalThis.useRouter = () => ({ replace: async value => { route.query = value.query } })
  globalThis.onMounted = () => {}
  globalThis.onBeforeUnmount = fn => { unmount = fn }
  globalThis.useAuthenticatedFetch = fetch
  globalThis.setTimeout = (fn, delay) => { const id = Symbol(); timers.set(id, { fn, delay }); return id }
  globalThis.clearTimeout = id => timers.delete(id)
  t.after(() => { unmount(); scope.stop(); globalThis.setTimeout = timeout; globalThis.clearTimeout = clear })
  return { state: scope.run(useTranslationWorkflow), draft, timers, unmount: () => unmount() }
}
const quote = { id: 'quote', enough_credits: true, expires_at: '2099-01-01T00:00:00Z' }
const settle = async () => { for (let i = 0; i < 6; i++) await Promise.resolve() }

test('translation quotes include the chosen folder and changing it invalidates an in flight quote', async t => {
  const calls = []
  let finish
  const h = harness(t, async (path, options) => { calls.push({ path, options }); return new Promise(resolve => { finish = resolve }) })
  h.state.folderId.value = 'folder-one'
  const pending = h.state.checkPrice()
  assert.equal(calls[0].options.body.folder_id, 'folder-one')
  h.state.folderId.value = 'folder-two'
  finish(quote); await pending
  assert.equal(h.state.quote.value, null)
  const next = h.state.checkPrice()
  assert.equal(calls[1].options.body.folder_id, 'folder-two')
  assert.notEqual(calls[1].options.body.client_key, calls[0].options.body.client_key)
  finish(quote); await next
  assert.equal(h.state.quote.value.id, 'quote')
})

test('translation quotes require confirmation and rapid confirm clicks submit once', async t => {
  const calls = []
  let finish
  const h = harness(t, async (path, options) => {
    calls.push({ path, options })
    if (path.endsWith('/quotes')) return quote
    return new Promise(resolve => { finish = resolve })
  })
  await h.state.checkPrice()
  assert.equal(calls.length, 1)
  const submitted = h.state.submit()
  await h.state.submit()
  assert.equal(calls.length, 2)
  assert.deepEqual(calls[1].options.body, { quote_id: 'quote' })
  finish({ id: 'translation', status: 'pending' }); await submitted
  assert.equal([...h.timers.values()][0].delay, 5000)
})

test('changing text while a price request is in flight discards the stale quote', async t => {
  let finish
  const h = harness(t, () => new Promise(resolve => { finish = resolve }))
  const pending = h.state.checkPrice()
  h.draft.text = 'Different text'
  finish(quote); await pending
  assert.equal(h.state.quote.value, null)
})

test('polling stops after completion and leaving the page clears scheduled work', async t => {
  const h = harness(t, async path => path.endsWith('/quotes') ? quote : ({ id: 'translation', status: path === '/api/translations' ? 'processing' : 'complete' }))
  await h.state.checkPrice(); await h.state.submit()
  const [id, timer] = [...h.timers][0]
  h.timers.delete(id); timer.fn(); await settle()
  assert.equal(h.state.record.value.status, 'complete')
  assert.equal(h.timers.size, 0)
  h.unmount()
})
