import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, effectScope, ref, shallowRef, watch } from 'vue'
import ts from 'typescript'

const source = await readFile(new URL('../../app/composables/useTranscriptDraft.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const { useTranscriptDraft } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)
const segment = { start: 1, end: 3, speaker: 'Ada', text: 'hello there', confidence: 0.9 }

function harness(t) {
  globalThis.ref = ref; globalThis.shallowRef = shallowRef; globalThis.computed = computed; globalThis.watch = watch
  const transcription = ref({ id: 'one', transcript: 'hello there', segments: [{ ...segment }] })
  const scope = effectScope()
  const editor = scope.run(() => useTranscriptDraft(() => transcription.value))
  t.after(() => scope.stop())
  return { editor, transcription }
}

test('plain text opens by default and switching speaker views preserves edited words and timing', t => {
  const { editor } = harness(t)
  assert.equal(editor.view.value, 'plain')
  assert.equal(editor.text.value, 'hello there')
  editor.selectView('speakers')
  editor.segments.value[0].text = 'Hello, Ada.'
  editor.selectView('plain')
  assert.equal(editor.draft.value, 'Hello, Ada.')
  editor.selectView('speakers')
  assert.equal(editor.view.value, 'speakers')
  assert.deepEqual(editor.sourceSegments.value[0], { ...segment, text: 'Hello, Ada.' })
})

test('freeform plain edits cannot silently reuse or overwrite speaker timing', t => {
  const { editor } = harness(t)
  editor.draft.value = 'A completely different sentence.'
  assert.equal(editor.aligned.value, false)
  assert.deepEqual(editor.sourceSegments.value, [])
  editor.selectView('speakers')
  assert.equal(editor.view.value, 'plain')
  assert.equal(editor.text.value, 'A completely different sentence.')
  editor.draft.value = 'hello there'
  editor.selectView('speakers')
  assert.equal(editor.view.value, 'speakers')
})

test('cleanup applies to an unsaved draft without modifying the saved transcription', t => {
  const { editor, transcription } = harness(t)
  editor.draft.value = 'um edited before cleaning'
  editor.applyCleanup({ text: 'Edited before cleaning.', segments: [] })
  assert.equal(editor.text.value, 'Edited before cleaning.')
  assert.equal(transcription.value.transcript, 'hello there')
  assert.equal(transcription.value.segments[0].text, 'hello there')
})

test('speaker cleanup keeps timing and a newly opened transcription resets to plain text', async t => {
  const { editor, transcription } = harness(t)
  editor.selectView('speakers')
  editor.applyCleanup({ text: 'Hello, there.', segments: [{ ...segment, text: 'Hello, there.' }] })
  assert.deepEqual(editor.sourceSegments.value[0], { ...segment, text: 'Hello, there.' })
  transcription.value = { id: 'two', transcript: 'Next recording.', segments: [] }
  await Promise.resolve()
  assert.equal(editor.view.value, 'plain')
  assert.equal(editor.text.value, 'Next recording.')
})
