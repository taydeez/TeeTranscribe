<script setup lang="ts">
import { accountChartSeries, creditChartSeries, dashboardColors, dashboardEnvironments, dashboardFeatureLabels, dashboardRanges, signInChartSeries } from '~/config/adminDashboard'
import type { DashboardChartSeries } from '~/types/adminDashboard'
const { canView, today, from, to, environment, report, loading, error, load, apply, range } = useAdminDashboard()
const route = useRoute()
const router = useRouter()
const currency = computed<'NGN' | 'USD'>({
  get: () => route.query.revenue_currency === 'USD' ? 'USD' : 'NGN',
  set: value => { void router.replace({ query: { ...route.query, revenue_currency: value } }) },
})
const revenueSeries = computed<DashboardChartSeries[]>(() => [{ key: currency.value === 'NGN' ? 'revenue_ngn_minor' : 'revenue_usd_minor', label: currency.value, color: '#4f46e5', divisor: 100 }])
const features = computed(() => report.value?.features.map((item, index) => ({ label: dashboardFeatureLabels[item.feature] ?? item.feature, value: item.requests, color: dashboardColors[index % dashboardColors.length] ?? '#4f46e5' })) ?? [])
const locations = computed(() => report.value?.locations.map((item, index) => ({ label: item.country, value: item.sign_ins, color: dashboardColors[index % dashboardColors.length] ?? '#4f46e5' })) ?? [])
const number = (value: number) => new Intl.NumberFormat().format(value)
const field = 'min-h-10 rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 text-sm'
</script>

<template>
  <section class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4"><div><p class="eyebrow mb-3">Business overview</p><h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">Your app, in numbers.</h1><p class="mt-3 text-sm text-[var(--muted)]">Revenue, customer growth and the tools people use.</p></div><button v-if="canView" class="button-secondary" :disabled="loading" @click="load"><UiAppIcon :name="loading ? 'loader' : 'clock'" :class="{ 'animate-spin': loading }" :size="16" />Refresh</button></header>
    <p v-if="!canView" class="surface p-6 text-sm">Your role does not have permission to view dashboard analytics.</p>
    <template v-else>
      <form class="surface flex flex-wrap items-end gap-3 p-4" @submit.prevent="apply">
        <div class="flex gap-1 rounded-xl bg-[var(--page)] p-1"><button v-for="preset in dashboardRanges" :key="preset.days" type="button" class="min-h-9 rounded-lg px-3 text-xs font-medium hover:bg-[var(--surface)] disabled:opacity-50" :disabled="loading" @click="range(preset.days)">{{ preset.label }}</button></div>
        <label class="flex flex-col gap-1 text-xs font-medium">From (UTC)<input v-model="from" :class="field" type="date" required :max="to || today"></label>
        <label class="flex flex-col gap-1 text-xs font-medium">To (UTC)<input v-model="to" :class="field" type="date" required :min="from" :max="today"></label>
        <label class="flex flex-col gap-1 text-xs font-medium">Payment environment<select v-model="environment" :class="field"><option v-for="option in dashboardEnvironments" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
        <button class="button-primary" :disabled="loading">Apply</button>
      </form>
      <div v-if="loading" class="grid gap-5 md:grid-cols-2" role="status" aria-label="Loading dashboard"><div v-for="index in 6" :key="index" class="surface h-52 animate-pulse motion-reduce:animate-none"><div class="m-6 h-4 w-32 rounded bg-[var(--line)]" /><div class="mx-6 mt-12 h-16 rounded bg-[var(--page)]" /></div><span class="sr-only">Loading dashboard…</span></div>
      <div v-else-if="error" class="surface p-8 text-center"><p class="text-sm text-rose-600" role="alert">{{ error }}</p><button class="button-secondary mt-4" @click="load">Try again</button></div>
      <template v-else-if="report">
        <div class="flex flex-wrap justify-between gap-2 text-xs text-[var(--muted)]"><p>{{ report.from }} — {{ report.to }} · Daily totals in UTC · Customers only</p><p>Updated {{ new Date(report.generated_at).toLocaleTimeString() }}</p></div>
        <AdminDashboardMetrics :summary="report.summary" />
        <div class="grid gap-5 xl:grid-cols-2">
          <AdminDashboardTrendChart title="Revenue" description="Gross payments received on each day. Currencies are shown separately." :points="report.daily" :series="revenueSeries" :unit="currency"><label><span class="sr-only">Revenue currency</span><select v-model="currency" :class="field"><option>NGN</option><option>USD</option></select></label></AdminDashboardTrendChart>
          <AdminDashboardTrendChart title="Credit activity" description="Bought credits follow the payment environment filter. Usage includes all consumed credits." :points="report.daily" :series="creditChartSeries" unit="Credits" />
          <AdminDashboardTrendChart title="Sign-in frequency" description="Successful customer logins. Tracking starts with this dashboard update; failed logins are excluded." :points="report.daily" :series="signInChartSeries"><span class="text-sm font-semibold text-indigo-600">{{ number(report.summary.sign_ins) }} sign-ins · {{ number(report.summary.unique_signing_users) }} customers</span></AdminDashboardTrendChart>
          <AdminDashboardTrendChart title="Account creation" description="New customer accounts created each day. Administrator accounts are excluded." :points="report.daily" :series="accountChartSeries"><span class="text-sm font-semibold text-teal-600">{{ number(report.summary.new_accounts) }} new accounts</span></AdminDashboardTrendChart>
          <AdminDashboardDistribution title="Most used features" description="Accepted feature requests, including work still processing and failed work. Unconfirmed quotes and export retries are excluded." :items="features" unit="requests" />
          <AdminDashboardDistribution title="Sign-in locations" description="Distribution of successful sign-ins by IP-based country. Unresolved or private IP addresses appear as Unknown. This is an estimate of where the app is accessed." :items="locations" unit="sign-ins" />
        </div>
      </template>
    </template>
  </section>
</template>
