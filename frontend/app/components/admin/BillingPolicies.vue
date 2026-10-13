<script setup lang="ts">
import type { BillingConfiguration } from '~/types/adminBilling'
const configuration = defineModel<BillingConfiguration>({ required: true })
defineProps<{ disabled: boolean }>()
</script>
<template>
  <section class="surface p-5 sm:p-6"><h2 class="text-lg font-semibold">Credit allowance &amp; time limits</h2>
    <fieldset :disabled="disabled" class="mt-5 grid gap-5 sm:grid-cols-2"><legend class="sr-only">Billing policy</legend>
      <label class="billing-label">Welcome credits<input :value="configuration.free_credits" required type="number" min="0" max="999999.99" step="0.01" class="billing-input" @input="configuration.free_credits = ($event.target as HTMLInputElement).value"><span class="billing-help">Granted once when a wallet is accessed with a positive allowance. Includes existing users who have never received a welcome grant. Set to 0 to disable.</span></label>
      <label class="billing-label">Usage quote expires after (minutes)<input v-model.number="configuration.quote_minutes" required type="number" min="1" max="1440" step="1" class="billing-input"><span class="billing-help">Applies to new usage quotes.</span></label>
      <label class="billing-label">Checkout expires after (minutes)<input v-model.number="configuration.checkout_minutes" required type="number" min="1" max="1440" step="1" class="billing-input"><span class="billing-help">Applies to new purchase quotes.</span></label>
      <label class="billing-label">Recover stalled processing after (hours)<input v-model.number="configuration.processing_timeout_hours" required type="number" min="26" max="168" step="1" class="billing-input"><span class="billing-help">Minimum 26 hours protects the provider callback window. Stalled task recovery runs every 10 minutes and frees concurrency capacity.</span></label>
    </fieldset>
  </section>
</template>
