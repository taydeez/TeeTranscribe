import type { PrivacyDeletion } from '~/types/privacy'

export function usePrivacyDeletionHistory(settled: () => void) {
  const records = ref<PrivacyDeletion[]>([]), loading = ref(false), error = ref(''), retrying = ref('')
  let active = true, sequence = 0
  let timer: ReturnType<typeof setTimeout> | null = null
  function schedule() {
    if (timer) clearTimeout(timer)
    timer = null
    if (active && records.value.some(item => ['pending', 'processing'].includes(item.status))) timer = setTimeout(() => { void load(true) }, 5000)
  }
  async function load(background = false) {
    const current = ++sequence
    if (timer) clearTimeout(timer)
    if (!background) loading.value = true
    error.value = ''
    try {
      const result = await useAuthenticatedFetch<{ data: PrivacyDeletion[] }>('/api/privacy/deletions')
      if (!active || current !== sequence) return
      const finished = result.data.some(item => !['pending', 'processing'].includes(item.status) && records.value.some(previous => previous.id === item.id && ['pending', 'processing'].includes(previous.status)))
      records.value = result.data
      if (finished) settled()
    } catch (failure) { if (active && current === sequence) error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Could not load deletion progress.' }
    finally { if (active && current === sequence) { loading.value = false; schedule() } }
  }
  async function retry(id: string) {
    if (retrying.value) return
    retrying.value = id; error.value = ''
    try {
      const result = await useAuthenticatedFetch<PrivacyDeletion>(`/api/privacy/deletions/${id}/retry`, { method: 'POST' })
      if (!active) return
      records.value = records.value.map(item => item.id === id ? result : item); schedule()
      if (result.status === 'completed') settled()
    } catch (failure) { if (active) error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Could not retry cleanup.' }
    finally { if (active) retrying.value = '' }
  }
  onMounted(() => { void load() })
  onBeforeUnmount(() => { active = false; sequence++; if (timer) clearTimeout(timer) })
  return { records, loading, error, retrying, load, retry }
}
