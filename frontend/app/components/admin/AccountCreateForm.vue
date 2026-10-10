<script setup lang="ts">
import type { AdminAccountInput, AdminRole } from '~/types/adminAccount'
const props = defineProps<{ roles: AdminRole[]; busy: boolean; submit: (input: AdminAccountInput) => Promise<boolean> }>()
const form = reactive({ name: '', username: '', email: '', password: '', role_id: '' })
async function save() {
  if (await props.submit({ ...form, role_id: Number(form.role_id) })) { form.password = ''; form.name = ''; form.username = ''; form.email = ''; form.role_id = '' }
}
</script>
<template>
  <form class="surface space-y-5 p-6" @submit.prevent="save">
    <div><h2 class="text-lg font-semibold">Create admin account</h2><p class="mt-2 text-sm text-[var(--muted)]">Email is used for login codes. The initial password must be changed after the first sign-in.</p></div>
    <div class="grid gap-4 sm:grid-cols-2">
      <label class="text-sm font-medium">Name<input v-model="form.name" required maxlength="255" :disabled="busy" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"></label>
      <label class="text-sm font-medium">Username<input v-model="form.username" required pattern="[a-zA-Z][a-zA-Z0-9_.\-]{2,79}" autocomplete="off" :disabled="busy" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"></label>
      <label class="text-sm font-medium">Email<input v-model="form.email" required type="email" autocomplete="off" :disabled="busy" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"></label>
      <label class="text-sm font-medium">Initial password<input v-model="form.password" required type="password" minlength="12" autocomplete="new-password" :disabled="busy" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"><span class="mt-1 block text-xs text-[var(--muted)]">At least 12 characters, including letters and numbers.</span></label>
      <label class="text-sm font-medium">Role<select v-model="form.role_id" required :disabled="busy" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"><option value="" disabled>Select role</option><option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option></select></label>
    </div>
    <button class="button-primary" type="submit" :disabled="busy || !roles.length">{{ busy ? 'Please wait…' : 'Create administrator' }}</button>
  </form>
</template>
