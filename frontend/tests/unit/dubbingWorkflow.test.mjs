import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { effectScope, reactive, ref, watch } from 'vue'
import ts from 'typescript'
const source = await readFile(new URL('../../app/composables/useDubbingWorkflow.ts', import.meta.url), 'utf8')
const configSource = await readFile(new URL('../../app/config/dubbing.ts', import.meta.url), 'utf8')
const configCompiled = ts.transpileModule(configSource, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const configUrl = `data:text/javascript;base64,${Buffer.from(configCompiled).toString('base64')}`
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText.replace("from '~/config/dubbing'", `from '${configUrl}'`)
const { useDubbingWorkflow } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)
function harness(t, fetch) {
  const scope = effectScope(), route = reactive({ query: {} }), timers = new Map(), calls = []
  const timeout = globalThis.setTimeout, clear = globalThis.clearTimeout
  let unmount, uploads = 0, acknowledgements = 0
  const uploadInputs = []
  globalThis.ref = ref; globalThis.watch = watch
  globalThis.useRoute = () => route
  globalThis.useRouter = () => ({ replace: async value => { route.query = value.query } })
  globalThis.onMounted = () => {}
  globalThis.onBeforeUnmount = fn => { unmount = fn }
  globalThis.useResumableUpload = () => ({
    upload: async (file, contentType) => { uploads++; uploadInputs.push({ file, contentType }); return { audio_url: 'https://r2.example/video.mp4', audio_storage_path: 'audio/video.mp4' } },
    acknowledge: async () => { acknowledgements++ },
  })
  globalThis.useAuthenticatedFetch = async (path, options) => { calls.push({ path, options }); return fetch(path, options) }
  globalThis.setTimeout = (fn, delay) => { const id = Symbol(); timers.set(id, { fn, delay }); return id }
  globalThis.clearTimeout = id => timers.delete(id)
  t.after(() => { unmount(); scope.stop(); globalThis.setTimeout = timeout; globalThis.clearTimeout = clear })
  const state = scope.run(useDubbingWorkflow)
  state.file.value = { name: 'Video.mp4', size: 123 }; state.configured.value = true
  return { state, calls, timers, uploadInputs, uploads: () => uploads, acknowledgements: () => acknowledgements, unmount: () => unmount() }
}
const quote = { id: 'quote', status: 'ready', enough_credits: true, credit_units: 1000, expires_at: '2099-01-01T00:00:00Z' }
const settle = async () => { for (let i = 0; i < 8; i++) await Promise.resolve() }

test('subtitles only uses its own languages and pricing when dubbing is disabled', async t => {
  const h = harness(t, async path => path.endsWith('/languages') ? {
    data: [], sourceLanguages: [], configured: false,
    subtitlesOnly: { configured: true, data: [{ code: 'es', name: 'Spanish', nigerian: false }], sourceLanguages: [{ code: 'en', name: 'English', nigerian: false }] },
  } : quote)
  await h.state.loadLanguages()
  assert.equal(h.state.configured.value, false)
  h.state.operation.value = 'subtitles'
  assert.equal(h.state.configured.value, true)
  assert.equal(h.state.targetLanguage.value, 'es')
  assert.equal(h.state.subtitlesEnabled.value, true)
  h.state.sourceLanguage.value = 'en'; h.state.subtitleStyle.value = 'contrast'
  await h.state.checkPrice()
  assert.deepEqual(h.calls[1].options.body, {
    client_key: h.calls[1].options.body.client_key, operation: 'subtitles', media_type: 'video', folder_id: null, video_storage_path: 'audio/video.mp4', name: null,
    source_language: 'en', target_language: 'es', subtitles_enabled: true, subtitle_style: 'contrast',
  })
})

test('changing a dubbing folder requires a new quote without uploading the file again', async t => {
  const h = harness(t, async () => quote)
  h.state.folderId.value = 'folder-one'
  await h.state.checkPrice()
  const firstKey = h.calls[0].options.body.client_key
  assert.equal(h.calls[0].options.body.folder_id, 'folder-one')
  h.state.folderId.value = 'folder-two'
  assert.equal(h.state.quote.value, null)
  await h.state.checkPrice()
  assert.equal(h.calls[1].options.body.folder_id, 'folder-two')
  assert.notEqual(h.calls[1].options.body.client_key, firstKey)
  assert.equal(h.uploads(), 1)
})

test('audio dubbing uses its own catalog and uploads audio before a single confirmed submission', async t => {
  let confirm
  const h = harness(t, async path => {
    if (path.endsWith('/languages')) return { configured: false, data: [], sourceLanguages: [],
      audioDubbing: { configured: true, data: [{ code: 'es', name: 'Spanish', nigerian: false }], sourceLanguages: [{ code: 'en', name: 'English', nigerian: false }] } }
    if (path.endsWith('/quotes')) return quote
    return new Promise(resolve => { confirm = resolve })
  })
  await h.state.loadLanguages()
  h.state.mediaType.value = 'audio'
  assert.equal(h.state.configured.value, true)
  const file = { name: 'Interview.M4A', size: 123 }
  h.state.selectFile({ target: { files: [file] } })
  h.state.sourceLanguage.value = 'en'
  await h.state.checkPrice()
  assert.equal(h.uploadInputs[0].contentType, 'audio/mp4')
  const body = h.calls[1].options.body
  assert.equal(body.media_type, 'audio'); assert.equal(body.operation, 'dubbing')
  assert.equal(body.audio_storage_path, 'audio/video.mp4'); assert.equal(body.video_storage_path, undefined)
  assert.equal(body.subtitles_enabled, false); assert.equal(body.subtitle_style, null)
  assert.equal(body.target_language, 'es')
  const submitting = h.state.submit(); await h.state.submit()
  assert.equal(h.calls.filter(item => item.path === '/api/dubbings').length, 1)
  confirm({ id: 'audio-dub', mediaType: 'audio', status: 'complete', audioUrl: 'https://r2.example/spanish.mp3' }); await submitting
  assert.equal(h.state.record.value.mediaType, 'audio'); assert.equal(h.acknowledgements(), 1)
  assert.equal(h.timers.size, 0)
})

