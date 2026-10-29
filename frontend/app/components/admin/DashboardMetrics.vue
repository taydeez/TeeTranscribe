<script setup lang="ts">
import type { DashboardReport } from '~/types/adminDashboard'
const props = defineProps<{ summary: DashboardReport['summary'] }>()
const money = (currency: string) => new Intl.NumberFormat(undefined, { style: 'currency', currency, maximumFractionDigits: 2 }).format((props.summary.revenue.find(item => item.currency === currency)?.amount_minor ?? 0) / 100)
const credits = (value: number) => new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(value / 100)
</script>
<template>
  <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article class="surface border-t-2 border-t-indigo-500 p-5"><p class="text-xs font-medium text-[var(--muted)]">Revenue · NGN</p><p class="mt-3 text-2xl font-semibold tracking-tight">{{ money('NGN') }}</p><p class="mt-3 text-xs text-[var(--muted)]">Successful purchases, before gateway fees</p></article>
    <article class="surface border-t-2 border-t-sky-500 p-5"><p class="text-xs font-medium text-[var(--muted)]">Revenue · USD</p><p class="mt-3 text-2xl font-semibold tracking-tight">{{ money('USD') }}</p><p class="mt-3 text-xs text-[var(--muted)]">Successful purchases, before gateway fees</p></article>
    <article class="surface border-t-2 border-t-violet-500 p-5"><p class="text-xs font-medium text-[var(--muted)]">Credits bought</p><p class="mt-3 text-2xl font-semibold tracking-tight">{{ credits(summary.credits_bought_units) }}</p><p class="mt-3 text-xs text-[var(--muted)]">From verified, paid purchases</p></article>
    <article class="surface border-t-2 border-t-teal-500 p-5"><p class="text-xs font-medium text-[var(--muted)]">Credits used</p><p class="mt-3 text-2xl font-semibold tracking-tight">{{ credits(summary.credits_used_units) }}</p><p class="mt-3 text-xs text-[var(--muted)]">Consumed credits; reservations excluded</p></article>
  </div>
</template>
