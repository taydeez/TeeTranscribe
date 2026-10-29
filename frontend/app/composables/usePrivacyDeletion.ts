import type { PrivacyDeletion, PrivacyDeletionTarget } from '~/types/privacy'

export function usePrivacyDeletion(target: () => PrivacyDeletionTarget, accepted: (record: PrivacyDeletion) => void, completed: (record: PrivacyDeletion) => void) {
  const confirming = ref(false), busy = ref(false), error = ref(''), record = ref<PrivacyDeletion | null>(null)
  const working = computed(() => Boolean(record.value && ['pending', 'processing'].includes(record.value.status)))
  let active = true, sequence = 0
  let snapshot = ''
  let timer: ReturnType<typeof setTimeout> | null = null
  const message = (failure: unknown) => (failure as { data?: { message?: string }; message?: string }).data?.message ?? (failure as Error).message ?? 'Deletion could not be requested. Try again.'
  function stop() { if (timer) clearTimeout(timer); timer = null }
  function schedule() {
    stop()
    if (active && working.value) timer = setTimeout(() => { void refresh() }, 5000)
  }
  function receive(result: PrivacyDeletion) {
    const wasComplete = record.value?.status === 'completed'
    record.value = result
    if (result.status === 'completed' && !wasComplete) completed(result)
    schedule()
  }
  function open() {
    if (busy.value || working.value || record.value?.status === 'completed') return
    snapshot = JSON.stringify(target()); confirming.value = true; error.value = ''
  }
  async function confirm() {
    if (!confirming.value || busy.value || snapshot !== JSON.stringify(target())) return
    const current = ++sequence
    busy.value = true; error.value = ''
    try {
      const result = await useAuthenticatedFetch<PrivacyDeletion>('/api/privacy/deletions', { method: 'POST', body: { ...target() } })
      if (!active || current !== sequence) return
      confirming.value = false; receive(result); accepted(result)
    } catch (failure) { if (active && current === sequence) error.value = message(failure) }
    finally { if (active && current === sequence) busy.value = false }
  }
  async function refresh() {
    const id = record.value?.id, current = sequence
    if (!id) return
    try {
      const result = await useAuthenticatedFetch<PrivacyDeletion>(`/api/privacy/deletions/${id}`)
      if (active && current === sequence) { error.value = ''; receive(result) }
    } catch (failure) { if (active && current === sequence) { error.value = message(failure); schedule() } }
  }
  async function retry() {
    if (busy.value || record.value?.status !== 'failed') return
    const current = ++sequence
    busy.value = true; error.value = ''
    try {
      const result = await useAuthenticatedFetch<PrivacyDeletion>(`/api/privacy/deletions/${record.value.id}/retry`, { method: 'POST' })
      if (active && current === sequence) receive(result)
    } catch (failure) { if (active && current === sequence) error.value = message(failure) }
    finally { if (active && current === sequence) busy.value = false }
  }
  watch(() => JSON.stringify(target()), () => { sequence++; stop(); confirming.value = false; busy.value = false; error.value = ''; record.value = null }, { flush: 'sync' })
  onBeforeUnmount(() => { active = false; sequence++; stop() })
  return { confirming, busy, error, record, working, open, confirm, retry }
}
