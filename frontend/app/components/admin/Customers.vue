<script setup lang="ts">
const auth = useAuthStore()
const { canView, searchInput, sort, result, loading, error, load, submitSearch, changeSort, goToPage } = useAdminCustomers()
function joinedDate(value: string | null) {
  return value ? new Intl.DateTimeFormat('en', { dateStyle: 'medium' }).format(new Date(value)) : '—'
}
</script>

<template>
  <section class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4"><div><p class="eyebrow mb-3">People</p><h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">Customers</h1><p class="mt-3 text-sm text-[var(--muted)]">Registered accounts on TeeTranscribe.</p></div><span v-if="result" class="rounded-full border border-[var(--line)] bg-[var(--surface)] px-4 py-2 text-sm text-[var(--muted)]">{{ result.meta.total.toLocaleString() }} {{ result.meta.total === 1 ? 'customer' : 'customers' }}</span></div>
    <div v-if="!canView" class="surface p-8 text-sm text-[var(--muted)]" role="alert">You don’t have permission to view customers. Contact your administrator.</div>
    <div v-else class="surface overflow-hidden">
      <div class="flex flex-col gap-4 border-b border-[var(--line)] p-5 sm:flex-row sm:items-center sm:justify-between">
        <form class="flex w-full gap-2 sm:max-w-lg" role="search" @submit.prevent="submitSearch">
          <div class="relative min-w-0 flex-1"><UiAppIcon name="search" :size="18" class="pointer-events-none absolute top-3 left-3 text-[var(--muted)]" /><label for="customer-search" class="sr-only">Search customers by name or email</label><input id="customer-search" v-model="searchInput" type="search" maxlength="255" placeholder="Search name or email…" class="h-10 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] pr-3 pl-10 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/15"></div>
          <button class="button-secondary" type="submit">Search</button>
        </form>
        <div class="flex items-center gap-2"><label for="customer-sort" class="shrink-0 text-xs text-[var(--muted)]">Sort by</label><select id="customer-sort" :value="sort" class="h-10 rounded-lg border border-[var(--line)] bg-[var(--surface)] px-3 text-sm" @change="changeSort(($event.target as HTMLSelectElement).value)"><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="name_asc">Name: A–Z</option><option value="name_desc">Name: Z–A</option></select></div>
      </div>
      <div v-if="loading" class="flex min-h-64 items-center justify-center gap-3 text-sm text-[var(--muted)]" role="status"><UiAppIcon name="loader" class="animate-spin" />Loading customers…</div>
      <div v-else-if="error" class="p-10 text-center" role="alert"><p class="text-sm text-red-600">{{ error }}</p><button class="button-secondary mt-4" type="button" @click="load">Try again</button></div>
      <div v-else-if="result && !result.data.length" class="px-6 py-16 text-center"><span class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><UiAppIcon name="users" :size="23" /></span><h2 class="mt-5 font-semibold">No customers found</h2><p class="mt-2 text-sm text-[var(--muted)]">Try another search or check back after customers join.</p></div>
      <div v-else-if="result" class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-left text-sm">
          <caption class="sr-only">Customer accounts, email verification and registration dates</caption>
          <thead class="bg-[var(--page)] text-xs text-[var(--muted)]"><tr><th scope="col" class="px-6 py-4 font-medium">Customer</th><th scope="col" class="px-6 py-4 font-medium">Email</th><th scope="col" class="px-6 py-4 font-medium">Verification</th><th scope="col" class="px-6 py-4 font-medium">Joined</th></tr></thead>
          <tbody class="divide-y divide-[var(--line)]"><tr v-for="customer in result.data" :key="customer.id" class="transition-colors hover:bg-[var(--page)]"><td class="px-6 py-5"><div class="flex items-center gap-3"><span class="grid size-9 shrink-0 place-items-center rounded-full bg-indigo-50 text-xs font-semibold text-indigo-600">{{ customer.name.charAt(0).toUpperCase() }}</span><NuxtLink v-if="auth.user?.permissions?.includes('View_User')" :to="`/taydeez/customers/${customer.id}`" class="font-medium text-indigo-600 hover:underline">{{ customer.name }}</NuxtLink><span v-else class="font-medium">{{ customer.name }}</span></div></td><td class="px-6 py-5 text-[var(--muted)]">{{ customer.email }}</td><td class="px-6 py-5"><span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium" :class="customer.emailVerified ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'"><span class="size-1.5 rounded-full bg-current" />{{ customer.emailVerified ? 'Verified' : 'Unverified' }}</span></td><td class="whitespace-nowrap px-6 py-5 text-[var(--muted)]">{{ joinedDate(customer.createdAt) }}</td></tr></tbody>
        </table>
      </div>
      <div v-if="result" class="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--line)] px-6 py-4">
        <p class="text-xs text-[var(--muted)]">Page {{ result.meta.currentPage }} of {{ result.meta.lastPage }}</p>
        <nav class="flex gap-2" aria-label="Customer list pagination"><button type="button" class="button-secondary disabled:cursor-not-allowed disabled:opacity-40" :disabled="loading || result.meta.currentPage <= 1" @click="goToPage(result.meta.currentPage - 1)"><UiAppIcon name="back" :size="15" />Previous</button><button type="button" class="button-secondary disabled:cursor-not-allowed disabled:opacity-40" :disabled="loading || result.meta.currentPage >= result.meta.lastPage" @click="goToPage(result.meta.currentPage + 1)">Next<UiAppIcon name="right" :size="15" /></button></nav>
      </div>
    </div>
  </section>
</template>
