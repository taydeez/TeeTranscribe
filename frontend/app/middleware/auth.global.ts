export default defineNuxtRouteMiddleware(async (to) => {
  if (import.meta.server || to.path !== '/dashboard') {
    return
  }

  const auth = useAuthStore()
  const authenticated = await auth.initialize()

  if (!authenticated) {
    return navigateTo('/')
  }
})
