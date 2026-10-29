import type { AdminLoginRequest, AdminLoginResponse, AdminVerifyRequest, AdminVerifyResponse } from '~/types/adminAuth'

export function useAdminLogin() {
  const auth = useAuthStore()
  const busy = ref(false)
  const error = ref('')
  const email = ref('')
  const step = ref<'password' | 'code'>('password')

  async function perform(operation: () => Promise<void>) {
    if (busy.value) return false
    busy.value = true
    error.value = ''
    try { await operation(); return true }
    catch (failure: unknown) {
      const response = failure as { name?: string; cause?: { name?: string }; data?: { message?: string }; message?: string }
      const timedOut = [response.name, response.cause?.name].some(name => name === 'TimeoutError' || name === 'AbortError')
      error.value = timedOut ? 'Sign-in took too long. Please try again.' : response.data?.message ?? response.message ?? 'Sign-in could not be completed.'
      return false
    }
    finally { busy.value = false }
  }

  async function login(input: AdminLoginRequest) {
    return await perform(async () => {
      const response = await $fetch<AdminLoginResponse>('/api/taydeez/login', { method: 'POST', body: input, retry: 0, timeout: 35_000 })
      if (!response.requires_two_factor) throw new Error('Administrator email verification is required.')
      email.value = response.email
      step.value = 'code'
    })
  }

  async function verify(code: string) {
    return await perform(async () => {
      const body: AdminVerifyRequest = { email: email.value, code }
      const response = await $fetch<AdminVerifyResponse>('/api/taydeez/verify', { method: 'POST', body, retry: 0, timeout: 35_000 })
      if (!response.token) throw new Error('The server did not return an authentication token.')
      await auth.establishSession(response.token)
      if (!auth.isAdmin) {
        await auth.logout()
        throw new Error('Administrator access is required.')
      }
      await navigateTo(auth.isEmailVerified ? (auth.user?.must_change_password ? '/taydeez/change-password' : '/taydeez') : '/auth/verify-email')
    })
  }

  function requestNewCode() {
    if (busy.value) return
    step.value = 'password'
    error.value = ''
  }

  function resumeVerification(value: string) {
    email.value = value
    step.value = 'code'
  }

  return { busy, error, email, step, login, verify, requestNewCode, resumeVerification }
}
