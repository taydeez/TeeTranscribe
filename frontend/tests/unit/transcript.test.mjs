import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import ts from 'typescript'

const source = await readFile(new URL('../../app/utils/transcript.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const { hasTranscriptChanges, exportDownloads } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)

test('unchanged transcript content does not enable save but text and speaker edits do', () => {
  const record = { transcript: 'Hello.', segments: [{ text: 'Hello.', speaker: 'Speaker 1', start: 0, end: 2 }] }
  assert.equal(hasTranscriptChanges(record, 'Hello.', null), false)
  assert.equal(hasTranscriptChanges(record, 'Hello.', [{ ...record.segments[0], text: ' Hello. ' }]), false)
  assert.equal(hasTranscriptChanges(record, 'Hello.', [{ ...record.segments[0], speaker: 'Ada' }]), true)
  assert.equal(hasTranscriptChanges(record, 'Edited.', null), true)
})

test('pending exports hide old download links and become downloadable only after completion', () => {
  const record = { status: 'processing', exports: [{ format: 'pdf', status: 'pending', downloadUrl: 'https://example.com/old.pdf' }] }
  const pending = exportDownloads(record)
  assert.equal(pending[0].ready, false)
  assert.equal(pending[0].working, true)
  assert.equal(pending[1].working, true)
  record.exports[0] = { format: 'pdf', status: 'completed', downloadUrl: 'https://example.com/new.pdf' }
  const refreshed = exportDownloads(record)
  assert.equal(refreshed[0].ready, true)
  assert.equal(refreshed[0].working, false)
  assert.equal(refreshed[1].working, true)
  record.exports.push({ format: 'txt', status: 'failed', downloadUrl: null })
  assert.equal(exportDownloads(record)[1].working, false)
  assert.equal(exportDownloads(record)[1].ready, false)
})

test('downloads select the requested speaker variant independently across PDF, TXT and DOCX', () => {
  const record = { status: 'complete', exports: [
    { format: 'txt', status: 'completed', downloadUrl: 'https://example.com/plain.txt' },
    { format: 'txt', variant: 'speakers', status: 'completed', downloadUrl: 'https://example.com/speakers.txt' },
    { format: 'docx', variant: 'speakers', status: 'pending', downloadUrl: 'https://example.com/stale.docx' },
  ] }
  assert.equal(exportDownloads(record)[1].item.downloadUrl, 'https://example.com/plain.txt')
  const speakers = exportDownloads(record, 'speakers')
  assert.deepEqual(speakers.map(item => item.format), ['pdf', 'txt', 'docx'])
  assert.equal(speakers[1].item.downloadUrl, 'https://example.com/speakers.txt')
  assert.equal(speakers[2].ready, false)
  assert.equal(speakers[2].working, true)
})
