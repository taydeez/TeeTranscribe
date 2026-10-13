<script setup lang="ts">
import type { DashboardDistribution } from '~/types/adminDashboard'
const props = defineProps<{ title: string; description: string; items: DashboardDistribution[]; unit: string }>()
const total = computed(() => props.items.reduce((sum, item) => sum + item.value, 0))
const visible = computed(() => props.items.length <= 10 ? props.items : [...props.items.slice(0, 9), { label: 'Other', value: props.items.slice(9).reduce((sum, item) => sum + item.value, 0), color: '#64748b' }])
const count = (value: number) => new Intl.NumberFormat().format(value)
const percent = (value: number) => total.value ? value / total.value * 100 : 0
</script>
<template>
  <section class="surface min-w-0 p-5 sm:p-6"><h2 class="font-semibold">{{ title }}</h2><p class="mt-2 text-xs leading-5 text-[var(--muted)]">{{ description }}</p>
    <p v-if="!total" class="mt-5 flex h-52 items-center justify-center rounded-xl bg-[var(--page)] px-6 text-center text-sm text-[var(--muted)]">No activity recorded in this period.</p>
    <ol v-else class="mt-6 space-y-5" :aria-label="title"><li v-for="item in visible" :key="item.label"><div class="mb-2 flex items-center justify-between gap-3 text-sm"><span class="truncate font-medium">{{ item.label }}</span><span class="shrink-0 text-xs text-[var(--muted)]">{{ count(item.value) }} {{ unit }} <span class="ml-2 font-medium">{{ percent(item.value).toFixed(1) }}%</span></span></div><div class="h-2 overflow-hidden rounded-full bg-[var(--page)]" aria-hidden="true"><div class="h-full rounded-full transition-[width] duration-300 motion-reduce:transition-none" :style="{ width: `${percent(item.value)}%`, backgroundColor: item.color }" /></div></li></ol>
  </section>
</template>
