import { createAdminIdleSession } from '~/utils/adminIdleSession'

export default defineNuxtPlugin((nuxtApp) => {
  const auth = useAuthStore()
  let session: ReturnType<typeof createAdminIdleSession> | undefined
  const storageKey = 'admin_last_activity'
  const tokenId = () => auth.token.split('|')[0]
  let lastStored = 0
  const events = ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart'] as const
  const interact = (event: Event) => {
    if (event.isTrusted && document.visibilityState === 'visible' && session) {
      session.interact()
      if (Date.now() - lastStored >= 1000) {
        lastStored = Date.now()
        localStorage.setItem(storageKey, JSON.stringify({ tokenId: tokenId(), at: lastStored }))
      }
    }
  }

  function savedActivity(value: string | null) {
    try {
      const saved = JSON.parse(value ?? '{}') as { tokenId?: string; at?: number }
      return saved.tokenId === tokenId() && typeof saved.at === 'number' && saved.at <= Date.now() ? saved.at : undefined
    }
    catch { return undefined }
  }

  function synchronize(event: StorageEvent) {
    if (event.key === storageKey) {
      const at = savedActivity(event.newValue)
      if (at !== undefined) session?.synchronize(at)
    }
    if (event.key === 'auth_token' && event.newValue === null && auth.isAdmin) {
      auth.clearSession()
      void navigateTo('/taydeez/login')
    }
  }

  const stopWatching = watch(() => auth.isAuthenticated && auth.isAdmin ? auth.token : '', (token) => {
    session?.stop()
    const initialActivity = savedActivity(localStorage.getItem(storageKey)) ?? Date.now()
    session = token
      ? createAdminIdleSession({
          now: () => Date.now(),
          initialActivity,
          heartbeat: () => useAuthenticatedFetch('/api/taydeez/activity', { method: 'POST', body: {} }),
          expire: () => {
            void auth.logout().catch(() => {})
            auth.clearSession()
            void navigateTo('/taydeez/login?sessionExpired=1')
          },
        })
      : undefined
    if (token) {
      localStorage.setItem(storageKey, JSON.stringify({ tokenId: tokenId(), at: initialActivity }))
    }
  }, { immediate: true })

  const timer = window.setInterval(() => { void session?.tick() }, 1000)
  for (const event of events) window.addEventListener(event, interact, { passive: true })
  window.addEventListener('storage', synchronize)
  nuxtApp.vueApp.onUnmount(() => {
    stopWatching()
    window.clearInterval(timer)
    session?.stop()
    for (const event of events) window.removeEventListener(event, interact)
    window.removeEventListener('storage', synchronize)
  })
})
