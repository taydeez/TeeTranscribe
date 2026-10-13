<script setup lang="ts">
import type { BillingConfiguration } from '~/types/adminBilling'
import { billingCurrencies } from '~/config/adminBilling'
const configuration = defineModel<BillingConfiguration>({ required: true })
defineProps<{ disabled: boolean }>()
const gateways = [{ key: 'paystack', name: 'Paystack' }, { key: 'flutterwave', name: 'Flutterwave' }] as const
const fxDate = computed({
  get: () => {
    if (!configuration.value.fx.updated_at) return ''
    const date = new Date(configuration.value.fx.updated_at)
    if (Number.isNaN(date.getTime())) return ''
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
  },
  set: (value: string) => { configuration.value.fx.updated_at = value ? new Date(value).toISOString() : null },
})
</script>
<template>
  <div class="grid items-start gap-6 xl:grid-cols-2">
    <section class="surface p-5 sm:p-6"><h2 class="text-lg font-semibold">Currencies &amp; exchange rate</h2><p class="mt-1 text-sm leading-6 text-[var(--muted)]">1 credit = ₦1. Both currencies fund the same credit wallet.</p>
      <fieldset :disabled="disabled" class="mt-5 space-y-5"><legend class="sr-only">Currency settings</legend>
        <div><p class="billing-label">Accept purchases in</p><div class="mt-3 flex gap-6"><label v-for="currency in billingCurrencies" :key="currency" class="flex items-center gap-2 text-sm"><input v-model="configuration.enabled_currencies" :value="currency" type="checkbox" class="accent-indigo-600">{{ currency }}</label></div></div>
        <label class="billing-label">NGN per 1 USD<input :value="configuration.fx.ngn_per_usd ?? ''" type="number" min="0.000001" max="1000000" step="0.000001" class="billing-input" placeholder="e.g. 1500" @input="configuration.fx.ngn_per_usd = ($event.target as HTMLInputElement).value || null"></label>
        <label class="billing-label">Exchange rate updated at (your local time)<input v-model="fxDate" :required="!!configuration.fx.ngn_per_usd" type="datetime-local" class="billing-input"></label>
        <button type="button" class="text-sm font-medium text-indigo-600 hover:underline disabled:opacity-50" @click="configuration.fx.updated_at = new Date().toISOString()">Mark rate as updated now</button>
        <label class="billing-label">Exchange rate valid for (hours)<input v-model.number="configuration.fx.max_age_hours" required type="number" min="1" max="720" step="1" class="billing-input"><span class="billing-help">New USD quotes stop when the rate expires. NGN remains available.</span></label>
        <div class="grid gap-4 sm:grid-cols-2"><label v-for="currency in billingCurrencies" :key="currency" class="billing-label">Minimum checkout ({{ currency }})<input :value="configuration.minimum_checkout[currency]" required type="number" min="0.01" max="999999.99" step="0.01" class="billing-input" @input="configuration.minimum_checkout[currency] = ($event.target as HTMLInputElement).value"></label></div>
      </fieldset>
    </section>
    <section class="surface p-5 sm:p-6"><h2 class="text-lg font-semibold">Payment gateways</h2><p class="mt-1 text-sm leading-6 text-[var(--muted)]">Enable multiple gateways at once. Each requires valid backend credentials and merchant support for the selected currencies.</p>
      <fieldset v-for="gateway in gateways" :key="gateway.key" :disabled="disabled" class="mt-5 rounded-xl border border-[var(--line)] p-4"><legend class="px-2 text-sm font-semibold">{{ gateway.name }}</legend>
        <label class="flex items-center gap-2 text-sm"><input v-model="configuration.gateways[gateway.key].enabled" type="checkbox" class="accent-indigo-600">Accept new purchases</label>
        <p class="mt-4 text-xs font-medium text-[var(--muted)]">Supported checkout currencies</p><div class="mt-3 flex gap-6"><label v-for="currency in billingCurrencies" :key="currency" class="flex items-center gap-2 text-sm"><input v-model="configuration.gateways[gateway.key].currencies" :value="currency" type="checkbox" class="accent-indigo-600">{{ currency }}</label></div>
      </fieldset>
      <p class="mt-4 text-xs leading-5 text-[var(--muted)]">Secrets and callback URLs stay in the backend environment. Disabling a gateway does not prevent verification of an existing purchase.</p>
    </section>
  </div>
</template>
