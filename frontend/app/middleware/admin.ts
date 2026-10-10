import type { AuthUser } from '~/stores/auth'

export default defineNuxtRouteMiddleware(async (to) => {
  if (import.meta.server) return
  const auth = useAuthStore()
  if (!await auth.initialize()) return navigateTo('/taydeez/login')
  if (!auth.isAdmin) return navigateTo('/dashboard')
  if (!auth.isEmailVerified) return navigateTo('/auth/verify-email')

  try {
    auth.user = await useAuthenticatedFetch<AuthUser>('/api/taydeez/user')
    if (auth.user.must_change_password && to.path !== '/taydeez/change-password') return navigateTo('/taydeez/change-password')
    if (!auth.user.must_change_password && to.path === '/taydeez/change-password') return navigateTo('/taydeez')
  } catch (error: unknown) {
    const failure = error as { statusCode?: number; response?: { status?: number } }
    const status = failure.statusCode ?? failure.response?.status
    if (status === 401) return navigateTo('/taydeez/login?sessionExpired=1')
    if (status === 403) {
      await auth.logout().catch(() => {})
      return navigateTo('/taydeez/login')
    }
    throw createError({ statusCode: 503, message: 'Administrator access could not be checked. Please try again.' })
  }
})
