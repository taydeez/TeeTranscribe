import type { BillingCurrency, CreditBalance, PackageCatalog, PurchaseQuote } from '~/types/billing'
import { suggestedCurrency } from '~/utils/credits'

export function useCreditBilling() {
  const currency = ref<BillingCurrency>('NGN')
  const balance = ref<CreditBalance | null>(null)
  const catalog = ref<PackageCatalog | null>(null)
  const paymentMethod = ref('')
  const purchase = ref<PurchaseQuote | null>(null)
  const busy = ref(false)
  const loading = ref(false)
  const error = ref('')
  const notice = ref('')
  const historyVersion = ref(0)
  let key = ''
  let selected = ''

  function fail(failure: unknown) {
    const issue = failure as { data?: { message?: string }; message?: string }
    error.value = issue.data?.message ?? issue.message ?? 'Billing is unavailable. Please try again.'
  }

  async function refresh() {
    loading.value = true; error.value = ''; catalog.value = null
    try {
      balance.value = await useAuthenticatedFetch<CreditBalance>('/api/billing/balance')
      catalog.value = await useAuthenticatedFetch<PackageCatalog>('/api/billing/packages', { query: { currency: currency.value } })
      if (paymentMethod.value && !catalog.value.payment_methods.some(method => method.code === paymentMethod.value)) {
        paymentMethod.value = ''; purchase.value = null; key = ''; selected = ''
      }
    } catch (failure: unknown) { fail(failure) }
    finally { loading.value = false }
  }

  async function changeCurrency(value: BillingCurrency) {
    if (busy.value) return
    paymentMethod.value = ''; currency.value = value; purchase.value = null; key = ''; selected = ''
    localStorage.setItem('billing-currency', value)
    await refresh()
  }

  function changePaymentMethod(code: string) {
    if (busy.value || !catalog.value?.payment_methods.some(method => method.code === code)) return
    paymentMethod.value = code; purchase.value = null; key = ''; selected = ''
  }

  async function selectPackage(id: string) {
    if (!paymentMethod.value) { error.value = 'Choose a payment method first.'; return }
    if (busy.value) return
    busy.value = true; error.value = ''
    if (selected !== `${id}:${currency.value}:${paymentMethod.value}`) { key = crypto.randomUUID(); selected = `${id}:${currency.value}:${paymentMethod.value}` }
    try {
      purchase.value = await useAuthenticatedFetch<PurchaseQuote>('/api/billing/purchases', { method: 'POST', body: { client_key: key, package_id: id, payment_method: paymentMethod.value, currency: currency.value } })
      if (new Date(purchase.value.expires_at).getTime() <= Date.now()) { key = ''; selected = ''; purchase.value = null; throw new Error('The purchase quote expired. Please select a package again.') }
    } catch (failure: unknown) { fail(failure) }
    finally { busy.value = false }
  }

  async function checkout() {
    if (!purchase.value || busy.value) return
    busy.value = true; error.value = ''
    let redirecting = false
    try {
      purchase.value = await useAuthenticatedFetch<PurchaseQuote>(`/api/billing/purchases/${purchase.value.id}/checkout`, { method: 'POST' })
      if (purchase.value.status === 'paid') { notice.value = 'This purchase has already been credited.'; await refresh(); return }
      const url = new URL(purchase.value.checkout_url ?? '')
      const hosts = purchase.value.gateway === 'flutterwave'
        ? ['checkout.flutterwave.com', 'checkout-v2.dev-flutterwave.com']
        : ['checkout.paystack.com']
      if (url.protocol !== 'https:' || !hosts.includes(url.hostname) || url.username || url.password || url.port) throw new Error('The checkout link could not be verified.')
      window.location.assign(url.toString())
      redirecting = true
    } catch (failure: unknown) { fail(failure) }
    finally { if (!redirecting) busy.value = false }
  }

  async function verify(reference: string) {
    if (busy.value) return
    busy.value = true; error.value = ''
    try {
      const result = await useAuthenticatedFetch<PurchaseQuote>('/api/billing/payments/verify', { method: 'POST', body: { reference } })
      notice.value = result.status === 'paid' ? 'Payment confirmed. Your credits are ready to use.' : result.status === 'failed' ? 'The payment was unsuccessful. You can try a new purchase.' : 'Your payment is still pending. Check again shortly; confirmed payments are also credited automatically.'
      if (result.status === 'paid') { historyVersion.value++; await refresh() }
    } catch (failure: unknown) { fail(failure) }
    finally { busy.value = false }
  }

  onMounted(async () => {
    const stored = localStorage.getItem('billing-currency')
    currency.value = stored === 'NGN' || stored === 'USD' ? stored : suggestedCurrency(Intl.DateTimeFormat().resolvedOptions().timeZone)
    await refresh()
  })
  return { currency, balance, catalog, paymentMethod, changePaymentMethod, purchase, busy, loading, error, notice, historyVersion, refresh, changeCurrency, selectPackage, checkout, verify }
}
