import type { AccountSecurityResponse, PasswordChangeRequest } from '~/types/accountSecurity'

export function useAccountSettings() {
  const auth = useAuthStore()
  const name = ref(auth.user?.name ?? '')
  const currentPassword = ref('')
  const password = ref('')
  const passwordConfirmation = ref('')
  const busy = ref(false)
  const error = ref('')
  const message = ref('')
  const nameChanged = computed(() => name.value.trim() !== '' && name.value.trim() !== auth.user?.name)

  async function saveName() {
    if (busy.value || !nameChanged.value) return
    busy.value = true; error.value = ''; message.value = ''
    try {
      const result = await useAuthenticatedFetch<AccountSecurityResponse>('/api/auth/profile', { method: 'PATCH', body: { name: name.value.trim() } })
      await auth.refreshUser()
      name.value = auth.user?.name ?? name.value
      message.value = result.message
    } catch (failure: unknown) { showError(failure) }
    finally { busy.value = false }
  }

  async function changePassword() {
    if (busy.value) return
    error.value = ''; message.value = ''
    if (password.value !== passwordConfirmation.value) { error.value = 'The new passwords do not match.'; return }
    busy.value = true
    try {
      const body: PasswordChangeRequest = { current_password: currentPassword.value, password: password.value, password_confirmation: passwordConfirmation.value }
      await useAuthenticatedFetch<AccountSecurityResponse>('/api/auth/change-password', { method: 'POST', body })
      currentPassword.value = ''; password.value = ''; passwordConfirmation.value = ''
      auth.clearSession()
      await navigateTo('/?login=1&passwordChanged=1')
    } catch (failure: unknown) { showError(failure) }
    finally { busy.value = false }
  }

  function showError(failure: unknown) {
    const response = failure as { data?: { message?: string }; message?: string }
    error.value = response.data?.message ?? response.message ?? 'Your account could not be updated.'
  }

  return { name, currentPassword, password, passwordConfirmation, busy, error, message, nameChanged, saveName, changePassword }
}
