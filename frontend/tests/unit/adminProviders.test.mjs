import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, reactive, ref, watch } from 'vue'
import ts from 'typescript'
const source = await readFile(new URL('../../app/composables/useAdminProviders.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const { useAdminProviders } = await import('data:text/javascript;base64,' + Buffer.from(compiled).toString('base64'))
const record = () => ({ activity: 'translation', name: 'Translation', version: 2, configuration: { default_provider: 'google', providers: { google: { enabled: true, model: 'nmt', languages: [] } }, language_rules: {} }, catalog: {}, history: [] })
function setup(t, fetch, permissions = ['ViewAny_AIProvider', 'Update_AIProvider']) {
  const calls = [], stops = []
  const route = reactive({ query: { activity: 'translation' } })
  const globals = { ref, computed, watch: (...args) => { const stop = watch(...args); stops.push(stop); return stop },
    useRoute: () => route, useRouter: () => ({ replace: async value => { route.query = value.query } }),
    useAuthStore: () => ({ user: { permissions } }), onMounted: () => {},
    useAuthenticatedFetch: async (url, options) => { calls.push({ url, options }); return fetch(url, options) } }
  const originals = Object.fromEntries(Object.keys(globals).map(key => [key, globalThis[key]]))
  Object.assign(globalThis, globals)
  t.after(() => { stops.forEach(stop => stop()); Object.assign(globalThis, originals) })
  return { calls, state: useAdminProviders() }
}
test('provider settings require view permission and read only admins cannot save', async t => {
  const h = setup(t, async () => ({ data: [] }), [])
  await h.state.load(); await h.state.save()
  assert.equal(h.calls.length, 0)
})
test('provider saves send the current version and reason and block duplicate requests', async t => {
  let finish
  const h = setup(t, () => new Promise(resolve => { finish = resolve }))
  h.state.activities.value = [record()]
  h.state.draft.value.providers.google.enabled = false
  assert.equal(h.state.activities.value[0].configuration.providers.google.enabled, true)
  h.state.reason.value = '  Pause translation  '
  const first = h.state.save()
  await h.state.save()
  assert.equal(h.calls.length, 1)
  assert.equal(h.calls[0].options.body.version, 2)
  assert.equal(h.calls[0].options.body.reason, 'Pause translation')
  finish({ data: { ...record(), version: 3, configuration: h.state.draft.value } })
  await first
  assert.equal(h.state.selected.value.version, 3)
  assert.equal(h.state.busy.value, false)
  assert.match(h.state.success.value, /Settings saved/)
})
test('a stale configuration conflict keeps unsaved edits and the server message', async t => {
  const h = setup(t, async () => { throw { data: { message: 'Refresh before saving.' } } })
  h.state.activities.value = [record()]
  h.state.draft.value.providers.google.enabled = false
  h.state.reason.value = 'Pause'
  await h.state.save()
  assert.equal(h.state.error.value, 'Refresh before saving.')
  assert.equal(h.state.draft.value.providers.google.enabled, false)
  assert.equal(h.state.selected.value.version, 2)
  assert.equal(h.state.busy.value, false)
})

test('model catalog and per-language model choices stay local until an audited save', async t => {
  const original = record()
  original.configuration.models = { google: [{ id: 'nmt', label: 'Provider default', enabled: true, languages: [], capabilities: { speakers: false, timestamps: false },
    pricing: { unit: '1000_characters', credits: '10', provider_cost: '0.002', provider_currency: 'USD' } }] }
  const h = setup(t, async (_url, options) => ({ data: { ...original, version: 3, configuration: JSON.parse(JSON.stringify(options.body.configuration)) } }))
  h.state.activities.value = [original]
  h.state.draft.value.models.google[0].pricing.credits = '15'
  h.state.draft.value.language_rules.yo = { provider: 'google', model: 'nmt' }
  h.state.reason.value = 'Adjust Yoruba translation pricing'
  assert.equal(original.configuration.models.google[0].pricing.credits, '10')
  await h.state.save()
  assert.equal(h.calls[0].options.body.configuration.models.google[0].pricing.credits, '15')
  assert.deepEqual(h.calls[0].options.body.configuration.language_rules.yo, { provider: 'google', model: 'nmt' })
  assert.equal(h.state.selected.value.version, 3)
  assert.equal(h.state.selected.value.configuration.models.google[0].pricing.credits, '15')
})
