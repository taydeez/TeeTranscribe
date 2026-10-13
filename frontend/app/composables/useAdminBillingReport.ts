import type { BillingReport } from '~/types/adminBilling'
export function useAdminBillingReport() {
  const auth = useAuthStore()
  const route = useRoute()
  const router = useRouter()
  const today = new Date().toISOString().slice(0, 10)
  const from = ref(typeof route.query.from === 'string' ? route.query.from : today.slice(0, 8) + '01')
  const to = ref(typeof route.query.to === 'string' ? route.query.to : today)
  const report = ref<BillingReport | null>(null)
  const loading = ref(false)
  const error = ref('')
  const canView = computed(() => auth.user?.permissions?.includes('ViewAny_Usage') ?? false)
  let request = 0
  async function load() {
    if (!canView.value) return
    const current = ++request
    loading.value = true; error.value = ''; report.value = null
    const query = { from: typeof route.query.from === 'string' ? route.query.from : today.slice(0, 8) + '01', to: typeof route.query.to === 'string' ? route.query.to : today }
    from.value = query.from; to.value = query.to
    try {
      const response = await useAuthenticatedFetch<{ data: BillingReport }>('/api/taydeez/billing/report', { query })
      if (current === request) report.value = response.data
    } catch (failure: unknown) { if (current === request) error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Billing report could not be loaded.' }
    finally { if (current === request) loading.value = false }
  }
  async function apply() {
    if (loading.value) return
    if (route.query.from === from.value && route.query.to === to.value) await load()
    else await router.replace({ query: { ...route.query, from: from.value, to: to.value } })
  }
  watch(() => [route.query.from, route.query.to], () => { void load() })
  onMounted(() => { void load() })
  return { from, to, today, report, loading, error, canView, load, apply }
}
