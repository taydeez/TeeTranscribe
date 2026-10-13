import type { DashboardEnvironment, DashboardReport } from '~/types/adminDashboard'

export function useAdminDashboard() {
  const route = useRoute()
  const router = useRouter()
  const auth = useAuthStore()
  const canView = computed(() => auth.isAdmin && !!auth.user?.permissions?.includes('View_Dashboard'))
  const today = new Date().toISOString().slice(0, 10)
  const start = new Date(today + 'T00:00:00Z')
  start.setUTCDate(start.getUTCDate() - 29)
  const defaultFrom = start.toISOString().slice(0, 10)
  const from = ref(defaultFrom)
  const to = ref(today)
  const environment = ref<DashboardEnvironment>('live')
  const report = ref<DashboardReport | null>(null)
  const loading = ref(false)
  const error = ref('')
  let requestId = 0

  async function load() {
    if (!canView.value) return
    const id = ++requestId
    from.value = typeof route.query.from === 'string' ? route.query.from : defaultFrom
    to.value = typeof route.query.to === 'string' ? route.query.to : today
    environment.value = ['live', 'test', 'all'].includes(String(route.query.environment)) ? route.query.environment as DashboardEnvironment : 'live'
    loading.value = true; error.value = ''; report.value = null
    try {
      const response = await useAuthenticatedFetch<{ data: DashboardReport }>('/api/taydeez/dashboard', { query: { from: from.value, to: to.value, environment: environment.value } })
      if (id === requestId) report.value = response.data
    } catch (failure: unknown) {
      if (id === requestId) error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Dashboard could not be loaded. Please try again.'
    } finally { if (id === requestId) loading.value = false }
  }
  async function apply() {
    if (loading.value) return
    const query = { ...route.query, from: from.value, to: to.value, environment: environment.value }
    if (route.query.from === from.value && route.query.to === to.value && route.query.environment === environment.value) await load()
    else await router.replace({ query })
  }
  async function range(days: number) {
    const date = new Date(today + 'T00:00:00Z')
    date.setUTCDate(date.getUTCDate() - days + 1)
    from.value = date.toISOString().slice(0, 10); to.value = today
    await apply()
  }
  watch(() => [route.query.from, route.query.to, route.query.environment], () => { void load() })
  onMounted(() => { void load() })
  onBeforeUnmount(() => { requestId++ })
  return { canView, today, from, to, environment, report, loading, error, load, apply, range }
}
