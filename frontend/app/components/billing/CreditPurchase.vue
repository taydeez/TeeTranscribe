<script setup lang="ts">
import type { BillingCurrency } from '~/types/billing'
import { formatCredits, formatMoney } from '~/utils/credits'

const props = defineProps<{ billing: ReturnType<typeof useCreditBilling> }>()
defineEmits<{ cancel: [] }>()
const confirmation = ref<HTMLElement | null>(null)
const selecting = ref('')
const selected = ref('')

async function choosePackage(id: string) {
  if (props.billing.busy.value) return
  selecting.value = id
  try {
    await props.billing.selectPackage(id)
    if (props.billing.error.value || !props.billing.purchase.value) return
    selected.value = id
    await nextTick()
    if (window.matchMedia('(max-width: 767px)').matches) {
      confirmation.value?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' })
      confirmation.value?.querySelector<HTMLElement>('h3')?.focus({ preventScroll: true })
    }
  } finally { selecting.value = '' }
}
</script>

<template>
  <section aria-labelledby="add-credits-title" class="purchase-section">
    <div class="purchase-header">
      <div><p class="eyebrow">TOP UP YOUR WORKSPACE</p><h2 id="add-credits-title">Choose your credit package</h2><p>Buy what you need. No subscription required.</p></div>
      <label class="currency-label">Pay in<select :value="billing.currency.value" :disabled="billing.busy.value || billing.loading.value" @change="billing.changeCurrency(($event.target as HTMLSelectElement).value as BillingCurrency)"><option value="NGN">NGN · Nigerian naira</option><option value="USD">USD · US dollar</option></select></label>
    </div>
    <div class="purchase-grid">
      <div class="space-y-5 min-w-0">
        <fieldset v-if="billing.catalog.value?.payment_methods.length" class="payment-methods" :disabled="billing.busy.value || billing.loading.value">
          <legend>Payment method</legend>
          <div class="grid gap-3 sm:grid-cols-2">
            <label v-for="method in billing.catalog.value.payment_methods" :key="method.id" class="method-option" :class="{ 'method-selected': billing.paymentMethod.value === method.code }">
              <input type="radio" name="payment-method" :value="method.code" :checked="billing.paymentMethod.value === method.code" @change="billing.changePaymentMethod(method.code)">
              <span><strong>{{ method.name }}</strong><span>Secure hosted checkout</span></span><UiAppIcon name="billing" :size="19" />
            </label>
          </div>
        </fieldset>
        <div v-if="billing.loading.value" class="space-y-3" role="status" aria-label="Loading credit packages"><div v-for="index in 3" :key="index" class="package-skeleton motion-safe:animate-pulse" /><span class="sr-only">Loading packages…</span></div>
        <div v-else-if="billing.catalog.value?.data.length" class="space-y-3">
          <article v-for="pack in billing.catalog.value.data" :key="pack.id" class="package-card" :class="{ 'package-selected': billing.purchase.value && selected === pack.id }">
            <div class="package-top"><p class="package-name">{{ pack.name }}</p><span v-if="billing.purchase.value && selected === pack.id" class="selected-label"><UiAppIcon name="check" :size="13" /> Selected</span></div>
            <div class="package-details"><div><h3>{{ formatCredits(pack.credit_units) }} <span>credits</span></h3><p>{{ formatMoney(pack.amount_minor, pack.currency) }}</p></div><button class="package-button" :class="{ 'package-button-selected': billing.purchase.value && selected === pack.id }" type="button" :disabled="billing.busy.value || !billing.catalog.value.payments_enabled || !billing.paymentMethod.value" :aria-label="`Select ${pack.name}, ${formatCredits(pack.credit_units)} credits for ${formatMoney(pack.amount_minor, pack.currency)}`" @click="choosePackage(pack.id)"><UiAppIcon v-if="selecting === pack.id" name="loader" :size="16" class="animate-spin" />{{ selecting === pack.id ? 'Selecting…' : billing.purchase.value && selected === pack.id ? 'Selected' : 'Select package' }}<UiAppIcon v-if="selecting !== pack.id" :name="billing.purchase.value && selected === pack.id ? 'check' : 'arrow'" :size="16" /></button></div>
          </article>
          <p v-if="!billing.catalog.value.payments_enabled" class="purchase-help" role="status">Payments aren’t configured yet. Please check back later.</p>
          <p v-else-if="!billing.paymentMethod.value" class="purchase-help">Choose a payment method above to select a package.</p>
        </div>
        <div v-else-if="billing.catalog.value" class="surface p-6 text-sm text-slate-500">No packages are available in this currency right now. Try another currency.</div>
        <button v-else class="button-secondary" type="button" @click="billing.refresh">Retry packages</button>
        <p class="purchase-help">Your currency is suggested from your time zone. You can change it above.</p>
      </div>
      <section ref="confirmation" class="confirmation surface" :class="{ 'confirmation-empty': !billing.purchase.value }" aria-labelledby="purchase-heading" :aria-busy="billing.busy.value">
        <div class="confirmation-heading"><span class="feature-icon"><UiAppIcon name="billing" /></span><div><p class="eyebrow">YOUR ORDER</p><h3 id="purchase-heading" tabindex="-1">Confirm your package</h3></div></div>
        <template v-if="billing.purchase.value">
          <p class="order-name">{{ billing.purchase.value.package_name }}</p>
          <dl class="order-details"><div><dt>Credits added</dt><dd>{{ formatCredits(billing.purchase.value.credit_units) }}</dd></div><div><dt>Currency</dt><dd>{{ billing.purchase.value.currency }}</dd></div><div class="order-total"><dt>Total to pay</dt><dd>{{ formatMoney(billing.purchase.value.amount_minor, billing.purchase.value.currency) }}</dd></div></dl>
          <p v-if="billing.purchase.value.fx_ngn_per_usd_micros" class="purchase-help">Locked exchange rate: $1 = ₦{{ (billing.purchase.value.fx_ngn_per_usd_micros / 1000000).toLocaleString() }}.</p>
          <button class="checkout-button" type="button" :disabled="billing.busy.value" @click="billing.checkout"><UiAppIcon :name="billing.busy.value ? 'loader' : 'arrow'" :size="18" :class="{ 'animate-spin': billing.busy.value }" />{{ billing.busy.value ? 'Preparing checkout…' : 'Continue to secure checkout' }}</button>
          <button class="cancel-purchase" type="button" :disabled="billing.busy.value" @click="$emit('cancel')">Cancel selection</button>
          <p class="purchase-help text-center">Quote valid until {{ new Date(billing.purchase.value.expires_at).toLocaleTimeString() }}. Credits are added after payment is verified.</p>
        </template>
        <div v-else class="empty-order"><UiAppIcon name="plus" :size="28" /><p>Pick a package to get started.</p><span>Your credit total and checkout details will appear here.</span></div>
      </section>
    </div>
  </section>
