<script setup lang="ts">
const route = useRoute()
const login = useAdminLogin()
const { busy, error, email, step } = login
const password = ref('')
const code = ref('')
const ready = ref(false)

onMounted(() => {
  ready.value = true
  if (route.query.verify === '1' && typeof route.query.email === 'string') {
    login.resumeVerification(route.query.email)
    history.replaceState(null, '', window.location.pathname)
  }
})

async function submit() {
  if (!ready.value) return
  if (step.value === 'code') await login.verify(code.value)
  else {
    const sent = await login.login({ email: email.value, password: password.value })
    if (sent) { password.value = ''; code.value = '' }
  }
}

function requestNewCode() {
  code.value = ''
  login.requestNewCode()
}
</script>

<template>
  <main class="grid min-h-dvh place-items-center bg-slate-50 px-5 py-12">
    <section class="w-full max-w-md rounded-3xl border border-slate-200 bg-white p-7 shadow-xl shadow-slate-200/40 sm:p-10" aria-labelledby="admin-login-title">
      <div class="mb-8 flex items-center justify-between">
        <NuxtLink class="flex items-center gap-2 text-lg font-bold text-slate-900" to="/"><span class="grid size-10 place-items-center rounded-xl bg-indigo-600 text-white">t.</span>TeeTranscribe</NuxtLink>
        <UiThemeToggle />
      </div>
      <p class="mb-3 text-xs font-semibold uppercase tracking-[.18em] text-indigo-600">Administration</p>
      <h1 id="admin-login-title" class="text-3xl font-semibold tracking-tight text-slate-900">{{ step === 'code' ? 'Check your email' : 'Welcome back' }}</h1>
      <p class="mt-3 text-sm leading-6 text-slate-500">{{ step === 'code' ? `Enter the six-digit code sent to ${email}.` : 'Sign in with your administrator account to continue.' }}</p>
      <p v-if="route.query.passwordChanged === '1'" class="mt-5 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-700" role="status">Password changed. Sign in with your new password.</p>
      <p v-if="route.query.sessionExpired === '1'" class="mt-5 rounded-xl bg-amber-50 p-3 text-sm text-amber-800" role="status">Your session expired after five minutes of inactivity. Please sign in again.</p>
      <form class="mt-7 space-y-5" @submit.prevent="submit">
        <template v-if="step === 'password'">
          <div>
            <label for="admin-email" class="mb-2 block text-sm font-medium text-slate-700">Username or email</label>
            <input id="admin-email" v-model="email" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100" type="text" autocomplete="username" required :disabled="busy">
          </div>
          <div>
            <label for="admin-password" class="mb-2 block text-sm font-medium text-slate-700">Password</label>
            <input id="admin-password" v-model="password" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100" type="password" autocomplete="current-password" required :disabled="busy">
          </div>
        </template>
        <div v-else>
          <label for="admin-code" class="mb-2 block text-sm font-medium text-slate-700">Email verification code</label>
          <input id="admin-code" v-model="code" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-center text-2xl tracking-[.35em] focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" placeholder="000000" required :disabled="busy">
          <p class="mt-2 text-xs leading-5 text-slate-500">The code expires in 10 minutes. Only the latest code will work.</p>
        </div>
        <p v-if="error" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700" role="alert">{{ error }}</p>
        <button class="flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 py-3.5 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:cursor-wait disabled:opacity-60" type="submit" :disabled="!ready || busy">
          <span v-if="busy" class="size-4 animate-spin rounded-full border-2 border-white/40 border-t-white" aria-hidden="true" />
          {{ busy ? 'Please wait…' : step === 'code' ? 'Verify and sign in' : 'Continue' }}
        </button>
        <button v-if="step === 'code'" class="w-full text-sm font-medium text-indigo-600 disabled:opacity-50" type="button" :disabled="busy" @click="requestNewCode">Use another email or request a new code</button>
        <NuxtLink v-else class="block text-center text-sm font-medium text-indigo-600" to="/auth/forgot-password">Forgot your password?</NuxtLink>
      </form>
      <p class="mt-8 border-t border-slate-100 pt-5 text-xs leading-5 text-slate-500">Email verification is required for every administrator sign-in.</p>
    </section>
  </main>
</template>