test('switching media clears the selected file and video subtitles while WebM audio uses an audio MIME type', async t => {
  const h = harness(t, async () => quote)
  h.state.subtitlesEnabled.value = true
  await h.state.checkPrice()
  h.state.mediaType.value = 'audio'
  assert.equal(h.state.file.value, null); assert.equal(h.state.quote.value, null)
  assert.equal(h.state.subtitlesEnabled.value, false); assert.equal(h.state.operation.value, 'dubbing')
  h.state.selectFile({ target: { files: [{ name: 'Voice.webm', size: 321 }] } })
  await h.state.checkPrice()
  assert.equal(h.uploadInputs[1].contentType, 'audio/webm')
  assert.equal(h.uploads(), 2)
})

test('changing between dubbing and subtitles invalidates the quote and reuses the uploaded video', async t => {
  const h = harness(t, async path => path.endsWith('/languages') ? {
    data: [{ code: 'Spanish', name: 'Spanish', nigerian: false }], sourceLanguages: [{ code: 'en', name: 'English', nigerian: false }], configured: true,
    subtitlesOnly: { configured: true, data: [{ code: 'es', name: 'Spanish', nigerian: false }], sourceLanguages: [{ code: 'en', name: 'English', nigerian: false }] },
  } : quote)
  await h.state.loadLanguages(); await h.state.checkPrice()
  const firstKey = h.calls[1].options.body.client_key
  assert.equal(h.state.targetLanguage.value, 'Spanish')
  h.state.operation.value = 'subtitles'
  assert.equal(h.state.quote.value, null)
  assert.equal(h.state.targetLanguage.value, 'es')
  await h.state.checkPrice()
  assert.equal(h.uploads(), 1)
  assert.notEqual(h.calls[2].options.body.client_key, firstKey)
  h.state.operation.value = 'dubbing'
  assert.equal(h.state.subtitlesEnabled.value, false)
  assert.equal(h.state.targetLanguage.value, 'Spanish')
})

test('the dubbing form uses provider target names while sending spoken language codes', async t => {
  const h = harness(t, async path => path.endsWith('/languages') ? {
    data: [{ code: 'English', name: 'English', nigerian: false }, { code: 'French', name: 'French', nigerian: false }],
    sourceLanguages: [{ code: 'en', name: 'English', nigerian: false }], configured: true,
  } : quote)
  await h.state.loadLanguages()
  assert.equal(h.state.targetLanguage.value, 'English')
  assert.equal(h.state.sourceLanguages.value[0].code, 'en')
  h.state.sourceLanguage.value = 'en'; h.state.targetLanguage.value = 'French'
  await h.state.checkPrice()
  assert.equal(h.calls[1].options.body.target_language, 'French')
  assert.equal(h.calls[1].options.body.source_language, 'en')
})
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

test('subtitle options are quoted explicitly and a changed style requires a fresh quote without uploading twice', async t => {
  const h = harness(t, async () => quote)
  h.state.subtitlesEnabled.value = true; h.state.subtitleStyle.value = 'boxed'
  await h.state.checkPrice()
  assert.equal(h.calls[0].options.body.subtitles_enabled, true)
  assert.equal(h.calls[0].options.body.subtitle_style, 'boxed')
  const key = h.calls[0].options.body.client_key
  h.state.subtitleStyle.value = 'contrast'
  assert.equal(h.state.quote.value, null)
  await h.state.checkPrice()
  assert.equal(h.uploads(), 1)
  assert.equal(h.calls[1].options.body.subtitle_style, 'contrast')
  assert.notEqual(h.calls[1].options.body.client_key, key)
  h.state.subtitlesEnabled.value = false
  await h.state.checkPrice()
  assert.equal(h.calls[2].options.body.subtitles_enabled, false)
  assert.equal(h.calls[2].options.body.subtitle_style, null)
})

test('a style change discards a stale quote and subtitle processing keeps the video polling until complete', async t => {
  let finish
  const h = harness(t, async path => path.endsWith('/quotes') ? new Promise(resolve => { finish = resolve }) : {
    id: 'dub', status: 'processing', subtitleStatus: 'processing', subtitlesEnabled: true,
  })
  const pending = h.state.checkPrice(); await settle()
  h.state.subtitlesEnabled.value = true
  finish(quote); await pending
  assert.equal(h.state.quote.value, null)
  await h.state.refresh('dub')
  assert.equal(h.state.record.value.subtitleStatus, 'processing')
  assert.equal([...h.timers.values()][0].delay, 5000)
})
