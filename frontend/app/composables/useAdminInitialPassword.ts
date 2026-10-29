export function useAdminInitialPassword() {
  const auth = useAuthStore()
  const busy = ref(false)
  const error = ref('')
  async function save(currentPassword: string, password: string, confirmation: string) {
    if (busy.value) return false
    busy.value = true; error.value = ''
    try {
      await useAuthenticatedFetch('/api/taydeez/password', { method: 'POST', body: { current_password: currentPassword, password, password_confirmation: confirmation } })
      auth.clearSession()
      await navigateTo('/taydeez/login?passwordChanged=1')
      return true
    } catch (failure: unknown) { error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Password could not be changed.'; return false }
    finally { busy.value = false }
  }
  return { busy, error, save }
}
