import type { AccountSecurityResponse, EmailVerificationLink, PasswordResetRequest } from '~/types/accountSecurity'

export function useAccountSecurity() {
  const auth = useAuthStore()
  const busy = ref(false)
  const error = ref('')
  const message = ref('')
  const resetComplete = ref(false)
  const verified = ref(false)
  const resendAvailableAt = ref(0)

  async function perform(operation: () => Promise<AccountSecurityResponse>): Promise<boolean> {
    if (busy.value) return false
    busy.value = true; error.value = ''; message.value = ''
    try {
      const response = await operation()
      message.value = response.message
      return true
    } catch (failure: unknown) {
      const response = failure as { data?: { message?: string }; message?: string }
      error.value = response.data?.message ?? response.message ?? 'Your request could not be completed.'
      return false
    } finally { busy.value = false }
  }

  async function requestReset(email: string) {
    return await perform(() => $fetch<AccountSecurityResponse>('/api/auth/forgot-password', { method: 'POST', body: { email }, retry: 0 }))
  }

  async function resetPassword(body: PasswordResetRequest) {
    const success = await perform(() => $fetch<AccountSecurityResponse>('/api/auth/reset-password', { method: 'POST', body, retry: 0 }))
    if (success) { resetComplete.value = true; auth.clearSession() }
    return success
  }

  async function verifyEmail(query: EmailVerificationLink) {
    const success = await perform(() => $fetch<AccountSecurityResponse>('/api/auth/verify-email', { query, retry: 0 }))
    if (success) {
      verified.value = true
      if (auth.isAuthenticated) {
        try { await auth.refreshUser() }
        catch { auth.clearSession() }
      }
    }
    return success
  }

  async function resendVerification() {
    if (Date.now() < resendAvailableAt.value) return false
    const success = await perform(() => useAuthenticatedFetch<AccountSecurityResponse>('/api/auth/resend-verification', { method: 'POST', body: {} }))
    if (success) resendAvailableAt.value = Date.now() + 60_000
    return success
  }

  async function checkVerification() {
    if (!auth.isAuthenticated) return false
    const success = await perform(async () => {
      await auth.refreshUser()
      return { message: auth.isEmailVerified ? 'Your email address is verified.' : 'Your email is not verified yet. Open the link in your inbox.' }
    })
    if (success && auth.isEmailVerified) await navigateTo('/dashboard')
    return success
  }

  return { busy, error, message, resetComplete, verified, resendAvailableAt, requestReset, resetPassword, verifyEmail, resendVerification, checkVerification }
}
