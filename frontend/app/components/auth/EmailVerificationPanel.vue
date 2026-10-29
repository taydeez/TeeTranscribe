<script setup lang="ts">
import type { EmailVerificationLink } from '~/types/accountSecurity'

const auth = useAuthStore()
const route = useRoute()
const security = useAccountSecurity()
const loading = ref(true)
const currentTime = ref(Date.now())
const resendSeconds = computed(() => Math.max(0, Math.ceil((security.resendAvailableAt.value - currentTime.value) / 1000)))
let timer: ReturnType<typeof setInterval> | undefined
onMounted(async () => {
  timer = setInterval(() => { currentTime.value = Date.now() }, 1000)
  try {
    await auth.initialize()
    if (['id', 'hash', 'expires', 'signature'].every(key => typeof route.query[key] === 'string')) {
      await security.verifyEmail({ id: route.query.id, hash: route.query.hash, expires: route.query.expires, signature: route.query.signature } as EmailVerificationLink)
    }
  } finally { loading.value = false }
})
onBeforeUnmount(() => { if (timer) clearInterval(timer) })

async function logout() { await auth.logout(); await navigateTo('/?login=1') }
</script>

<template>
  <AuthAccountSecurityShell title="Verify your email" description="Open the verification link in your inbox to start using your workspace. Check your spam folder if you don’t see it.">
    <p v-if="loading || security.busy.value" class="flex items-center gap-2 text-sm text-slate-500"><UiAppIcon name="loader" class="animate-spin" :size="18" />Please wait…</p>
    <p v-if="security.error.value" class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-700" role="alert">{{ security.error.value }}</p>
    <p v-if="security.message.value" class="mt-4 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800" role="status">{{ security.message.value }}</p>
    <div v-if="!loading" class="mt-6 grid gap-3">
      <template v-if="auth.isAuthenticated && !auth.isEmailVerified">
        <p class="mb-2 text-sm text-slate-500">Signed in as {{ auth.user?.email }}</p>
        <button class="button-primary justify-center" :disabled="security.busy.value || resendSeconds > 0" @click="security.resendVerification">{{ resendSeconds > 0 ? `Resend in ${resendSeconds}s` : 'Resend verification email' }}</button>
        <button class="button-secondary justify-center" :disabled="security.busy.value" @click="security.checkVerification">I’ve verified my email</button>
        <button class="text-sm text-slate-500" :disabled="security.busy.value" @click="logout">Use another account</button>
      </template>
      <NuxtLink v-else-if="auth.isAuthenticated" to="/dashboard" class="button-primary justify-center">Open your workspace</NuxtLink>
      <NuxtLink v-else to="/?login=1" class="button-primary justify-center">Log in to your account</NuxtLink>
    </div>
  </AuthAccountSecurityShell>
</template>
