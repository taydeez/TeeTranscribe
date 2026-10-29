<script setup lang="ts">
import type { BillingConfiguration } from '~/types/adminBilling'
import { billingActivities } from '~/config/adminBilling'
const configuration = defineModel<BillingConfiguration>({ required: true })
defineProps<{ disabled: boolean }>()
</script>

<template>
  <section class="surface p-5 sm:p-6">
    <h2 class="text-lg font-semibold">Task deadlines</h2>
    <p class="mt-2 text-sm leading-relaxed text-[var(--muted)]">Deadline = base minutes + media length in minutes × multiplier, rounded up and capped at the maximum. Text tasks use the base allowance. Recovery runs every minute, returns reserved credits, and releases the customer's task slot.</p>
    <p class="mt-2 text-xs text-[var(--muted)]">Changes apply to active tasks as well as new work. Polling does not extend a deadline. Default rules cover providers without an override.</p>
    <fieldset :disabled="disabled" class="mt-5 space-y-6">
      <legend class="sr-only">Task deadline settings</legend>
      <div v-for="activity in billingActivities" :key="activity.key">
        <h3 class="text-sm font-semibold">{{ activity.label }}</h3>
        <div v-for="(rule, provider) in configuration.task_deadlines[activity.key]" :key="provider" class="mt-3 grid gap-3 rounded-xl border border-[var(--line)] p-4 sm:grid-cols-4 sm:items-start">
          <p class="text-sm font-medium capitalize">{{ provider === 'default' ? 'Default' : provider }}</p>
          <label class="billing-label">Base minutes<input v-model.number="rule.base_minutes" required type="number" min="1" max="1440" step="1" class="billing-input"></label>
          <label class="billing-label">Length multiplier<input v-model.number="rule.duration_multiplier" required type="number" min="0" max="20" step="0.1" class="billing-input"></label>
          <label class="billing-label">Maximum minutes<input v-model.number="rule.max_minutes" required type="number" :min="rule.base_minutes" max="10080" step="1" class="billing-input"></label>
        </div>
      </div>
    </fieldset>
  </section>
</template>
