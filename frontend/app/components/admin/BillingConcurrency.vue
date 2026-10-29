<script setup lang="ts">
import type { BillingConfiguration } from '~/types/adminBilling'
import { concurrencyLimits } from '~/config/adminConcurrency'
const configuration = defineModel<BillingConfiguration>({ required: true })
defineProps<{ disabled: boolean }>()
</script>

<template>
  <section class="surface p-5 sm:p-6">
    <h2 class="text-lg font-semibold">Concurrent tasks per customer</h2>
    <p class="mt-2 text-sm text-[var(--muted)]">Queued and processing tasks count until their final downloads are ready. The overall limit also applies to each activity. Uploads and price quotes are separate.</p>
    <fieldset :disabled="disabled" class="mt-5 grid gap-5 sm:grid-cols-2">
      <legend class="sr-only">Concurrency limits</legend>
      <label v-for="limit in concurrencyLimits" :key="limit.key" class="billing-label">{{ limit.label }}
        <input v-model.number="configuration.concurrency[limit.key]" required type="number" min="1" max="100" step="1" class="billing-input">
      </label>
    </fieldset>
    <p class="mt-4 text-xs text-[var(--muted)]">Changes apply to new tasks and download regeneration. Existing work continues if limits are lowered. Retries reuse the same task. Overdue tasks release their slots using the activity and provider deadlines below.</p>
  </section>
</template>
