<script setup lang="ts">
const auth = useAuthStore()
const canViewCustomers = computed(() => auth.user?.permissions?.includes('ViewAny_User') ?? false)
</script>

<template>
  <section class="space-y-8">
    <div><p class="eyebrow mb-3">Administration</p><h1 class="text-3xl font-semibold tracking-tight text-[var(--text)] sm:text-4xl">Welcome back, {{ auth.user?.name }}.</h1><p class="mt-3 text-sm leading-6 text-[var(--muted)]">Your place to manage the people using TeeTranscribe.</p></div>
    <div class="grid gap-5 xl:grid-cols-3">
      <div class="surface relative overflow-hidden p-6 sm:p-8 xl:col-span-2">
        <span class="mb-6 inline-flex size-12 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><UiAppIcon name="users" :size="24" /></span>
        <h2 class="text-xl font-semibold">Customers</h2><p class="mt-3 max-w-md text-sm leading-6 text-[var(--muted)]">Find customer accounts, check email verification, and see when they joined.</p>
        <NuxtLink v-if="canViewCustomers" to="/taydeez/customers" class="button-primary mt-7">View customers <UiAppIcon name="arrow" :size="17" /></NuxtLink>
        <p v-else class="mt-7 text-sm text-[var(--muted)]">Your role does not have permission to view customers.</p>
      </div>
      <div class="surface p-6 sm:p-8"><p class="text-xs font-semibold tracking-wider text-[var(--muted)] uppercase">Your access</p><h2 class="mt-5 text-xl font-semibold">Administrator</h2><p class="mt-2 break-all text-sm text-[var(--muted)]">{{ auth.user?.email }}</p><div class="mt-6 flex items-center gap-2 text-sm text-emerald-600"><UiAppIcon name="check" :size="18" />Email code verified</div><p class="mt-4 text-xs leading-5 text-[var(--muted)]">Access to each module follows your assigned permissions.</p></div>
    </div>
  </section>
</template>
