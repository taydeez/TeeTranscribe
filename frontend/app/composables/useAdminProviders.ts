import type { AIActivityConfiguration, AIProviderConfiguration } from '~/types/aiProvider'
export function useAdminProviders() {
  const auth = useAuthStore()
  const route = useRoute()
  const router = useRouter()
  const activities = ref<AIActivityConfiguration[]>([])
  const draft = ref<AIProviderConfiguration | null>(null)
  const reason = ref('')
  const loading = ref(false)
  const busy = ref(false)
  const error = ref('')
  const success = ref('')
  const can = (permission: string) => auth.user?.permissions?.includes(permission) ?? false
  const selected = computed(() => activities.value.find(item => item.activity === route.query.activity) ?? activities.value[0])
  watch(selected, item => { draft.value = item ? JSON.parse(JSON.stringify(item.configuration)) : null; reason.value = ''; success.value = '' }, { immediate: true, flush: 'sync' })
  async function load() {
    if (!can('ViewAny_AIProvider')) return
    loading.value = true; error.value = ''
    try { activities.value = (await useAuthenticatedFetch<{ data: AIActivityConfiguration[] }>('/api/taydeez/ai-providers')).data }
    catch (failure: unknown) { error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Provider settings could not be loaded.' }
    finally { loading.value = false }
  }
  async function select(activity: string) { if (!busy.value) await router.replace({ query: { ...route.query, activity } }) }
  async function save() {
    if (busy.value || !draft.value || !selected.value || !can('Update_AIProvider')) return
    busy.value = true; error.value = ''; success.value = ''
    const activity = selected.value.activity
    try {
      const response = await useAuthenticatedFetch<{ data: AIActivityConfiguration }>('/api/taydeez/ai-providers/' + activity, { method: 'PUT', body: { version: selected.value.version, configuration: draft.value, reason: reason.value.trim() } })
      activities.value = activities.value.map(item => item.activity === activity ? { ...response.data, name: item.name } : item)
      success.value = 'Settings saved. New requests will use these rules.'
    } catch (failure: unknown) { error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Settings could not be saved.' }
    finally { busy.value = false }
  }
  onMounted(() => { void load() })
  return { activities, draft, reason, loading, busy, error, success, can, selected, load, select, save }
}
