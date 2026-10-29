import { defineStore } from 'pinia'

export type AuthUser = {
  id: number
  name: string
  email: string
  email_verified_at?: string | null
  email_verified?: boolean
  roles?: string[]
  permissions?: string[]
  is_admin?: boolean
  must_change_password?: boolean
}

const tokenStorageKey = 'auth_token'

export const useAuthStore = defineStore('auth', () => {
  const token = ref('')
  const user = ref<AuthUser | null>(null)
  const initialized = ref(false)
  let sessionVersion = 0
  let initialization: Promise<boolean> | null = null
  const isAuthenticated = computed(() => token.value !== '' && user.value !== null)
  const isAdmin = computed(() => user.value?.is_admin ?? user.value?.roles?.some(role => role === 'admin' || role === 'super_admin') ?? false)
  const isEmailVerified = computed(() => user.value?.email_verified ?? Boolean(user.value?.email_verified_at))

  function persistToken(value: string): void {
    sessionVersion++
    token.value = value
    user.value = null
    initialized.value = false
    if (import.meta.client) {
      localStorage.setItem(tokenStorageKey, value)
    }
  }

  function clearSession(): void {
    sessionVersion++
    initialization = null
    token.value = ''
    user.value = null
    initialized.value = true
    if (import.meta.client) {
      localStorage.removeItem(tokenStorageKey)
    }
  }

  async function fetchCurrentUser(sessionToken = token.value): Promise<AuthUser> {
    if (!sessionToken) {
      throw new Error('No authentication token is available.')
    }

    return await $fetch<AuthUser>('/api/auth/user', {
      timeout: 20_000,
      headers: { Authorization: `Bearer ${sessionToken}` },
      retry: 1,
      retryDelay: 500,
    })
  }

  async function establishSession(value: string): Promise<void> {
    persistToken(value)
    const version = sessionVersion
    try {
      const currentUser = await fetchCurrentUser(value)
      if (version === sessionVersion) {
        user.value = currentUser
        initialized.value = true
      }
    } catch (error) {
      if (version === sessionVersion && unauthorized(error)) clearSession()
      throw error
    }
  }

  async function refreshUser(): Promise<void> {
    const version = sessionVersion
    try {
      const currentUser = await fetchCurrentUser()
      if (version === sessionVersion) user.value = currentUser
    } catch (error) {
      if (version === sessionVersion && unauthorized(error)) clearSession()
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

    if (initialization) return initialization

    const storedToken = localStorage.getItem(tokenStorageKey) ?? ''
    if (!storedToken) {
      clearSession()
      return false
    }

    const pending = establishSession(storedToken)
      .then(() => isAuthenticated.value)
      .catch(() => false)
      .finally(() => { if (initialization === pending) initialization = null })
    initialization = pending
    return pending
  }

  function unauthorized(error: unknown): boolean {
    const failure = error as { statusCode?: number; status?: number; response?: { status?: number } }
    return (failure?.statusCode ?? failure?.status ?? failure?.response?.status) === 401
  }

  async function logout(): Promise<void> {
    const currentToken = token.value
    const version = sessionVersion
    try {
      if (currentToken) {
        await $fetch('/api/auth/logout', {
          method: 'POST',
          headers: { Authorization: `Bearer ${currentToken}` },
          retry: 0,
        })
      }
    } finally {
      if (version === sessionVersion) clearSession()
    }
  }

  return {
    token,
    user,
    initialized,
    isAuthenticated,
    isAdmin,
    isEmailVerified,
    refreshUser,
    establishSession,
    initialize,
    clearSession,
    logout,
  }
})
