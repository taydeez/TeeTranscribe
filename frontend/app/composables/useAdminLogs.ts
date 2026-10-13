import { logFilterKeys } from '~/config/adminLogs'
import type { LogFilterKey } from '~/config/adminLogs'
import type { OperationalLog, OperationalLogList } from '~/types/adminLog'

export function useAdminLogs() {
  const route = useRoute()
  const router = useRouter()
  const auth = useAuthStore()
  const canView = computed(() => auth.isAdmin && !!auth.user?.permissions?.includes('ViewAny_OperationalLog'))
  const canInspect = computed(() => !!auth.user?.permissions?.includes('View_OperationalLog'))
  const filters = reactive(Object.fromEntries(logFilterKeys.map(key => [key, ''])) as Record<LogFilterKey, string>)
  const result = ref<OperationalLogList | null>(null)
  const selected = ref<OperationalLog | null>(null)
  const loading = ref(false)
  const detailLoading = ref(false)
  const error = ref('')
  const detailError = ref('')
  let requestId = 0
  let detailRequestId = 0

  async function load() {
    if (!canView.value) return
    const id = ++requestId
    loading.value = true
    error.value = ''
    result.value = null
    for (const key of logFilterKeys) filters[key] = typeof route.query[key] === 'string' ? route.query[key] as string : ''
    const page = Math.max(1, Number(route.query.page) || 1)
    try {
      const response = await useAuthenticatedFetch<OperationalLogList>('/api/taydeez/logs', {
        query: { ...Object.fromEntries(logFilterKeys.filter(key => filters[key]).map(key => [key, filters[key]])), page, per_page: 25 },
      })
      if (id === requestId) result.value = response
    } catch (failure: unknown) {
      if (id === requestId) error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Logs could not be loaded.'
    } finally {
      if (id === requestId) loading.value = false
    }
  }

  async function apply() {
    await router.replace({ query: { ...Object.fromEntries(logFilterKeys.filter(key => filters[key]).map(key => [key, filters[key].trim()])), page: 1 } })
  }
  async function reset() { await router.replace({ query: {} }) }
  async function page(value: number) {
    if (loading.value || value < 1 || value > (result.value?.meta.lastPage ?? 1)) return
    await router.replace({ query: { ...route.query, page: value } })
  }
  async function follow(log: OperationalLog) {
    await router.replace({ query: { operation_type: log.operation_type ?? undefined, operation_id: log.operation_id ?? undefined, job_id: log.operation_id ? undefined : log.job_id ?? undefined, sort: 'oldest' } })
  }
  async function inspect(log: OperationalLog) {
    if (!canInspect.value) return
    const id = ++detailRequestId
    selected.value = log
    detailLoading.value = true
    detailError.value = ''
    try {
      const response = await useAuthenticatedFetch<{ data: OperationalLog }>(`/api/taydeez/logs/${log.id}`)
      if (id === detailRequestId) selected.value = response.data
    } catch (failure: unknown) {
      if (id === detailRequestId) detailError.value = (failure as { data?: { message?: string } }).data?.message ?? 'Log details could not be loaded.'
    } finally {
      if (id === detailRequestId) detailLoading.value = false
    }
  }
  function close() { detailRequestId++; selected.value = null; detailError.value = ''; detailLoading.value = false }
  watch(() => route.query, () => { close(); void load() })
  onMounted(() => { void load() })
  onBeforeUnmount(() => { requestId++; detailRequestId++ })
  return { canView, canInspect, filters, result, selected, loading, detailLoading, error, detailError, load, apply, reset, page, follow, inspect, close }
}
