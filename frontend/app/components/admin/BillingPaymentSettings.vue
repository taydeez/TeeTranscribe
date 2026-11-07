<script setup lang="ts">
import type { BillingConfiguration } from '~/types/adminBilling'
import { billingCurrencies } from '~/config/adminBilling'
const configuration = defineModel<BillingConfiguration>({ required: true })
defineProps<{ disabled: boolean }>()
const gateways = [{ key: 'paystack', name: 'Paystack' }, { key: 'flutterwave', name: 'Flutterwave' }, { key: 'bachs', name: 'Bachs' }] as const
</script>
<template>
  <div class="grid items-start gap-6 xl:grid-cols-2">
    <section class="surface p-5 sm:p-6"><h2 class="text-lg font-semibold">Checkout settings</h2><p class="mt-1 text-sm leading-6 text-[var(--muted)]">Package prices are set directly in USD. No exchange rate or currency refresh is needed. The standard value is $0.01 per credit; package discounts can be configured separately.</p>
      <fieldset :disabled="disabled" class="mt-5 space-y-5"><legend class="sr-only">Checkout settings</legend>
        <div><p class="billing-label">Accept purchases in</p><div class="mt-3 flex gap-6"><label v-for="currency in billingCurrencies" :key="currency" class="flex items-center gap-2 text-sm"><input v-model="configuration.enabled_currencies" :value="currency" type="checkbox" class="accent-indigo-600">{{ currency }}</label></div></div>
        <label class="billing-label">Minimum checkout (USD)<input v-model="configuration.minimum_checkout.USD" required type="number" min="0.01" max="999999.99" step="0.01" class="billing-input"></label>
      </fieldset>
    </section>
    <section class="surface p-5 sm:p-6"><h2 class="text-lg font-semibold">Payment gateways</h2><p class="mt-1 text-sm leading-6 text-[var(--muted)]">Enable multiple gateways at once. Each requires valid backend credentials and merchant support for the selected currencies.</p>
      <fieldset v-for="gateway in gateways" :key="gateway.key" :disabled="disabled" class="mt-5 rounded-xl border border-[var(--line)] p-4"><legend class="px-2 text-sm font-semibold">{{ gateway.name }}</legend>
        <label class="flex items-center gap-2 text-sm"><input v-model="configuration.gateways[gateway.key].enabled" type="checkbox" class="accent-indigo-600">Accept new purchases</label>
        <p class="mt-4 text-xs font-medium text-[var(--muted)]">Supported checkout currencies</p><div class="mt-3 flex gap-6"><label v-for="currency in gateway.key === 'bachs' ? ['USD'] : billingCurrencies" :key="currency" class="flex items-center gap-2 text-sm"><input v-model="configuration.gateways[gateway.key].currencies" :value="currency" type="checkbox" class="accent-indigo-600">{{ currency }}</label></div>
      </fieldset>
      <p class="mt-4 text-xs leading-5 text-[var(--muted)]">Secrets and callback URLs stay in the backend environment. Disabling a gateway does not prevent verification of an existing purchase.</p>
    </section>
  </div>
</template>
