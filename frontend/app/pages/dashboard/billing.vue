<script setup lang="ts">
import type { BillingHistoryType } from '~/types/billing'
import { formatCredits } from '~/utils/credits'

definePageMeta({ layout: 'dashboard', title: 'Credits and billing' })
const billing = useCreditBilling()
const route = useRoute()
const historyType = computed<BillingHistoryType>(() => route.query.history === 'ledger' ? 'ledger' : route.query.history === 'usage' ? 'usage' : 'payments')
const reference = computed(() => typeof route.query.reference === 'string' ? route.query.reference : typeof route.query.tx_ref === 'string' ? route.query.tx_ref : '')
onMounted(() => { if (reference.value) billing.verify(reference.value) })
</script>

<template>
  <div class="billing-page">
    <section class="page-intro"><p class="eyebrow">YOUR WORKSPACE WALLET</p><h1>Credits and billing</h1><p>A single balance for everything you create. Top up when you need to.</p></section>
    <div class="wallet-grid">
      <section class="wallet-balance" aria-labelledby="available-heading"><div class="wallet-label"><span id="available-heading">Available credits</span><UiAppIcon name="billing" :size="22" /></div><p class="balance-value">{{ billing.balance.value ? formatCredits(billing.balance.value.available_units) : '—' }}</p><p class="wallet-description">Ready for your next transcription, translation, or dub.</p><div class="wallet-footer"><span>1 credit = ₦1</span><span>NGN &amp; USD purchases</span></div></section>
      <section class="reserved-balance surface" aria-labelledby="reserved-heading"><span class="feature-icon"><UiAppIcon name="clock" /></span><p id="reserved-heading" class="reserved-label">Reserved credits</p><p class="reserved-value">{{ billing.balance.value ? formatCredits(billing.balance.value.reserved_units) : '—' }}</p><p>Held while your tasks are processing. Returned if a task fails.</p></section>
    </div>
    <p v-if="billing.error.value" class="rounded-xl bg-rose-50 p-4 text-sm text-rose-700" role="alert">{{ billing.error.value }}</p>
    <div v-if="billing.notice.value" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800" role="status">{{ billing.notice.value }}</div>
    <button v-if="reference" class="button-secondary" :disabled="billing.busy.value" type="button" @click="billing.verify(reference)">Check payment status</button>
    <BillingCreditPurchase :billing="billing" @cancel="billing.purchase.value = null" />
    <section class="history-section" aria-labelledby="history-heading">
      <div class="history-header"><div><h2 id="history-heading">Your billing history</h2><p>Track payments, credit usage, and download invoices.</p></div><nav class="history-tabs" aria-label="Billing history"><NuxtLink v-for="tab in ['payments', 'usage', 'ledger'] as const" :key="tab" :to="{ query: { history: tab } }" :class="{ 'history-tab-active': historyType === tab }" :aria-current="historyType === tab ? 'page' : undefined">{{ tab === 'ledger' ? 'Credit activity' : tab === 'usage' ? 'Usage' : 'Payments' }}</NuxtLink></nav></div>
      <BillingCreditHistory :type="historyType" :version="billing.historyVersion.value" />
    </section>
  </div>
</template>

<style scoped>
.billing-page { max-width:1152px; display:grid; gap:28px; }
.billing-page .page-intro { margin-bottom:0; }
.wallet-grid { display:grid; grid-template-columns:1.6fr 1fr; gap:20px; margin-bottom:12px; }
.wallet-balance { position:relative; overflow:hidden; border-radius:18px; padding:28px; background:linear-gradient(115deg,#172554,#312e81); color:#fff; }
.wallet-balance::after { content:''; position:absolute; width:230px; height:230px; border:1px solid #ffffff0d; border-radius:50%; right:-80px; top:-60px; box-shadow:0 0 0 40px #ffffff04; pointer-events:none; }
.wallet-label { display:flex; align-items:center; justify-content:space-between; gap:12px; color:#c7d2fe; font-size:13px; }
.balance-value { margin-top:18px; font-size:clamp(36px,5vw,48px); font-weight:700; line-height:1.2; letter-spacing:-.04em; overflow-wrap:anywhere; }
.wallet-description { margin-top:12px; font-size:12px; line-height:1.8; color:#c7d2fe; }
.wallet-footer { display:flex; flex-wrap:wrap; justify-content:space-between; gap:12px; border-top:1px solid #ffffff20; padding-top:18px; margin-top:24px; color:#c7d2fe; font-size:11px; }
.reserved-balance { padding:24px 28px; }
.reserved-label { margin-top:16px; font-size:13px; color:var(--muted); }
.reserved-value { margin-top:8px; font-size:32px; font-weight:600; letter-spacing:-.04em; overflow-wrap:anywhere; }
.reserved-balance > p:last-child { max-width:300px; margin-top:12px; color:var(--muted); font-size:12px; line-height:1.8; }
.history-section { padding-top:32px; margin-top:8px; border-top:1px solid var(--line); min-width:0; }
.history-header { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:20px; margin-bottom:24px; }
.history-header h2 { font-size:22px; font-weight:700; letter-spacing:-.035em; }
.history-header p { color:var(--muted); font-size:13px; margin-top:8px; line-height:1.7; }
.history-tabs { display:flex; padding:4px; gap:4px; border:1px solid var(--line); border-radius:10px; background:color-mix(in srgb,var(--muted) 5%,var(--page)); }
.history-tabs a { display:flex; align-items:center; justify-content:center; padding:10px 14px; min-height:44px; font-size:12px; color:var(--muted); border-radius:7px; }
.history-tabs .history-tab-active { background:var(--surface); color:var(--text); font-weight:600; box-shadow:0 1px 4px #0f172a0a; }
@media(max-width:640px) { .wallet-grid { grid-template-columns:1fr; gap:16px; } .wallet-balance,.reserved-balance { padding:24px; } .history-tabs { width:100%; } .history-tabs a { flex:1; padding:10px; } }
</style>
