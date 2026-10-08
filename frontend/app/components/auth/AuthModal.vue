<script setup lang="ts">
import type { AuthUser } from '~/stores/auth'

type AuthMode = 'login' | 'register' | 'verify'
type AuthResponse = { token?: string; requires_two_factor?: boolean; email?: string; user?: AuthUser }

const props = withDefaults(defineProps<{ open: boolean; initialMode?: 'login' | 'register' }>(), { initialMode: 'login' })
const emit = defineEmits<{ 'update:open': [value: boolean] }>()
const auth = useAuthStore()
const route = useRoute()
const mode = ref<AuthMode>(props.initialMode)
const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const code = ref('')
const error = ref('')
const busy = ref(false)

watch(() => props.open, (open) => { if (open) { mode.value = props.initialMode; error.value = '' } })
watch(() => props.initialMode, value => { if (props.open) mode.value = value })

function close() { if (!busy.value) emit('update:open', false) }

async function submit() {
  busy.value = true
  error.value = ''
  try {
    const endpoint = mode.value === 'register' ? '/api/auth/register' : mode.value === 'verify' ? '/api/auth/admin-verify' : '/api/auth/login'
    const body = mode.value === 'register'
      ? { name: name.value, email: email.value, password: password.value, password_confirmation: passwordConfirmation.value }
      : mode.value === 'verify' ? { email: email.value, code: code.value } : { email: email.value, password: password.value }
    const response = await $fetch<AuthResponse>(endpoint, { method: 'POST', body })
    if (response.requires_two_factor) { mode.value = 'verify'; email.value = response.email ?? email.value; return }
    if (!response.token) throw new Error('The server did not return an authentication token.')
    await auth.establishSession(response.token)
    emit('update:open', false)
    await navigateTo(auth.isEmailVerified ? '/dashboard' : '/auth/verify-email')
  } catch (failure: unknown) {
    const response = failure as { data?: { message?: string }; message?: string }
    error.value = response.data?.message ?? response.message ?? 'Authentication failed.'
  }
  finally { busy.value = false }
}

function escape(event: KeyboardEvent) { if (event.key === 'Escape') close() }
onMounted(() => window.addEventListener('keydown', escape))
onBeforeUnmount(() => window.removeEventListener('keydown', escape))
</script>

<template>
  <Teleport to="body">
    <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0" leave-active-class="transition duration-150 ease-in" leave-to-class="opacity-0">
      <div v-if="open" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/60 p-4 backdrop-blur-sm" @mousedown.self="close">
        <section class="relative w-full max-w-md overflow-hidden rounded-2xl border border-white/70 bg-white p-7 shadow-lg sm:p-9" role="dialog" aria-modal="true" aria-labelledby="auth-title">
          <div class="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-indigo-500 via-violet-500 to-cyan-400" />
          <button class="absolute right-5 top-5 grid size-10 place-items-center rounded-full border border-slate-200 text-lg text-slate-500" type="button" aria-label="Close" @click="close">×</button>
          <span class="mb-5 grid size-11 place-items-center rounded-xl bg-indigo-50 text-lg font-semibold text-indigo-600">t.</span>
          <p class="mb-3 text-xs font-semibold tracking-[.2em] text-indigo-600">TEETRANSCRIBE ACCOUNT</p>
          <h2 id="auth-title" class="text-3xl font-semibold tracking-[-.04em] text-slate-900">{{ mode === 'register' ? 'Create your account' : mode === 'verify' ? 'Check your email' : 'Welcome back' }}</h2>
          <p class="mt-2 text-sm leading-6 text-slate-500">{{ mode === 'verify' ? 'Enter the six-digit administrator code we sent you.' : 'Save your recordings and keep every transcript within reach.' }}</p>
          <p v-if="route.query.passwordChanged === '1' && mode === 'login'" class="mt-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800" role="status">Your password has been changed. Log in with your new password.</p>
          <form class="mt-7 grid gap-4" @submit.prevent="submit">
            <input v-if="mode === 'register'" v-model="name" class="rounded-xl border border-slate-300 px-4 py-3 text-sm" required placeholder="Your name or organization name" autocomplete="name">
            <input v-model="email" class="rounded-xl border border-slate-300 px-4 py-3 text-sm" required type="email" placeholder="Email address" autocomplete="email">
            <input v-if="mode !== 'verify'" v-model="password" class="rounded-xl border border-slate-300 px-4 py-3 text-sm" required type="password" placeholder="Password" :autocomplete="mode === 'register' ? 'new-password' : 'current-password'">
            <input v-if="mode === 'register'" v-model="passwordConfirmation" class="rounded-xl border border-slate-300 px-4 py-3 text-sm" required type="password" placeholder="Confirm password" autocomplete="new-password">
            <input v-if="mode === 'verify'" v-model="code" class="rounded-xl border border-slate-300 px-4 py-3 text-center text-xl tracking-[.35em]" required inputmode="numeric" maxlength="6" placeholder="000000">
            <NuxtLink v-if="mode === 'login'" class="text-right text-sm font-semibold text-indigo-600" to="/auth/forgot-password" @click="close">Forgot password?</NuxtLink>
            <p v-if="error" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700" role="alert">{{ error }}</p>
            <button class="rounded-xl bg-indigo-600 px-5 py-3.5 text-sm font-bold text-white disabled:opacity-50" :disabled="busy">{{ busy ? 'Please wait…' : mode === 'register' ? 'Create account' : mode === 'verify' ? 'Verify code' : 'Log in' }}</button>
            <a v-if="mode !== 'verify'" class="rounded-xl border border-slate-300 px-5 py-3.5 text-center text-sm font-semibold text-slate-700" href="/auth/google">Continue with Google</a>
            <button v-if="mode !== 'verify'" class="text-sm font-semibold text-indigo-600" type="button" @click="mode = mode === 'login' ? 'register' : 'login'">{{ mode === 'login' ? 'New here? Create an account' : 'Already have an account? Log in' }}</button>
          </form>
        </section>
      </div>
    </Transition>
  </Teleport>
</template>
