import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, ref } from 'vue'
import ts from 'typescript'
const source = await readFile(new URL('../../app/composables/useAdminBilling.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const { useAdminBilling } = await import('data:text/javascript;base64,' + Buffer.from(compiled).toString('base64'))
const record = () => ({ version: 2, configuration: { packages: [{ code: 'starter', name: 'Starter', credits: '5000', price_ngn: '5000', enabled: true }], free_credits: '0' }, history: [] })
function setup(t, fetch, permissions = ['ViewAny_Pricing', 'Update_Pricing']) {
  const calls = []
  const globals = { ref, computed, onMounted: () => {}, useAuthStore: () => ({ user: { permissions } }),
    useAuthenticatedFetch: async (url, options) => { calls.push({ url, options }); return fetch(url, options) } }
  const originals = Object.fromEntries(Object.keys(globals).map(key => [key, globalThis[key]]))
  Object.assign(globalThis, globals)
  t.after(() => Object.assign(globalThis, originals))
  return { calls, state: useAdminBilling() }
}
test('billing settings are not requested without permission', async t => {
  const h = setup(t, async () => ({ data: record() }), [])
  await h.state.load(); await h.state.save()
  assert.equal(h.calls.length, 0)
})

test('provider deadlines are submitted with the billing version and edit reason', async t => {
  const h = setup(t, async (_url, options) => options ? { data: { ...record(), version: 3, configuration: options.body.configuration } } : { data: record() })
  await h.state.load()
  h.state.draft.value.task_deadlines = { transcription: { deepgram: { base_minutes: 5, duration_multiplier: 1.5, max_minutes: 180 } } }
  h.state.reason.value = 'Shorten stalled transcription deadlines'
  await h.state.save()
  assert.deepEqual(h.calls[1].options.body.configuration.task_deadlines.transcription.deepgram, { base_minutes: 5, duration_multiplier: 1.5, max_minutes: 180 })
  assert.equal(h.calls[1].options.body.version, 2)
  assert.equal(h.state.dirty.value, false)
})
test('read only admins can load settings but cannot save changes', async t => {
  const h = setup(t, async () => ({ data: record() }), ['ViewAny_Pricing'])
  await h.state.load()
  h.state.draft.value.free_credits = '50'
  h.state.reason.value = 'Welcome offer'
  await h.state.save()
  assert.equal(h.calls.length, 1)
  assert.equal(h.state.record.value.configuration.free_credits, '0')
  assert.equal(h.state.dirty.value, true)
})
test('billing saves include version and reason and reject duplicate submissions', async t => {
  let finish
  const h = setup(t, async (_url, options) => options ? new Promise(resolve => { finish = resolve }) : { data: record() })
  await h.state.load()
  h.state.draft.value.packages[0].credits = '5500'
  h.state.reason.value = '  Bonus package  '
  const first = h.state.save()
  await h.state.save()
  assert.equal(h.calls.length, 2)
  assert.equal(h.calls[1].options.body.version, 2)
  assert.equal(h.calls[1].options.body.reason, 'Bonus package')
  assert.equal(h.calls[1].options.body.configuration.packages[0].credits, '5500')
  finish({ data: { ...record(), version: 3, configuration: h.calls[1].options.body.configuration } })
  await first
  assert.equal(h.state.record.value.version, 3)
  assert.equal(h.state.busy.value, false)
  assert.equal(h.state.dirty.value, false)
  assert.match(h.state.success.value, /Billing settings saved/)
})
test('a conflicting billing save preserves edits until reset or reload', async t => {
  const h = setup(t, async (_url, options) => { if (options) throw { data: { message: 'Billing settings changed. Refresh before saving.' } }; return { data: record() } })
  await h.state.load()
  h.state.draft.value.free_credits = '25'
  h.state.reason.value = 'Welcome offer'
  await h.state.save()
  assert.match(h.state.error.value, /Refresh before saving/)
  assert.equal(h.state.draft.value.free_credits, '25')
  assert.equal(h.state.record.value.version, 2)
  assert.equal(h.state.busy.value, false)
  h.state.reset()
  assert.equal(h.state.draft.value.free_credits, '0')
  assert.equal(h.state.dirty.value, false)
})
test('billing load failures release loading state and permit retry', async t => {
  let fail = true
  const h = setup(t, async () => { if (fail) throw { data: { message: 'The server is unavailable.' } }; return { data: record() } })
  await h.state.load()
  assert.equal(h.state.loading.value, false)
  assert.equal(h.state.draft.value, null)
  assert.match(h.state.error.value, /unavailable/)
  fail = false
  await h.state.load()
  assert.equal(h.state.record.value.version, 2)
  assert.equal(h.state.error.value, '')
})

test('concurrency limit edits are submitted with the version and remain editable after a failed save', async t => {
  const saved = { ...record(), configuration: { ...record().configuration, concurrency: { overall: 3, transcription: 2, translation: 3, dubbing: 1, transcript_tools: 2 } } }
  const h = setup(t, async (_url, options) => { if (options) throw { data: { message: 'Please refresh before saving.' } }; return { data: saved } })
  await h.state.load()
  h.state.draft.value.concurrency.overall = 5
  h.state.reason.value = 'Increase customer capacity'
  await h.state.save()
  assert.equal(h.calls[1].options.body.configuration.concurrency.overall, 5)
  assert.equal(h.calls[1].options.body.version, 2)
  assert.equal(h.state.record.value.configuration.concurrency.overall, 3)
  assert.equal(h.state.draft.value.concurrency.overall, 5)
  assert.equal(h.state.busy.value, false)
  h.state.reset()
  assert.equal(h.state.draft.value.concurrency.overall, 3)
})
