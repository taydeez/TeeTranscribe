import type { PrivacyDeletion, PrivacyFilePage } from '~/types/privacy'

export function usePrivacyFiles() {
  const route = useRoute()
  const files = ref<PrivacyFilePage | null>(null), loading = ref(false), error = ref('')
  const page = computed(() => Math.max(1, Number(route.query.filesPage) || 1))
  let active = true, sequence = 0
  const hidden = new Set<string>()
  async function load(clearHidden = false) {
    if (clearHidden) hidden.clear()
    const current = ++sequence
    loading.value = true; error.value = ''
    try {
      const result = await useAuthenticatedFetch<PrivacyFilePage>('/api/privacy/files', { query: { page: page.value, per_page: 10 } })
      if (active && current === sequence) files.value = { ...result, data: result.data.filter(item => !hidden.has(`${item.resourceType}:${item.id}`)) }
    } catch (failure) { if (active && current === sequence) error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Could not load saved uploads.' }
    finally { if (active && current === sequence) loading.value = false }
  }
  function accepted(result: PrivacyDeletion) {
    hidden.add(`${result.resourceType}:${result.resourceId}`)
    if (files.value) files.value = { ...files.value, data: files.value.data.filter(item => item.id !== result.resourceId || item.resourceType !== result.resourceType) }
  }
  watch(page, () => { void load() })
  onMounted(() => { void load() })
  onBeforeUnmount(() => { active = false; sequence++ })
  return { files, loading, error, page, load, accepted }
}
