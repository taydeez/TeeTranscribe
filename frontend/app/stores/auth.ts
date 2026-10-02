import { defineStore } from 'pinia'

export type AuthUser = {
  id: number
  name: string
  email: string
  roles?: string[]
}

const tokenStorageKey = 'auth_token'

export const useAuthStore = defineStore('auth', () => {
  const token = ref('')
  const user = ref<AuthUser | null>(null)
  const initialized = ref(false)
  const isAuthenticated = computed(() => token.value !== '' && user.value !== null)

  function persistToken(value: string): void {
    token.value = value
    if (import.meta.client) {
      localStorage.setItem(tokenStorageKey, value)
    }
  }

  function clearSession(): void {
    token.value = ''
    user.value = null
    initialized.value = true
    if (import.meta.client) {
      localStorage.removeItem(tokenStorageKey)
    }
  }

  async function fetchCurrentUser(): Promise<AuthUser> {
    if (!token.value) {
      throw new Error('No authentication token is available.')
    }

    return await $fetch<AuthUser>('/api/auth/user', {
      headers: { Authorization: `Bearer ${token.value}` },
      retry: 0,
    })
  }

  async function establishSession(value: string): Promise<void> {
    persistToken(value)
    try {
      user.value = await fetchCurrentUser()
      initialized.value = true
    } catch (error) {
      clearSession()
      throw error
    }
  }

  async function initialize(): Promise<boolean> {
    if (initialized.value) {
      return isAuthenticated.value
    }

    if (!import.meta.client) {
      return false
    }

    const storedToken = localStorage.getItem(tokenStorageKey) ?? ''
    if (!storedToken) {
      clearSession()
      return false
    }

    try {
      await establishSession(storedToken)
      return true
    } catch {
      return false
    }
  }

  async function logout(): Promise<void> {
    const currentToken = token.value
    try {
      if (currentToken) {
        await $fetch('/api/auth/logout', {
          method: 'POST',
          headers: { Authorization: `Bearer ${currentToken}` },
          retry: 0,
        })
      }
    } finally {
      clearSession()
    }
  }

  return {
    token,
    user,
    initialized,
    isAuthenticated,
    establishSession,
    initialize,
    clearSession,
    logout,
  }
})
