import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { createPinia, defineStore, setActivePinia } from 'pinia'
import { reactive, ref, watch } from 'vue'
import ts from 'typescript'

globalThis.translationHarness = { defineStore, ref, watch }
const source = await readFile(new URL('../../app/stores/translationDraft.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
  .replace(/import .* from 'pinia';/, 'const { defineStore } = globalThis.translationHarness;')
  .replace(/import .* from 'vue';/, 'const { ref, watch } = globalThis.translationHarness;')
const { useTranslationDraftStore } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`)

test('transferred text survives a route change and remains editable in translation', () => {
  setActivePinia(createPinia())
  globalThis.useAuthStore = () => reactive({ isAuthenticated: true, user: { id: 1 } })
  const sourceDraft = useTranslationDraftStore()
  sourceDraft.prepare('transcription-id', 'Client interview', 'Text from the editor.')
  const translationPage = useTranslationDraftStore()
  assert.equal(translationPage.text, 'Text from the editor.')
  assert.equal(translationPage.sourceName, 'Client interview')
  translationPage.text = 'Prepared for translation.'
  assert.equal(sourceDraft.text, 'Prepared for translation.')
})

test('signing out clears the transferred transcript and guests cannot populate it', () => {
  setActivePinia(createPinia())
  const auth = reactive({ isAuthenticated: true, user: { id: 1 } })
  globalThis.useAuthStore = () => auth
  const draft = useTranslationDraftStore()
  draft.prepare('transcription-id', 'Private transcript', 'Private text.')
  auth.isAuthenticated = false; auth.user = null
  assert.equal(draft.text, '')
  assert.equal(draft.sourceName, '')
  assert.equal(draft.transcriptionId, '')
  assert.deepEqual(draft.segments, [])
  draft.prepare('transcription-id', 'Private transcript', 'Private text.')
  assert.equal(draft.text, '')
})

test('speaker edits transfer as an independent snapshot without changing the transcript editor', () => {
  setActivePinia(createPinia())
  globalThis.useAuthStore = () => reactive({ isAuthenticated: true, user: { id: 1 } })
  const draft = useTranslationDraftStore()
  const segments = [{ start: 1, end: 2, text: 'Edited.', speaker: 'Ada', confidence: 0.9 }]
  draft.prepare('id', 'Interview', 'Edited.', segments)
  segments[0].speaker = 'Changed afterwards'
  assert.equal(draft.segments[0].speaker, 'Ada')
})
