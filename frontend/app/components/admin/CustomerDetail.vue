<script setup lang="ts">
const { canView, canUpdate, canAdjust, result, loading, busy, error, actionError, success, load, updateAccess, adjustCredits } = useAdminCustomer()
const location = computed(() => result.value?.data.signupLocation)
const locationLabel = computed(() => [location.value?.city, location.value?.region, location.value?.country].filter(Boolean).join(', ') || 'Unknown')
function date(value: string | null) {
  return value ? new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—'
}
function credits(value: number) { return (value / 100).toLocaleString('en', { maximumFractionDigits: 2 }) }
</script>

<template>
  <section class="space-y-6">
    <NuxtLink to="/taydeez/customers" class="inline-flex items-center gap-2 text-sm text-indigo-600"><UiAppIcon name="back" :size="16" />All customers</NuxtLink>
    <div v-if="!canView" class="surface p-8" role="alert">You don’t have permission to view this customer.</div>
    <div v-else-if="loading" class="surface flex items-center justify-center gap-3 p-16 text-[var(--muted)]" role="status"><UiAppIcon name="loader" class="animate-spin" />Loading customer…</div>
    <div v-else-if="error" class="surface p-8" role="alert"><p class="text-red-600">{{ error }}</p><button type="button" class="button-secondary mt-4" @click="load">Try again</button></div>
    <template v-else-if="result">
      <div class="flex flex-wrap items-center justify-between gap-4"><div><h1 class="text-3xl font-semibold">{{ result.data.name }}</h1><p class="mt-2 break-all text-sm text-[var(--muted)]">{{ result.data.email }}</p></div><span class="rounded-full px-4 py-2 text-xs font-semibold capitalize" :class="result.data.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ result.data.status }}</span></div>
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="surface p-5"><p class="text-xs text-[var(--muted)]">Available credits</p><p class="mt-3 text-2xl font-semibold">{{ credits(result.balance.available_units) }}</p><p class="mt-2 text-xs text-[var(--muted)]">{{ credits(result.balance.reserved_units) }} reserved</p></div>
        <div class="surface p-5"><p class="text-xs text-[var(--muted)]">Signup location · approximate</p><p class="mt-3 text-sm font-semibold">{{ locationLabel }}</p><p class="mt-2 break-all text-xs text-[var(--muted)]">IP: {{ result.data.signupIp ?? 'Not recorded' }}</p></div>
        <div class="surface p-5"><p class="text-xs text-[var(--muted)]">Joined</p><p class="mt-3 text-sm font-semibold">{{ date(result.data.createdAt) }}</p><p class="mt-2 text-xs text-[var(--muted)]">{{ result.data.emailVerified ? 'Email verified' : 'Email unverified' }}</p></div>
        <div class="surface p-5"><p class="text-xs text-[var(--muted)]">Account access</p><p class="mt-3 text-sm font-semibold">{{ result.data.status === 'suspended' ? 'Suspended until ' + date(result.data.suspendedUntil) : result.data.status === 'blocked' ? 'Blocked until restored' : 'Active' }}</p><p v-if="result.data.restrictionReason" class="mt-2 text-xs text-[var(--muted)]">{{ result.data.restrictionReason }}</p></div>
      </div>
      <p v-if="success" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-700" role="status">{{ success }}</p>
      <p v-if="actionError" class="rounded-xl bg-red-50 p-4 text-sm text-red-700" role="alert">{{ actionError }}</p>
      <AdminCustomerControls :can-update="canUpdate" :can-adjust="canAdjust" :busy="busy" @access="updateAccess" @credits="adjustCredits" />
      <div class="surface overflow-hidden"><div class="border-b border-[var(--line)] p-6"><h2 class="font-semibold">Admin activity</h2><p class="mt-2 text-xs text-[var(--muted)]">Most recent 50 account actions.</p></div>
        <p v-if="!result.data.actions.length" class="p-8 text-sm text-[var(--muted)]">No admin actions yet.</p>
        <ul v-else class="divide-y divide-[var(--line)]"><li v-for="entry in result.data.actions" :key="entry.id" class="p-6"><div class="flex flex-wrap items-center justify-between gap-2"><p class="text-sm font-semibold capitalize">{{ entry.action.replaceAll('_', ' ') }}<span v-if="entry.metadata.credit_units != null"> · {{ credits(entry.metadata.credit_units) }} credits</span></p><time class="text-xs text-[var(--muted)]">{{ date(entry.createdAt) }}</time></div><p class="mt-2 whitespace-pre-wrap text-sm">{{ entry.reason }}</p><p class="mt-2 text-xs text-[var(--muted)]">By {{ entry.adminName ?? 'Former administrator' }}</p></li></ul>
      </div>
    </template>
  </section>
</template>
