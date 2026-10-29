<script setup lang="ts">
const { can, roles, allPermissions, selectedPermissions, selected, mutable, loading, busy, error, success, create, save, selectRole } = useAdminRoles()
const name = ref('')
const filter = ref('')
const visiblePermissions = computed(() => allPermissions.value.filter(value => value.toLowerCase().includes(filter.value.toLowerCase())))
async function createRole() { if (await create(name.value)) name.value = '' }
</script>
<template>
  <section class="space-y-6">
    <div><h1 class="text-3xl font-semibold">Roles and permissions</h1><p class="mt-3 text-sm text-[var(--muted)]">Create roles and choose the actions each role can perform.</p></div>
    <p v-if="!can('ViewAny_Role')" class="surface p-8" role="alert">You don’t have permission to view roles.</p>
    <template v-else>
      <p v-if="error" class="rounded-xl bg-red-50 p-4 text-sm text-red-700" role="alert">{{ error }}</p><p v-if="success" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-700" role="status">{{ success }}</p>
      <form v-if="can('Create_Role')" class="surface flex flex-wrap items-end gap-4 p-6" @submit.prevent="createRole"><label class="min-w-0 flex-1 text-sm font-medium">New role name<input v-model="name" required maxlength="100" pattern="[a-z][a-z0-9_ \-]*" placeholder="e.g. support_manager" :disabled="busy" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"></label><button class="button-primary" type="submit" :disabled="busy">Create role</button></form>
      <div v-if="loading" class="surface p-12 text-center" role="status">Loading roles…</div>
      <div v-else class="surface space-y-5 p-6">
        <label class="block text-sm font-medium">Role<select :value="selected?.id" :disabled="busy" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3 sm:max-w-sm" @change="selectRole(($event.target as HTMLSelectElement).value)"><option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option></select></label>
        <p class="text-xs leading-5 text-[var(--muted)]">{{ mutable ? 'You may grant only permissions you currently hold. Admin accounts also retain the base admin role.' : 'This role is read only for your account. System user and super admin permissions are protected.' }}</p>
        <label class="block text-sm"><span class="sr-only">Filter permissions</span><input v-model="filter" type="search" placeholder="Filter permissions…" class="w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"></label>
        <form class="space-y-5" @submit.prevent="save"><div class="grid max-h-[32rem] gap-3 overflow-y-auto sm:grid-cols-2 xl:grid-cols-3"><label v-for="permission in visiblePermissions" :key="permission" class="flex items-start gap-3 rounded-lg border border-[var(--line)] p-3 text-xs"><input v-model="selectedPermissions" type="checkbox" :value="permission" :disabled="!mutable || busy || !can(permission)" class="mt-0.5 accent-indigo-600"><span class="break-all">{{ permission }}</span></label></div><p v-if="!visiblePermissions.length" class="text-sm text-[var(--muted)]">No matching permissions.</p><button v-if="mutable" class="button-primary" type="submit" :disabled="busy">Save permissions</button></form>
      </div>
    </template>
  </section>
</template>
