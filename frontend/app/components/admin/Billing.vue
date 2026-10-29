<script setup lang="ts">
const { record, draft, reason, loading, busy, error, success, dirty, can, load, reset, save } = useAdminBilling()
const readOnly = computed(() => busy.value || !can('Update_Pricing'))
</script>
<template>
  <section class="admin-billing space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4"><div><h1 class="text-3xl font-semibold">Credits &amp; billing</h1><p class="mt-3 max-w-2xl text-sm leading-6 text-[var(--muted)]">Manage credit packages, usage pricing and payments in one place.</p></div><span class="rounded-full border border-indigo-100 bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-700">1 credit = ₦1</span></div>
    <p v-if="!can('ViewAny_Pricing')" class="surface p-6 text-sm text-[var(--muted)]">You don’t have permission to view billing settings.</p>
    <template v-else>
      <p v-if="error" class="rounded-xl bg-red-50 p-4 text-sm text-red-700" role="alert">{{ error }} <button type="button" :disabled="busy || loading" class="underline disabled:opacity-50" @click="load">Reload saved settings</button><span v-if="dirty" class="mt-2 block text-xs">Reloading replaces your unsaved changes.</span></p>
      <p v-if="success" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-700" role="status">{{ success }}</p>
      <p v-if="loading" class="surface p-12 text-center" role="status">Loading billing settings…</p>
      <form v-else-if="draft && record" class="space-y-6" @submit.prevent="save">
        <AdminBillingPackages v-model="draft.packages" :disabled="readOnly" />
        <AdminBillingPricing v-model="draft" :disabled="readOnly" />
        <AdminBillingPaymentSettings v-model="draft" :disabled="readOnly" />
        <AdminBillingPolicies v-model="draft" :disabled="readOnly" />
        <AdminBillingConcurrency v-model="draft" :disabled="readOnly" />
        <AdminBillingDeadlines v-model="draft" :disabled="readOnly" />
        <div v-if="can('Update_Pricing')" class="surface space-y-4 p-5 sm:p-6"><label class="billing-label">Reason for change<textarea v-model="reason" required maxlength="1000" rows="2" :disabled="busy" class="billing-input" placeholder="Explain the pricing or billing change" /></label><div class="flex flex-wrap items-center gap-3"><button type="submit" class="button-primary" :disabled="busy || !dirty || !reason.trim()"><UiAppIcon v-if="busy" name="loader" class="animate-spin" />{{ busy ? 'Saving…' : 'Save billing settings' }}</button><button type="button" class="button-secondary" :disabled="busy || !dirty" @click="reset">Discard changes</button><span class="text-xs text-[var(--muted)]">{{ dirty ? 'Unsaved changes' : 'All changes saved' }} · Version {{ record.version }}</span></div><p class="text-xs text-[var(--muted)]">New quotes use the saved settings. Existing quotes retain their original prices.</p></div>
      </form>
      <section v-if="record" class="surface p-5 sm:p-6"><h2 class="text-lg font-semibold">Recent changes</h2><p v-if="!record.history.length" class="mt-3 text-sm text-[var(--muted)]">No changes yet. Settings currently use backend defaults.</p><ol v-else class="mt-3 divide-y divide-[var(--line)]"><li v-for="change in record.history" :key="change.id" class="py-4"><p class="text-sm font-medium">{{ change.reason }}</p><p class="mt-1 text-xs text-[var(--muted)]">{{ change.actorName ?? 'Former administrator' }} · {{ new Date(change.createdAt).toLocaleString() }}</p><details class="mt-2 text-xs"><summary class="cursor-pointer text-indigo-600">View change</summary><div class="mt-3 grid gap-4 lg:grid-cols-2"><div><p class="font-semibold">Before</p><pre class="mt-2 max-h-64 overflow-auto rounded-lg bg-[var(--page)] p-3">{{ JSON.stringify(change.before, null, 2) }}</pre></div><div><p class="font-semibold">After</p><pre class="mt-2 max-h-64 overflow-auto rounded-lg bg-[var(--page)] p-3">{{ JSON.stringify(change.after, null, 2) }}</pre></div></div></details></li></ol></section>
    </template>
    <AdminBillingReport />
  </section>
</template>
<style scoped>
.admin-billing :deep(.billing-label) { display: block; font-size: 0.875rem; font-weight: 500; }
.admin-billing :deep(.billing-input) { display: block; width: 100%; margin-top: 0.5rem; padding: 0.75rem; border: 1px solid var(--line); border-radius: 0.5rem; background: var(--surface); font-size: 0.875rem; font-weight: 400; }
.admin-billing :deep(.billing-input:focus) { outline: 2px solid #6366f1; outline-offset: 2px; }
.admin-billing :deep(.billing-input:disabled) { opacity: 0.6; }
.admin-billing :deep(.billing-help) { display: block; margin-top: 0.5rem; font-size: 0.75rem; line-height: 1.6; font-weight: 400; color: var(--muted); }
</style>
