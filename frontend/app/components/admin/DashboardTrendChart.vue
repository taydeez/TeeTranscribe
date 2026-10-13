<script setup lang="ts">
import type { DashboardChartSeries, DashboardDay } from '~/types/adminDashboard'
const props = defineProps<{ title: string; description: string; points: DashboardDay[]; series: DashboardChartSeries[]; unit?: string }>()
const active = ref(0)
const id = useId()
const hasValues = computed(() => props.points.some(point => props.series.some(series => point[series.key] > 0)))
const maximum = computed(() => Math.max(1, ...props.points.flatMap(point => props.series.map(series => point[series.key] / (series.divisor ?? 1)))))
const ceiling = computed(() => Math.ceil(maximum.value / 4) * 4)
const ticks = computed(() => Array.from({ length: 5 }, (_, index) => ceiling.value * index / 4))
const selected = computed(() => props.points[Math.min(active.value, props.points.length - 1)])
const number = (value: number) => new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(value)
const compact = (value: number) => new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 }).format(value)
const date = (value: string) => new Date(value + 'T00:00:00Z').toLocaleDateString(undefined, { month: 'short', day: 'numeric', timeZone: 'UTC' })
function x(index: number) { return props.points.length <= 1 ? 332 : 52 + index / (props.points.length - 1) * 560 }
function y(value: number) { return 180 - value / ceiling.value * 150 }
function line(series: DashboardChartSeries) { return props.points.map((point, index) => `${x(index)},${y(point[series.key] / (series.divisor ?? 1))}`).join(' ') }
function inspect(event: PointerEvent) {
  const svg = event.currentTarget as SVGSVGElement
  const box = svg.getBoundingClientRect()
  active.value = Math.max(0, Math.min(props.points.length - 1, Math.round(((event.clientX - box.left) / box.width * 640 - 52) / 560 * (props.points.length - 1))))
}
watch(() => props.points, () => { active.value = Math.max(0, props.points.length - 1) }, { immediate: true })
</script>

<template>
  <section class="surface min-w-0 p-5 sm:p-6">
    <header><div class="flex flex-wrap items-center justify-between gap-3"><h2 class="font-semibold">{{ title }}</h2><slot /></div><p class="mt-2 text-xs leading-5 text-[var(--muted)]">{{ description }}</p></header>
    <div class="mt-5 flex flex-wrap gap-x-5 gap-y-2 text-xs text-[var(--muted)]"><span v-for="seriesItem in series" :key="seriesItem.key" class="inline-flex items-center gap-2"><span class="size-2 rounded-full" :style="{ backgroundColor: seriesItem.color }" />{{ seriesItem.label }}</span><span v-if="unit" class="ml-auto">{{ unit }}</span></div>
    <div v-if="!hasValues" class="flex h-60 items-center justify-center rounded-xl bg-[var(--page)] px-6 text-center text-sm text-[var(--muted)]">No activity recorded in this period.</div>
    <template v-else>
      <svg viewBox="0 0 640 220" class="mt-3 block w-full overflow-visible" role="img" :aria-label="`${title}, daily values. Use the date slider or data table to inspect.`" @pointermove="inspect">
        <defs><linearGradient :id="id" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" :stop-color="series[0]?.color" stop-opacity=".13" /><stop offset="100%" :stop-color="series[0]?.color" stop-opacity="0" /></linearGradient></defs>
        <g v-for="tick in ticks" :key="tick"><line x1="52" x2="612" :y1="y(tick)" :y2="y(tick)" stroke="var(--line)" stroke-dasharray="3 5" /><text x="42" :y="y(tick) + 4" text-anchor="end" fill="var(--muted)" font-size="11">{{ compact(tick) }}</text></g>
        <polygon v-if="series.length === 1 && series[0]" :points="`${x(0)},180 ${line(series[0])} ${x(points.length - 1)},180`" :fill="`url(#${id})`" />
        <polyline v-for="seriesItem in series" :key="seriesItem.key" :points="line(seriesItem)" fill="none" :stroke="seriesItem.color" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
        <line :x1="x(active)" :x2="x(active)" y1="26" y2="180" stroke="var(--muted)" stroke-dasharray="3 4" opacity=".5" />
        <circle v-for="seriesItem in series" :key="seriesItem.key" :cx="x(active)" :cy="y((selected?.[seriesItem.key] ?? 0) / (seriesItem.divisor ?? 1))" r="4" :fill="seriesItem.color" stroke="var(--surface)" stroke-width="2" />
        <text x="52" y="207" fill="var(--muted)" font-size="11">{{ points[0] ? date(points[0].date) : '' }}</text><text x="612" y="207" text-anchor="end" fill="var(--muted)" font-size="11">{{ points.at(-1) ? date(points.at(-1)!.date) : '' }}</text>
      </svg>
      <label class="mt-1 block"><span class="sr-only">Inspect a date in {{ title }}</span><input v-model.number="active" class="h-3 w-full cursor-pointer accent-indigo-600" type="range" min="0" :max="points.length - 1" :aria-valuetext="selected ? date(selected.date) : ''"></label>
      <div v-if="selected" class="mt-3 flex flex-wrap justify-between gap-2 rounded-lg bg-[var(--page)] px-3 py-2 text-xs"><span class="text-[var(--muted)]">{{ date(selected.date) }}</span><span v-for="seriesItem in series" :key="seriesItem.key" class="font-medium">{{ seriesItem.label }}: {{ number(selected[seriesItem.key] / (seriesItem.divisor ?? 1)) }}</span></div>
      <details class="mt-4 text-xs text-[var(--muted)]"><summary class="cursor-pointer hover:text-indigo-600">View data table</summary><div class="mt-3 max-h-52 overflow-auto"><table class="w-full text-left"><caption class="sr-only">{{ title }} daily data</caption><thead><tr><th class="p-2">Date (UTC)</th><th v-for="seriesItem in series" :key="seriesItem.key" class="p-2">{{ seriesItem.label }}</th></tr></thead><tbody><tr v-for="point in points" :key="point.date" class="border-t border-[var(--line)]"><td class="p-2">{{ point.date }}</td><td v-for="seriesItem in series" :key="seriesItem.key" class="p-2">{{ number(point[seriesItem.key] / (seriesItem.divisor ?? 1)) }}</td></tr></tbody></table></div></details>
    </template>
  </section>
</template>