</template>

<style scoped>
.purchase-header { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:24px; margin-bottom:28px; }
.purchase-header h2 { font-size:22px; font-weight:700; letter-spacing:-.035em; }
.purchase-header > div > p:last-child { color:var(--muted); font-size:14px; margin-top:8px; }
.currency-label { display:grid; gap:8px; font-size:12px; color:var(--muted); }
.currency-label select { min-height:44px; padding:10px 36px 10px 12px; border:1px solid var(--line); border-radius:10px; background:var(--surface); color:var(--text); font-size:13px; }
.purchase-grid { display:grid; gap:24px; grid-template-columns:minmax(0,1fr) 340px; align-items:start; }
.payment-methods legend { font-size:13px; font-weight:600; margin-bottom:12px; }
.method-option { display:flex; align-items:center; gap:10px; padding:16px; border:1px solid var(--line); background:var(--surface); border-radius:12px; cursor:pointer; }
.method-option input { accent-color:#4f46e5; }
.method-option > span { flex:1; min-width:0; }
.method-option strong { display:block; font-size:13px; }
.method-option > span > span { display:block; margin-top:4px; font-size:10px; color:var(--muted); }
.method-option > svg { color:var(--muted); flex-shrink:0; }
.method-selected { border-color:#6366f1; box-shadow:0 0 0 1px #6366f1; }
.package-card { padding:22px; border:1px solid var(--line); border-radius:14px; background:var(--surface); transition:border-color .2s,box-shadow .2s; }
.package-card:hover { border-color:#a5b4fc; }
.package-selected { border-color:#6366f1; box-shadow:0 0 0 1px #6366f1; background:color-mix(in srgb,var(--surface) 96%,#6366f1); }
.package-top { display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:14px; min-height:20px; }
.package-name { font-size:12px; font-weight:600; color:var(--muted); }
.selected-label { display:inline-flex; align-items:center; gap:4px; font-size:10px; color:var(--text); }
.package-details { display:flex; justify-content:space-between; align-items:center; gap:16px; }
.package-details h3 { font-size:28px; font-weight:700; letter-spacing:-.04em; line-height:1.25; }
.package-details h3 span { font-size:13px; font-weight:400; color:var(--muted); letter-spacing:0; }
.package-details p { margin-top:8px; font-size:14px; font-weight:600; }
.package-button { display:inline-flex; justify-content:center; align-items:center; gap:8px; min-height:44px; padding:10px 14px; border:1px solid var(--line); border-radius:9px; font-size:12px; font-weight:600; flex-shrink:0; transition:background .2s; }
.package-button:hover:not(:disabled),.package-button-selected { background:#6366f110; border-color:#6366f1; }
button:disabled,fieldset:disabled { opacity:.6; }
.package-skeleton { height:140px; border-radius:14px; background:color-mix(in srgb,var(--muted) 12%,var(--surface)); }
.purchase-help { color:var(--muted); font-size:11px; line-height:1.8; }
.confirmation { position:sticky; top:24px; padding:24px; scroll-margin-top:24px; }
.confirmation-heading { display:flex; align-items:center; gap:12px; padding-bottom:22px; border-bottom:1px solid var(--line); }
.confirmation-heading .eyebrow { font-size:10px; margin-bottom:4px; }
.confirmation h3 { font-size:17px; font-weight:700; letter-spacing:-.025em; }
.order-name { margin:24px 0 20px; font-size:14px; font-weight:600; }
.order-details { display:grid; gap:18px; margin-bottom:20px; }
.order-details > div { display:flex; align-items:center; justify-content:space-between; gap:12px; font-size:13px; }
.order-details dt { color:var(--muted); }
.order-details dd { font-weight:600; overflow-wrap:anywhere; }
.order-details .order-total { border-top:1px solid var(--line); padding-top:20px; }
.order-total dd { font-size:24px; letter-spacing:-.04em; }
.checkout-button { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; min-height:48px; margin-top:24px; padding:12px; border-radius:10px; background:#15803d; color:#fff; font-size:12px; font-weight:600; transition:background .2s; }
.checkout-button:hover:not(:disabled) { background:#166534; }
.checkout-button:focus-visible { outline-color:#15803d; }
.cancel-purchase { display:block; min-height:44px; width:100%; margin:8px 0; color:var(--muted); font-size:12px; }
.cancel-purchase:hover { color:var(--text); }
.empty-order { display:grid; justify-items:center; gap:12px; text-align:center; padding:40px 0 24px; color:var(--muted); }
.empty-order > p { font-size:14px; color:var(--text); font-weight:500; }
.empty-order > span { max-width:220px; font-size:12px; line-height:1.8; }
@media(max-width:1100px) { .purchase-grid { grid-template-columns:minmax(0,1fr); } .confirmation { position:static; } .confirmation-empty { display:none; } }
@media(max-width:480px) { .currency-label,.currency-label select { width:100%; } .package-card,.confirmation { padding:20px; } .package-details { flex-wrap:wrap; } .package-button { flex:1; } }
</style>
