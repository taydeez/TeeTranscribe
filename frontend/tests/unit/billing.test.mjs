import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, ref } from 'vue'
import ts from 'typescript'

const moduleUrl = source => `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`
const compile = source => ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText
const creditsSource = await readFile(new URL('../../app/utils/credits.ts', import.meta.url), 'utf8')
const creditsModule = moduleUrl(compile(creditsSource))
const { formatCredits, suggestedCurrency } = await import(creditsModule)
const formSource = await readFile(new URL('../../app/composables/useTranscriptionForm.ts', import.meta.url), 'utf8')
const { useTranscriptionForm } = await import(moduleUrl(compile(formSource).replace("'~/utils/credits'", JSON.stringify(creditsModule))))

test('fractional credit display and currency suggestion preserve the wallet unit', () => {
  assert.equal(formatCredits(3001), '30.01')
  assert.equal(formatCredits(1), '0.01')
  assert.equal(suggestedCurrency('Africa/Lagos'), 'NGN')
  assert.equal(suggestedCurrency('America/New_York'), 'USD')
})

function harness(authenticated = true, enough = true) {
  const calls = []
  const quote = ref(null)
  globalThis.ref = ref
  globalThis.computed = computed
  globalThis.watch = () => {}
  globalThis.useAuthStore = () => ({ isAuthenticated: authenticated, user: authenticated ? { id: 1 } : null })
  globalThis.useResumableUpload = () => ({ cancelling: ref(false), progress: ref(0), acknowledge: async () => {}, notice: ref('') })
  globalThis.useTranscriptionQuote = () => ({
    quote, reset: () => { quote.value = null },
    request: async source => {
      calls.push({ path: 'quote', source })
      quote.value = { id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', status: 'ready', credit_units: 3001, quantity: 90001, enough_credits: enough }
      return quote.value
    },
  })
  globalThis.useAuthenticatedFetch = async (path, options) => { calls.push({ path, body: options.body }); return { id: 'transcription-id' } }
  const form = useTranscriptionForm()
  form.source.value = 'url'; form.pastedUrl.value = 'https://example.com/audio.mp3'
  return { form, calls }
}

test('checks the price first and submits only after a second confirmation', async () => {
  const { form, calls } = harness()
  assert.equal(await form.submit(), null)
  assert.equal(form.stage.value, 'quoted')
  assert.equal(calls.length, 1)
  assert.equal(calls[0].path, 'quote')
  assert.equal(form.submitLabel.value, 'Confirm · 30.01 credits')
  form.folderId.value = 'folder-id'
  form.name.value = '  Client interview  '
  assert.equal(await form.submit(), 'transcription-id')
  assert.deepEqual(calls[1], { path: '/api/transcribe', body: { quote_id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', folder_id: 'folder-id', name: 'Client interview' } })
})

test('requires sign in before requesting a quote or uploading', async () => {
  const { form, calls } = harness(false)
  assert.equal(await form.submit(), null)
  assert.equal(calls.length, 0)
  assert.match(form.error.value, /Sign in/)
})

test('an insufficient quote cannot be confirmed and retains its audio', async () => {
  const { form, calls } = harness(true, false)
  await form.submit()
  assert.equal(form.canSubmit.value, false)
  assert.equal(form.uploadedUrl.value, 'https://example.com/audio.mp3')
  assert.equal(calls.length, 1)
})

const billingSource = await readFile(new URL('../../app/composables/useCreditBilling.ts', import.meta.url), 'utf8')
const { useCreditBilling } = await import(moduleUrl(compile(billingSource).replace("'~/utils/credits'", JSON.stringify(creditsModule))))

test('checkout ignores repeated clicks during initialization and navigation but allows retry after failure', async () => {
  globalThis.ref = ref
  globalThis.onMounted = () => {}
  let resolveCheckout
  let calls = 0
  const redirects = []
  globalThis.window = { location: { assign: url => redirects.push(url) } }
  globalThis.useAuthenticatedFetch = () => {
    calls++
    return new Promise(resolve => { resolveCheckout = resolve })
  }
  const billing = useCreditBilling()
  billing.purchase.value = { id: 'payment-id', gateway: 'flutterwave' }
  const first = billing.checkout()
  await billing.checkout()
  assert.equal(calls, 1)
  resolveCheckout({ id: 'payment-id', gateway: 'flutterwave', status: 'pending', checkout_url: 'https://checkout.flutterwave.com/pay/test' })
  await first
  await billing.checkout()
  assert.equal(calls, 1)
  assert.equal(redirects.length, 1)
  assert.equal(billing.busy.value, true)

  const retry = useCreditBilling()
  retry.purchase.value = { id: 'retry-id', gateway: 'flutterwave' }
  globalThis.useAuthenticatedFetch = async () => { throw new Error('Network interrupted') }
  await retry.checkout()
  assert.equal(retry.busy.value, false)
  globalThis.useAuthenticatedFetch = async () => ({ id: 'retry-id', gateway: 'flutterwave', status: 'pending', checkout_url: 'https://checkout.flutterwave.com/pay/retry' })
  await retry.checkout()
  assert.equal(redirects.length, 2)
})

test('purchase uses the selected gateway and switching gateways starts a fresh quote', async () => {
  globalThis.ref = ref
  globalThis.onMounted = () => {}
  const calls = []
  globalThis.useAuthenticatedFetch = async (path, options) => {
    calls.push({ path, body: options.body })
    return { id: 'payment-id', gateway: options.body.payment_method, expires_at: new Date(Date.now() + 60000).toISOString() }
  }
  const billing = useCreditBilling()
  billing.catalog.value = { data: [], payments_enabled: true, usd_enabled: true, payment_methods: [{ id: 'p', code: 'paystack' }, { id: 'f', code: 'flutterwave' }] }
  await billing.selectPackage('starter')
  assert.equal(calls.length, 0)
  billing.changePaymentMethod('paystack')
  await billing.selectPackage('starter')
  await billing.selectPackage('starter')
  assert.equal(calls[0].body.payment_method, 'paystack')
  assert.equal(calls[0].body.client_key, calls[1].body.client_key)
  billing.changePaymentMethod('flutterwave')
  assert.equal(billing.purchase.value, null)
  await billing.selectPackage('starter')
  assert.equal(calls[2].body.payment_method, 'flutterwave')
  assert.notEqual(calls[0].body.client_key, calls[2].body.client_key)
})

test('checkout supports Flutterwave sandbox and rejects unsafe or mismatched URLs', async () => {
  globalThis.ref = ref
  globalThis.onMounted = () => {}
  const redirects = []
  globalThis.window = { location: { assign: url => redirects.push(url) } }
  for (const [gateway, url, allowed] of [
    ['flutterwave', 'https://checkout-v2.dev-flutterwave.com/pay/test', true],
    ['flutterwave', 'https://checkout.flutterwave.com/pay/live', true],
    ['paystack', 'https://checkout.paystack.com/pay/test', true],
    ['paystack', 'https://checkout-v2.dev-flutterwave.com/pay/test', false],
    ['flutterwave', 'https://checkout-v2.dev-flutterwave.com.attacker.example/pay', false],
    ['flutterwave', 'http://checkout-v2.dev-flutterwave.com/pay', false],
    ['flutterwave', 'https://user:pass@checkout-v2.dev-flutterwave.com/pay', false],
    ['flutterwave', 'https://checkout-v2.dev-flutterwave.com:8443/pay', false],
  ]) {
    const result = { id: 'payment-id', gateway, status: 'pending', checkout_url: url }
    globalThis.useAuthenticatedFetch = async () => result
    const billing = useCreditBilling()
    billing.purchase.value = result
    const before = redirects.length
    await billing.checkout()
    assert.equal(redirects.length, before + (allowed ? 1 : 0))
    assert.equal(billing.error.value === '', allowed)
  }
})
