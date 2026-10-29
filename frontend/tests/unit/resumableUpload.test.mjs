import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { ref } from 'vue'
import ts from 'typescript'

function moduleUrl(source) {
  return `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`
}
const stateModule = moduleUrl(`
  export const fileFingerprint = async () => 'a'.repeat(64)
  export const savedUploads = async id => [...globalThis.uploadHarness.saved.values()].filter(x => x.userId === id).map(x => ({...x}))
  export const saveUpload = async record => globalThis.uploadHarness.saved.set(record.key, {...record})
  export const forgetUpload = async key => globalThis.uploadHarness.saved.delete(key)
`)
const partsModule = moduleUrl(`
  export const sendUploadPart = (...args) => globalThis.uploadHarness.send(...args)
  export const uploadDelay = async () => {}
`)
const source = await readFile(new URL('../../app/composables/useResumableUpload.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
  .replace("'~/utils/uploadState'", JSON.stringify(stateModule))
  .replace("'~/utils/uploadPart'", JSON.stringify(partsModule))
const { useResumableUpload } = await import(moduleUrl(compiled))

function harness() {
  const context = {
    saved: new Map(), sessions: new Map(), parts: [], signed: [],
    beginnings: 0, completions: 0, loseStart: false, expiredStorage: false, pausePart: 0, failPartOnce: 0,
  }
  globalThis.uploadHarness = context
  globalThis.ref = ref
  globalThis.useAuthStore = () => ({ user: { id: 1 } })
  globalThis.watch = () => {}
  globalThis.onMounted = () => {}
  globalThis.onBeforeUnmount = () => {}
  globalThis.useAuthenticatedFetch = async (path, { body, signal }) => {
    if (signal?.aborted) throw new DOMException('Paused', 'AbortError')
    if (path.endsWith('/multipart')) {
      let session = context.sessions.get(body.client_key)
      if (!session) {
        context.beginnings++
        session = { id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', status: 'uploading', filename: body.filename, size: body.size, part_size: 2, part_count: Math.ceil(body.size / 2), expires_at: new Date(Date.now() + 86400000).toISOString() }
        context.sessions.set(body.client_key, session)
      }
      if (context.loseStart) { context.loseStart = false; throw new Error('Start response lost') }
      return { ...session }
    }
    const session = [...context.sessions.values()][0]
    if (path.endsWith('/status')) {
      if (context.expiredStorage) throw Object.assign(new Error('Gone'), { statusCode: 410 })
      return { ...session, parts: context.parts }
    }
    if (path.endsWith('/part')) {
      context.signed.push(body.part_number)
      return { upload_url: String(body.part_number), headers: {} }
    }
    if (path.endsWith('/complete')) {
      assert.equal(context.parts.length, session.part_count)
      if (session.status !== 'completed') context.completions++
      session.status = 'completed'
      return { audio_url: 'https://storage.example/audio.mp3', audio_storage_path: 'audio/example.mp3' }
    }
    if (path.endsWith('/abort')) {
      if (session.status === 'completed') throw Object.assign(new Error('This file is already uploaded.'), { statusCode: 409 })
      session.status = 'aborted'; return { status: 'aborted' }
    }
    throw new Error('Unexpected endpoint')
  }
  context.send = async (ticket, chunk, signal, progress) => {
    const number = Number(ticket.upload_url)
    if (context.pausePart === number) {
      context.pausePart = 0
      context.uploader.pause()
      throw new DOMException('Paused', 'AbortError')
    }
    if (context.failPartOnce === number) {
      context.failPartOnce = 0
      throw new Error('Network interruption')
    }
    if (signal.aborted) throw new DOMException('Paused', 'AbortError')
    progress(chunk.size)
    context.parts = context.parts.filter(part => part.number !== number)
    context.parts.push({ number, size: chunk.size, etag: 'etag-' + number })
  }
  context.uploader = useResumableUpload()
  return context
}
const file = new File(['audio'], 'recording.mp3')

test('discard removes a completed saved upload without aborting its stored file', async () => {
  const context = harness()
  await context.uploader.upload(file, 'audio/mpeg')
  const record = [...context.saved.values()][0]
  context.uploader = useResumableUpload()
  await context.uploader.discard(record)
  assert.equal(context.saved.size, 0)
  assert.equal(context.uploader.unfinished.value.length, 0)
  assert.equal([...context.sessions.values()][0].status, 'completed')
  assert.match(context.uploader.notice.value, /uploaded file is preserved/)
  assert.equal(context.uploader.cancelling.value, false)
})

test('discard preserves recovery state when an abort conflict is not a completed upload', async () => {
  const context = harness()
  context.pausePart = 1
  await assert.rejects(context.uploader.upload(file, 'audio/mpeg'), { name: 'AbortError' })
  const request = globalThis.useAuthenticatedFetch
  globalThis.useAuthenticatedFetch = async (path, options) => {
    if (path.endsWith('/abort')) throw Object.assign(new Error('Conflict'), { statusCode: 409 })
    return request(path, options)
  }
  await assert.rejects(context.uploader.discard([...context.saved.values()][0]), /Conflict/)
  assert.equal(context.saved.size, 1)
  assert.equal(context.uploader.cancelling.value, false)
})

test('a new uploader instance resumes only missing parts after pause and refresh', async () => {
  const context = harness()
  context.pausePart = 2
  await assert.rejects(context.uploader.upload(file, 'audio/mpeg'), { name: 'AbortError' })
  assert.deepEqual(context.parts.map(part => part.number), [1])
  assert.equal(context.saved.size, 1)
  context.uploader = useResumableUpload()
  await context.uploader.upload(file, 'audio/mpeg')
  assert.deepEqual(context.signed, [1, 2, 2, 3])
  assert.equal(context.beginnings, 1)
  assert.equal(context.completions, 1)
  assert.equal(context.uploader.progress.value, 100)
})

test('retries a failed part with a fresh signed URL without repeating successful parts', async () => {
  const context = harness()
  context.failPartOnce = 2
  await context.uploader.upload(file, 'audio/mpeg')
  assert.deepEqual(context.signed, [1, 2, 2, 3])
  assert.equal(context.completions, 1)
})

test('persists the client key before requesting an upload so a lost start response is recoverable', async () => {
  const context = harness()
  context.loseStart = true
  await assert.rejects(context.uploader.upload(file, 'audio/mpeg'), /response lost/)
  assert.equal(context.saved.size, 1)
  context.uploader = useResumableUpload()
  await context.uploader.upload(file, 'audio/mpeg')
  assert.equal(context.beginnings, 1)
})

test('retains a completed upload until transcription is accepted, then clears recovery state', async () => {
  const context = harness()
  await context.uploader.upload(file, 'audio/mpeg')
  assert.equal(context.saved.size, 1)
  const signedBeforeRetry = [...context.signed]
  context.uploader = useResumableUpload()
  await context.uploader.upload(file, 'audio/mpeg')
  assert.deepEqual(context.signed, signedBeforeRetry)
  assert.equal(context.completions, 1)
  await context.uploader.acknowledge()
  assert.equal(context.saved.size, 0)
})

test('replaces an incorrectly sized part instead of trusting cached progress', async () => {
  const context = harness()
  context.parts = [{ number: 1, size: 1, etag: 'wrong-size' }]
  await context.uploader.upload(file, 'audio/mpeg')
  assert.deepEqual(context.signed, [1, 2, 3])
  assert.equal(context.parts.find(part => part.number === 1).size, 2)
})

test('clears an expired R2 session so the next submission can start afresh', async () => {
  const context = harness()
  context.expiredStorage = true
  await assert.rejects(context.uploader.upload(file, 'audio/mpeg'), /expired/)
  assert.equal(context.saved.size, 0)
})
