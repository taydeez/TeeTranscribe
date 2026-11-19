import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, ref } from 'vue'
import ts from 'typescript'
const compile = source => ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const moduleUrl = source => `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`
const utils = moduleUrl(compile(await readFile(new URL('../../app/utils/features.ts', import.meta.url), 'utf8')))
const { useMarketingFeatures } = await import(moduleUrl(compile(await readFile(new URL('../../app/composables/useMarketingFeatures.ts', import.meta.url), 'utf8')).replace("'~/utils/features'", JSON.stringify(utils))))

test('marketing reuses its last successful feature catalog during interruptions without enabling disabled tools', async t => {
  const states = new Map()
  const originals = { useState: globalThis.useState, computed: globalThis.computed, $fetch: globalThis.$fetch }
  globalThis.useState = (key, initial) => { if (!states.has(key)) states.set(key, ref(initial())); return states.get(key) }
  globalThis.computed = computed
  let calls = 0
  const flags = { transcription: true, translation: false, audio_dubbing: false, video_dubbing: false, subtitles: false }
  globalThis.$fetch = async () => { calls++; return { data: flags } }
  t.after(() => Object.assign(globalThis, originals))
  const features = useMarketingFeatures()
  await features.refresh()
  await features.refresh()
  assert.equal(calls, 1)
  states.get('marketing-features-checked').value = 0
  globalThis.$fetch = async () => { throw new Error('Backend unavailable') }
  await features.refresh()
  assert.equal(features.enabled('transcription'), true)
  assert.equal(features.enabled('video_dubbing'), false)
  assert.equal(features.unavailable.value, true)
})
