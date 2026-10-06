<script setup lang="ts">
import type { BillingCurrency, BillingHistoryType } from '~/types/billing'
import { formatCredits, formatMoney } from '~/utils/credits'

definePageMeta({ layout: 'dashboard', title: 'Credits and billing' })
const billing = useCreditBilling()
const route = useRoute()
const historyType = computed<BillingHistoryType>(() => route.query.history === 'ledger' ? 'ledger' : route.query.history === 'usage' ? 'usage' : 'payments')
const reference = computed(() => typeof route.query.reference === 'string' ? route.query.reference : typeof route.query.tx_ref === 'string' ? route.query.tx_ref : '')
onMounted(() => { if (reference.value) billing.verify(reference.value) })
</script>

<template>
  <div class="max-w-6xl space-y-7">
    <section class="page-intro"><p class="eyebrow">YOUR ACCOUNT</p><h1>Credits and billing</h1><p>Buy credits when you need them. See the price before every transcription.</p></section>
    <div class="grid gap-4 sm:grid-cols-2">
      <section class="surface relative overflow-hidden p-6"><div class="absolute -right-6 -top-6 h-28 w-28 rounded-full bg-indigo-500/10" /><p class="text-sm text-slate-500">Available credits</p><p class="mt-3 text-4xl font-semibold tracking-tight">{{ billing.balance.value ? formatCredits(billing.balance.value.available_units) : '—' }}</p><p class="mt-3 text-xs text-slate-500">1 credit = ₦1 · One balance for NGN and USD purchases</p></section>
      <section class="surface p-6"><p class="text-sm text-slate-500">Reserved credits</p><p class="mt-3 text-4xl font-semibold tracking-tight">{{ billing.balance.value ? formatCredits(billing.balance.value.reserved_units) : '—' }}</p><p class="mt-3 text-xs text-slate-500">Held for processing. Released if transcription fails.</p></section>
    </div>
    <p v-if="billing.error.value" class="rounded-xl bg-rose-50 p-4 text-sm text-rose-700" role="alert">{{ billing.error.value }}</p>
    <div v-if="billing.notice.value" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800" role="status">{{ billing.notice.value }}</div>
    <button v-if="reference" class="button-secondary" :disabled="billing.busy.value" type="button" @click="billing.verify(reference)">Check payment status</button>
    <section>
      <div class="mb-5 flex flex-wrap items-center justify-between gap-4"><div><h2 class="text-xl font-semibold">Add credits</h2><p class="mt-1 text-sm text-slate-500">No subscription required. Your currency is suggested from your time zone; you can change it.</p></div><label class="flex items-center gap-2 text-sm">Currency <select class="rounded-lg border border-slate-300 bg-[var(--surface)] px-3 py-2" :value="billing.currency.value" :disabled="billing.busy.value || billing.loading.value" @change="billing.changeCurrency(($event.target as HTMLSelectElement).value as BillingCurrency)"><option value="NGN">NGN · Nigerian naira</option><option value="USD">USD · US dollar</option></select></label></div>
      <fieldset v-if="billing.catalog.value?.payment_methods.length" class="mb-5" :disabled="billing.busy.value || billing.loading.value">
        <legend class="mb-3 text-sm font-semibold">Choose a payment method</legend>
        <div class="grid gap-3 sm:grid-cols-2">
          <label v-for="method in billing.catalog.value.payment_methods" :key="method.id" class="surface flex cursor-pointer items-start gap-3 p-4 transition-colors" :class="billing.paymentMethod.value === method.code ? 'border-indigo-500 ring-1 ring-indigo-500' : 'hover:border-indigo-300'">
            <input type="radio" name="payment-method" :value="method.code" :checked="billing.paymentMethod.value === method.code" class="mt-1 accent-indigo-600" @change="billing.changePaymentMethod(method.code)">
            <span><span class="block font-semibold">{{ method.name }}</span><span v-if="method.description" class="mt-1 block text-sm text-slate-500">{{ method.description }}</span></span>
          </label>
        </div>
      </fieldset>
      <p v-if="billing.loading.value" class="p-4 text-sm text-slate-500">Loading packages…</p>
      <div v-else-if="billing.catalog.value" class="grid gap-4 md:grid-cols-3">
        <section v-for="pack in billing.catalog.value.data" :key="pack.id" class="surface flex flex-col p-6 transition motion-safe:hover:-translate-y-1"><p class="text-sm font-semibold text-indigo-600">{{ pack.name }}</p><h3 class="mt-4 text-3xl font-semibold">{{ formatCredits(pack.credit_units) }} <span class="text-base font-normal text-slate-500">credits</span></h3><p class="mt-3 text-xl font-semibold">{{ formatMoney(pack.amount_minor, pack.currency) }}</p><p class="mt-2 text-xs text-slate-500">Cost per activity depends on the provider and audio length.</p><button class="button-primary mt-6 w-full" type="button" :disabled="billing.busy.value || !billing.catalog.value.payments_enabled || !billing.paymentMethod.value" @click="billing.selectPackage(pack.id)">{{ billing.catalog.value.payments_enabled ? (billing.paymentMethod.value ? 'Choose package' : 'Choose payment method') : 'Payments not configured' }}</button></section>
      </div>
      <button v-else class="button-secondary" type="button" @click="billing.refresh">Retry packages</button>
    </section>
    <section v-if="billing.purchase.value" class="surface border-indigo-200 p-6" aria-labelledby="purchase-heading">
      <h2 id="purchase-heading" class="text-xl font-semibold">Confirm your purchase</h2><p class="mt-3">{{ formatCredits(billing.purchase.value.credit_units) }} credits for <strong>{{ formatMoney(billing.purchase.value.amount_minor, billing.purchase.value.currency) }}</strong></p>
      <p class="mt-2 text-sm text-slate-500">Payment via {{ billing.catalog.value?.payment_methods.find(method => method.code === billing.purchase.value?.gateway)?.name ?? billing.purchase.value.gateway }}</p>
      <p v-if="billing.purchase.value.fx_ngn_per_usd_micros" class="mt-2 text-sm text-slate-500">Locked exchange rate: $1 = ₦{{ (billing.purchase.value.fx_ngn_per_usd_micros / 1000000).toLocaleString() }}.</p><p class="mt-2 text-xs text-slate-500">Quote valid until {{ new Date(billing.purchase.value.expires_at).toLocaleTimeString() }}. Credits are added after the payment is verified.</p>
      <div class="mt-5 flex flex-wrap gap-3"><button class="button-primary" type="button" :disabled="billing.busy.value" @click="billing.checkout">{{ billing.busy.value ? 'Preparing checkout…' : 'Continue to secure checkout' }}</button><button class="button-secondary" type="button" :disabled="billing.busy.value" @click="billing.purchase.value = null">Cancel</button></div>
    </section>
    <section><div class="mb-4 flex flex-wrap items-center justify-between gap-3"><h2 class="text-xl font-semibold">History</h2><nav class="flex gap-1 rounded-xl bg-slate-100 p-1" aria-label="Billing history"><NuxtLink v-for="tab in ['payments', 'usage', 'ledger'] as const" :key="tab" :to="{ query: { history: tab } }" class="rounded-lg px-3 py-2 text-sm capitalize" :class="historyType === tab ? 'bg-white font-semibold text-indigo-700 shadow-sm' : 'text-slate-500'">{{ tab === 'ledger' ? 'Credit activity' : tab }}</NuxtLink></nav></div><BillingCreditHistory :type="historyType" :version="billing.historyVersion.value" /></section>
  </div>
</template>
