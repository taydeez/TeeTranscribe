<script setup lang="ts">
const { busy, error, save } = useAdminInitialPassword()
const current = ref('')
const password = ref('')
const confirmation = ref('')
</script>
<template>
  <main class="grid min-h-dvh place-items-center bg-[var(--page)] px-5 py-12"><form class="surface w-full max-w-md space-y-5 p-8" @submit.prevent="save(current, password, confirmation)"><p class="eyebrow">Administration</p><h1 class="text-2xl font-semibold">Choose your own password</h1><p class="text-sm leading-6 text-[var(--muted)]">Change the initial password before accessing administration. You’ll sign in again with your new password and an email code.</p><label class="block text-sm font-medium">Initial password<input v-model="current" type="password" autocomplete="current-password" required :disabled="busy" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"></label><label class="block text-sm font-medium">New password<input v-model="password" type="password" autocomplete="new-password" minlength="12" required :disabled="busy" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"></label><p class="text-xs text-[var(--muted)]">At least 12 characters, with letters and numbers.</p><label class="block text-sm font-medium">Confirm password<input v-model="confirmation" type="password" autocomplete="new-password" minlength="12" required :disabled="busy" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"></label><p v-if="error" class="text-sm text-red-600" role="alert">{{ error }}</p><button class="button-primary w-full" :disabled="busy" type="submit">{{ busy ? 'Saving…' : 'Change password' }}</button></form></main>
</template>
