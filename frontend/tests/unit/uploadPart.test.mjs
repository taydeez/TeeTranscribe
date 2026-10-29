import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import ts from 'typescript'

async function loadUtility(path) {
  const source = await readFile(new URL(path, import.meta.url), 'utf8')
  const { outputText } = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } })
  return import(`data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`)
}
const { sendUploadPart, uploadDelay } = await loadUtility('../../app/utils/uploadPart.ts')
const { fileFingerprint } = await loadUtility('../../app/utils/uploadState.ts')

class FakeXHR {
  static instances = []
  upload = {}
  headers = {}
  status = 200
  open(method, url) { this.method = method; this.url = url }
  setRequestHeader(name, value) { this.headers[name] = value }
  send(body) { this.body = body; FakeXHR.instances.push(this) }
  abort() { this.onabort() }
}
const ticket = { upload_url: 'https://storage.example/signed-part', headers: { 'x-test': 'signed' } }

test('uploads only the selected chunk directly to storage and reports real progress', async () => {
  const previous = globalThis.XMLHttpRequest
  globalThis.XMLHttpRequest = FakeXHR
  try {
    const controller = new AbortController()
    const updates = []
    const chunk = new Blob(['part'])
    const promise = sendUploadPart(ticket, chunk, controller.signal, loaded => updates.push(loaded))
    const xhr = FakeXHR.instances.at(-1)
    assert.equal(xhr.method, 'PUT')
    assert.equal(xhr.url, ticket.upload_url)
    assert.equal(xhr.body, chunk)
    assert.deepEqual(xhr.headers, { 'x-test': 'signed' })
    xhr.upload.onprogress({ loaded: 3 })
    xhr.onload()
    await promise
    assert.deepEqual(updates, [3])
    controller.abort()
  } finally { globalThis.XMLHttpRequest = previous }
})

test('pause aborts the active part without pretending it finished', async () => {
  const previous = globalThis.XMLHttpRequest
  globalThis.XMLHttpRequest = FakeXHR
  try {
    const controller = new AbortController()
    const promise = sendUploadPart(ticket, new Blob(['part']), controller.signal, () => {})
    const rejected = assert.rejects(promise, { name: 'AbortError' })
    controller.abort()
    await rejected
  } finally { globalThis.XMLHttpRequest = previous }
})

test('storage failures and network interruption reject so the caller can retry the same part', async () => {
  const previous = globalThis.XMLHttpRequest
  globalThis.XMLHttpRequest = FakeXHR
  try {
    for (const outcome of ['rejected', 'offline', 'timeout']) {
      const promise = sendUploadPart(ticket, new Blob(['part']), new AbortController().signal, () => {})
      const rejected = assert.rejects(promise)
      const xhr = FakeXHR.instances.at(-1)
      if (outcome === 'rejected') { xhr.status = 503; xhr.onload() }
      if (outcome === 'offline') xhr.onerror()
      if (outcome === 'timeout') xhr.ontimeout()
      await rejected
    }
  } finally { globalThis.XMLHttpRequest = previous }
})

test('pause also interrupts retry backoff immediately', async () => {
  const controller = new AbortController()
  const rejected = assert.rejects(uploadDelay(60_000, controller.signal), { name: 'AbortError' })
  controller.abort()
  await rejected
})

test('reselected unchanged files keep their identity even when filesystem timestamps differ', async () => {
  const original = new File(['original audio'], 'recording.mp3', { lastModified: 1 })
  const reselected = new File(['original audio'], 'recording.mp3', { lastModified: 2 })
  const changed = new File(['different audio'], 'recording.mp3', { lastModified: 1 })
  assert.equal(await fileFingerprint(original), await fileFingerprint(reselected))
  assert.notEqual(await fileFingerprint(original), await fileFingerprint(changed))
})
