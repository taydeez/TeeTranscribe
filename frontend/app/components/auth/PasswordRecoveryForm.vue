<script setup lang="ts">
const props = defineProps<{ mode: 'forgot' | 'reset' }>()
const route = useRoute()
const security = useAccountSecurity()
const email = ref(typeof route.query.email === 'string' ? route.query.email : '')
const password = ref('')
const confirmation = ref('')
const token = typeof route.query.token === 'string' ? route.query.token : ''
const validLink = props.mode === 'forgot' || (token !== '' && email.value !== '')

async function submit() {
  if (props.mode === 'forgot') await security.requestReset(email.value)
  else await security.resetPassword({ email: email.value, token, password: password.value, password_confirmation: confirmation.value })
}
</script>

<template>
  <AuthAccountSecurityShell
    :title="mode === 'forgot' ? 'Reset your password' : 'Choose a new password'"
    :description="mode === 'forgot' ? 'Enter your account email and we’ll send a reset link.' : 'Use at least eight characters, including a letter and a number.'">
    <p v-if="!validLink" class="rounded-xl bg-rose-50 p-4 text-sm text-rose-700" role="alert">This reset link is incomplete. <NuxtLink class="underline" to="/auth/forgot-password">Request a new link.</NuxtLink></p>
    <form v-else-if="!security.resetComplete.value" class="grid gap-4" @submit.prevent="submit">
      <label class="grid gap-2 text-sm font-medium">Email address
        <input v-model="email" class="rounded-xl border border-slate-300 px-4 py-3" type="email" autocomplete="email" required :readonly="mode === 'reset'">
      </label>
      <template v-if="mode === 'reset'">
        <label class="grid gap-2 text-sm font-medium">New password
          <input v-model="password" class="rounded-xl border border-slate-300 px-4 py-3" type="password" autocomplete="new-password" minlength="8" required>
        </label>
        <label class="grid gap-2 text-sm font-medium">Confirm new password
          <input v-model="confirmation" class="rounded-xl border border-slate-300 px-4 py-3" type="password" autocomplete="new-password" minlength="8" required>
        </label>
      </template>
      <button class="button-primary justify-center" :disabled="security.busy.value">{{ security.busy.value ? 'Please wait…' : mode === 'forgot' ? 'Send reset link' : 'Reset password' }}</button>
    </form>
    <p v-if="security.error.value" class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-700" role="alert">{{ security.error.value }}</p>
    <p v-if="security.message.value" class="mt-4 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800" role="status">{{ security.message.value }}</p>
    <NuxtLink to="/?login=1" class="mt-6 inline-block text-sm font-semibold text-indigo-600">Back to login</NuxtLink>
  </AuthAccountSecurityShell>
</template>
