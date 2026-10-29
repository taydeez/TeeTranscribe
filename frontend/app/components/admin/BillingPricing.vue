<script setup lang="ts">
import type { BillingConfiguration } from '~/types/adminBilling'
import { billingActivities } from '~/config/adminBilling'
const configuration = defineModel<BillingConfiguration>({ required: true })
defineProps<{ disabled: boolean }>()
</script>
<template>
  <section class="surface p-5 sm:p-6">
    <h2 class="text-lg font-semibold">Usage pricing</h2><p class="mt-1 text-sm leading-6 text-[var(--muted)]">Use provider/model prices, or set one customer price for an activity. Provider cost estimates remain separate.</p>
    <NuxtLink to="/taydeez/providers" class="mt-2 inline-block text-sm font-medium text-indigo-600 hover:underline">Manage provider/model prices →</NuxtLink>
    <fieldset :disabled="disabled" class="mt-5 divide-y divide-[var(--line)]"><legend class="sr-only">Activity prices</legend>
      <div v-for="activity in billingActivities" :key="activity.key" class="grid items-center gap-3 py-4 sm:grid-cols-3">
        <div><p class="text-sm font-semibold">{{ activity.label }}</p><p class="mt-1 text-xs text-[var(--muted)]">Per {{ activity.unit }}</p></div>
        <label class="billing-label"><span class="sr-only">{{ activity.label }} pricing mode</span><select v-model="configuration.activities[activity.key].mode" class="billing-input !mt-0"><option value="model">Provider/model pricing</option><option value="activity">Fixed activity rate</option></select></label>
        <label v-if="configuration.activities[activity.key].mode === 'activity'" class="billing-label"><span class="sr-only">{{ activity.label }} credits per {{ activity.unit }}</span><input :value="configuration.activities[activity.key].credits ?? ''" required type="number" min="0.01" max="999999.99" step="0.01" placeholder="Credits per unit" class="billing-input !mt-0" @input="configuration.activities[activity.key].credits = ($event.target as HTMLInputElement).value || null"></label>
        <p v-else class="text-xs text-[var(--muted)]">Uses the selected model’s saved price.</p>
      </div>
    </fieldset>
  </section>
</template>
