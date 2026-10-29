import type { ActivityCancellation, ActivityType } from '~/types/activity'

export function useActivityCancellation(task: () => { type: ActivityType; id: string }, changed: (result: ActivityCancellation) => void) {
  const confirming = shallowRef(false)
  const busy = shallowRef(false)
  const error = shallowRef('')
  let active = true
  let sequence = 0
  async function confirm() {
    if (busy.value) return
    const { type, id } = task()
    const current = ++sequence
    busy.value = true; error.value = ''
    try {
      const result = await useAuthenticatedFetch<ActivityCancellation>(`/api/activities/${type}/${id}/cancel`, { method: 'POST', body: {} })
      if (!active || current !== sequence) return
      confirming.value = false
      changed(result)
    } catch (failure) {
      if (active && current === sequence) error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Could not cancel this task. Please try again.'
    } finally { if (active && current === sequence) busy.value = false }
  }
  watch(() => JSON.stringify(task()), () => { sequence++; confirming.value = false; busy.value = false; error.value = '' }, { flush: 'sync' })
  onBeforeUnmount(() => { active = false; sequence++ })
  return { confirming, busy, error, confirm }
}
