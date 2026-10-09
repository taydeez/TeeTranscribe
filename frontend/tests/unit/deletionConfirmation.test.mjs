import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, effectScope, nextTick, reactive, ref, watch } from 'vue'
import ts from 'typescript'

const component = await readFile(new URL('../../app/components/privacy/DeletionConfirmation.vue', import.meta.url), 'utf8')
const script = component.match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1]
const compiled = ts.transpileModule('export function setup() {\n' + script + '\nreturn { dialog, cancel }\n}', { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText

async function harness(t) {
  const scope = effectScope(), props = reactive({ open: false, busy: false, disabled: false, name: 'Meetings', title: 'Delete folder?', consequence: 'Delete contents.', error: '' })
  const events = [], style = { overflow: 'auto' }, element = { open: false, shown: 0, closed: 0, showModal() { this.open = true; this.shown++ }, close() { this.open = false; this.closed++ } }
  const originalDocument = globalThis.document
  let unmount
  globalThis.ref = ref; globalThis.watch = watch; globalThis.computed = computed
  globalThis.defineProps = () => props
  globalThis.defineEmits = () => event => { events.push(event); if (event === 'cancel') props.open = false }
  globalThis.useId = () => 'confirmation'
  globalThis.onBeforeUnmount = callback => { unmount = callback }
  globalThis.document = { body: { style } }
  const { setup } = await import('data:text/javascript;base64,' + Buffer.from(compiled).toString('base64'))
  const module = scope.run(setup)
  module.dialog.value = element
  await nextTick()
  t.after(() => { unmount(); scope.stop(); globalThis.document = originalDocument })
  return { props, element, style, events, module, unmount }
}

test('deletion confirmation opens a modal and cancellation removes the overlay and restores page scrolling', async t => {
  const h = await harness(t)
  h.props.open = true; await nextTick()
  assert.equal(h.element.open, true)
  assert.equal(h.element.shown, 1)
  assert.equal(h.style.overflow, 'hidden')
  h.module.cancel(); await nextTick()
  assert.equal(h.element.open, false)
  assert.equal(h.style.overflow, 'auto')
  assert.deepEqual(h.events, ['cancel'])
})

test('accepted deletion and navigation both close the modal and restore the previous scroll setting', async t => {
  const h = await harness(t)
  h.props.open = true; await nextTick()
  h.props.open = false; await nextTick()
  assert.equal(h.element.open, false)
  assert.equal(h.style.overflow, 'auto')
  h.props.open = true; await nextTick()
  h.unmount()
  assert.equal(h.element.open, false)
  assert.equal(h.style.overflow, 'auto')
})

test('confirmation stays open during submission and after a request error so the user can retry or cancel', async t => {
  const h = await harness(t)
  h.props.open = true; h.props.busy = true; await nextTick()
  h.module.cancel(); await nextTick()
  assert.equal(h.element.open, true)
  assert.deepEqual(h.events, [])
  h.props.busy = false; h.props.error = 'Request failed'; await nextTick()
  assert.equal(h.element.open, true)
  h.module.cancel(); await nextTick()
  assert.equal(h.element.open, false)
  assert.equal(h.style.overflow, 'auto')
})
