import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, ref, watch } from 'vue'
import ts from 'typescript'

const compile = source => ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const utility = compile(await readFile(new URL('../../app/utils/customerCreditUnits.ts', import.meta.url), 'utf8'))
const utilityUrl = `data:text/javascript;base64,${Buffer.from(utility).toString('base64')}`
const { customerCreditUnits } = await import(utilityUrl)
const source = (await readFile(new URL('../../app/composables/useAdminCustomer.ts', import.meta.url), 'utf8')).replace("'~/utils/customerCreditUnits'", JSON.stringify(utilityUrl))
const { useAdminCustomer } = await import(`data:text/javascript;base64,${Buffer.from(compile(source)).toString('base64')}`)

function setup(t, fetch, permissions = ['View_User', 'Update_User', 'Create_CreditLedger']) {
  const requests = [], stops = []
  const globals = { ref, computed, watch: (...args) => { const stop = watch(...args); stops.push(stop); return stop },
    useRoute: () => ({ params: { customerId: '42' } }),
    useAuthStore: () => ({ user: { permissions } }),
    onMounted: () => {}, onBeforeUnmount: () => {},
    useAuthenticatedFetch: (url, options) => { requests.push({ url, options }); return fetch(url, options) } }
  const originals = Object.fromEntries(Object.keys(globals).map(key => [key, globalThis[key]]))
  Object.assign(globalThis, globals)
  t.after(() => { stops.forEach(stop => stop()); Object.assign(globalThis, originals) })
  return { state: useAdminCustomer(), requests }
}

test('customer adjustment credits use exact whole units and reject excessive precision', () => {
  assert.equal(customerCreditUnits('50.05'), 5005)
  assert.equal(customerCreditUnits('0.01'), 1)
  assert.throws(() => customerCreditUnits('0.001'))
  assert.throws(() => customerCreditUnits('-10'))
  assert.throws(() => customerCreditUnits('0'))
})

test('rapid credit adjustment submissions issue one request with reason', async t => {
  let finish
  const h = setup(t, () => new Promise(resolve => { finish = resolve }))
  const first = h.state.adjustCredits({ action: 'add', credits: '50.05', reason: ' Compensation ' })
  assert.equal(await h.state.adjustCredits({ action: 'add', credits: '50.05', reason: ' Compensation ' }), false)
  assert.equal(h.requests.length, 1)
  assert.equal(h.requests[0].options.body.credit_units, 5005)
  assert.equal(h.requests[0].options.body.reason, 'Compensation')
  finish({ data: { id: 42 }, balance: { available_units: 5005 } })
  assert.equal(await first, true)
  assert.equal(h.state.result.value.balance.available_units, 5005)
})

test('retry after a lost adjustment response reuses the idempotency key', async t => {
  let attempts = 0
  const h = setup(t, async () => { if (!attempts++) throw new Error('Network interruption'); return { data: { id: 42 } } })
  const input = { action: 'remove', credits: '5', reason: 'Correction' }
  assert.equal(await h.state.adjustCredits(input), false)
  assert.equal(await h.state.adjustCredits(input), true)
  assert.equal(h.requests[0].options.body.client_key, h.requests[1].options.body.client_key)
  assert.equal(h.state.actionError.value, '')
})

test('read only permissions cannot perform customer mutations', async t => {
  const h = setup(t, async () => ({}), ['View_User'])
  assert.equal(await h.state.adjustCredits({ action: 'add', credits: '5', reason: 'Correction' }), false)
  assert.equal(await h.state.updateAccess({ action: 'block', reason: 'Abuse' }), false)
  assert.equal(h.requests.length, 0)
})

test('suspension sends the selected number of days and refreshes customer details', async t => {
  const h = setup(t, async () => ({ data: { id: 42, status: 'suspended' } }))
  await h.state.updateAccess({ action: 'suspend', days: 7, reason: 'Abuse' })
  assert.equal(h.requests[0].url, '/api/taydeez/customers/42/access')
  assert.deepEqual(h.requests[0].options.body, { action: 'suspend', days: 7, reason: 'Abuse' })
  assert.equal(h.state.result.value.data.status, 'suspended')
})
