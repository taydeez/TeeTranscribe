import type { FetchOptions } from 'ofetch'
import { $fetch as ofetch } from 'ofetch'

export async function useAuthenticatedFetch<T>(request: string, options: FetchOptions<'json'> = {}): Promise<T> {
  const auth = useAuthStore()
  const headers = new Headers(options.headers as HeadersInit | undefined)

  if (!auth.token) {
    auth.clearSession()
    await navigateTo('/')
    throw createError({ statusCode: 401, message: 'Authentication is required.' })
  }

  headers.set('Authorization', `Bearer ${auth.token}`)
  headers.set('Accept', 'application/json')

  try {
    return await ofetch<T>(request, { ...options, headers, retry: 0 })
  } catch (error: unknown) {
    const failure = error as { statusCode?: number; status?: number; response?: { status?: number }; data?: { message?: string } }
    const status = failure.statusCode ?? failure.status ?? failure.response?.status
    if (status === 401) {
      const loginUrl = auth.isAdmin ? '/taydeez/login?sessionExpired=1' : '/'
      auth.clearSession()
      await navigateTo(loginUrl)
    }
    if (status === 403 && failure.data?.message?.includes('email address is not verified')) {
      await auth.refreshUser()
      await navigateTo('/auth/verify-email')
    }
    throw error
  }
}
