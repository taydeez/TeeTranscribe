import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, ref } from 'vue'
import ts from 'typescript'
const compile = source => ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const moduleUrl = source => `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`
const utilityUrl = moduleUrl(compile(await readFile(new URL('../../app/utils/features.ts', import.meta.url), 'utf8')))
const { featureRouteAvailable, hasDubbing, dubbingSelection } = await import(utilityUrl)
const source = compile(await readFile(new URL('../../app/composables/useFeatures.ts', import.meta.url), 'utf8')).replace("'~/utils/features'", JSON.stringify(utilityUrl))
const { useFeatures } = await import(moduleUrl(source))
const enabled = { transcription: true, translation: true, audio_dubbing: true, video_dubbing: true, subtitles: true }

test('feature routes fail closed while the library and account pages stay accessible', () => {
  assert.equal(featureRouteAvailable('/dashboard/translations', null), false)
  assert.equal(featureRouteAvailable('/dashboard/dubbing', null), false)
  assert.equal(featureRouteAvailable('/dashboard/transcriptions/folder', null), true)
  assert.equal(featureRouteAvailable('/dashboard/settings', null), true)
  assert.equal(featureRouteAvailable('/dashboard/translations/', null), false)
  assert.equal(featureRouteAvailable('/dashboard/dubbing', null, { dubbing: '01M6W4NXDNF1VSR89GCSWJR106' }), true)
  assert.equal(featureRouteAvailable('/dashboard/translations', null, { translation: '01M6W4NXDNF1VSR89GCSWJR106' }), true)
  assert.equal(featureRouteAvailable('/dashboard/dubbing', null, { dubbing: 'invalid-id' }), false)
  assert.equal(featureRouteAvailable('/dashboard/translations', { ...enabled, translation: false }), false)
})

test('every combination of audio, video, and subtitle switches selects only an enabled activity', () => {
  for (let mask = 0; mask < 8; mask++) {
    const flags = { ...enabled, audio_dubbing: !!(mask & 1), video_dubbing: !!(mask & 2), subtitles: !!(mask & 4) }
    assert.equal(hasDubbing(flags), mask !== 0)
    assert.equal(featureRouteAvailable('/dashboard/dubbing', flags), mask !== 0)
    for (const media of ['audio', 'video']) for (const operation of ['dubbing', 'subtitles']) {
      const result = dubbingSelection(flags, media, operation)
      if (!mask) { assert.equal(result, null); continue }
      const feature = result.media === 'audio' ? 'audio_dubbing' : result.operation === 'subtitles' ? 'subtitles' : 'video_dubbing'
      assert.equal(flags[feature], true)
    }
  }
})

test('feature refresh shares switches across views and hides them on failure until recovery', async t => {
  const state = new Map()
  const originals = { useState: globalThis.useState, computed: globalThis.computed, $fetch: globalThis.$fetch }
  globalThis.useState = (key, initial) => { if (!state.has(key)) state.set(key, ref(initial())); return state.get(key) }
  globalThis.computed = computed
  globalThis.$fetch = async () => ({ data: enabled })
  t.after(() => Object.assign(globalThis, originals))
  const first = useFeatures(), second = useFeatures()
  assert.equal(first.enabled('transcription'), false)
  await first.refresh()
  assert.equal(second.enabled('transcription'), true)
  globalThis.$fetch = async () => ({ data: { ...enabled, translation: false } })
  await second.refresh()
  assert.equal(first.enabled('translation'), false)
  globalThis.$fetch = async () => { throw new Error('Network interrupted') }
  await first.refresh()
  assert.equal(second.dubbing.value, false)
  assert.ok(second.error.value)
  globalThis.$fetch = async () => ({ data: enabled })
  await second.refresh()
  assert.equal(first.enabled('translation'), true)
  assert.equal(first.error.value, '')
  const pending = []
  globalThis.$fetch = () => new Promise(resolve => pending.push(resolve))
  const older = first.refresh(), newer = second.refresh()
  pending[1]({ data: { ...enabled, translation: false } })
  await newer
  pending[0]({ data: enabled })
  await older
  assert.equal(first.enabled('translation'), false)
})
