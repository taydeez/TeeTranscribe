export default defineNuxtRouteMiddleware(async (to) => {
  if (import.meta.server || !to.path.startsWith('/dashboard')) {
    return
  }

  const auth = useAuthStore()
  const authenticated = await auth.initialize()

  if (!authenticated) {
    return navigateTo('/')
  }
  if (!auth.isEmailVerified) {
    return navigateTo('/auth/verify-email')
  }
  if (auth.isAdmin) {
    return navigateTo('/taydeez')
  }
})
