export default defineNuxtPlugin((nuxtApp) => {
  const auth = useAuthStore()
  const restore = () => {
    if (document.visibilityState === 'visible' && !auth.initialized) void auth.initialize()
  }
  window.addEventListener('online', restore)
  window.addEventListener('pageshow', restore)
  document.addEventListener('visibilitychange', restore)
  nuxtApp.vueApp.onUnmount(() => {
    window.removeEventListener('online', restore)
    window.removeEventListener('pageshow', restore)
    document.removeEventListener('visibilitychange', restore)
  })
})
