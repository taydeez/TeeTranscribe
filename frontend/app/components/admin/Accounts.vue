<script setup lang="ts">
const { auth, can, result, roles, loading, busy, error, success, deleting, create, updateRole, remove, goToPage } = useAdminAccounts()
</script>
<template>
  <section class="space-y-6">
    <div><h1 class="text-3xl font-semibold">Admin accounts</h1><p class="mt-3 text-sm text-[var(--muted)]">Manage who can access administration and what they can do.</p></div>
    <p v-if="!can('ViewAny_AdminAccount')" class="surface p-8" role="alert">You don’t have permission to view administrators.</p>
    <template v-else>
      <p v-if="success" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-700" role="status">{{ success }}</p>
      <p v-if="error" class="rounded-xl bg-red-50 p-4 text-sm text-red-700" role="alert">{{ error }}</p>
      <AdminAccountCreateForm v-if="can('Create_AdminAccount')" :roles="roles" :busy="busy || loading" :submit="create" />
      <div class="surface overflow-hidden"><div v-if="loading" class="flex justify-center gap-3 p-12" role="status"><UiAppIcon name="loader" class="animate-spin" />Loading administrators…</div>
        <p v-else-if="!result?.data.length" class="p-10 text-sm text-[var(--muted)]">No administrators to display.</p>
        <div v-else class="overflow-x-auto"><table class="w-full min-w-[720px] text-left text-sm"><thead class="bg-[var(--page)] text-xs text-[var(--muted)]"><tr><th class="p-5">Administrator</th><th class="p-5">Role</th><th class="p-5">Password</th><th class="p-5"><span class="sr-only">Actions</span></th></tr></thead><tbody class="divide-y divide-[var(--line)]"><tr v-for="account in result.data" :key="account.id"><td class="p-5"><p class="font-semibold">{{ account.name }}<span v-if="account.id === auth.user?.id" class="ml-2 text-xs text-indigo-600">You</span></p><p class="mt-1 text-xs text-[var(--muted)]">{{ account.username ?? 'No username' }} · {{ account.email }}</p></td><td class="p-5"><AdminAccountRoleSelect v-if="can('Update_AdminAccount') && roles.length" :account="account" :roles="roles" :disabled="busy || account.id === auth.user?.id" @save="updateRole(account, $event)" /><span v-else>{{ account.roles.join(', ') }}</span></td><td class="p-5 text-xs text-[var(--muted)]">{{ account.mustChangePassword ? 'Change required' : 'Password set' }}</td><td class="p-5"><button v-if="can('Delete_AdminAccount') && account.id !== auth.user?.id" class="icon-button hover:text-red-600" type="button" :disabled="busy" aria-label="Delete administrator" @click="deleting = account"><UiAppIcon name="close" :size="18" /></button></td></tr></tbody></table></div>
        <div v-if="result && result.meta.lastPage > 1" class="flex items-center justify-between border-t border-[var(--line)] p-5"><span class="text-xs text-[var(--muted)]">Page {{ result.meta.currentPage }} of {{ result.meta.lastPage }}</span><div class="flex gap-2"><button class="button-secondary" :disabled="loading || result.meta.currentPage <= 1" @click="goToPage(result.meta.currentPage - 1)">Previous</button><button class="button-secondary" :disabled="loading || result.meta.currentPage >= result.meta.lastPage" @click="goToPage(result.meta.currentPage + 1)">Next</button></div></div>
      </div>
      <PrivacyDeletionConfirmation :open="!!deleting" :name="deleting?.name ?? ''" title="Delete administrator?" consequence="This administrator will lose access immediately. Their account record is retained for audit history." :busy="busy" :disabled="false" :error="error" @cancel="deleting = null" @confirm="remove" />
    </template>
  </section>
</template>
