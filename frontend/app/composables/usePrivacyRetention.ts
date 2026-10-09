import { defaultRetention, PRIVACY_CATEGORIES } from '~/config/privacy'
import type { PrivacySettings, RetentionPolicy } from '~/types/privacy'

export function usePrivacyRetention() {
  const original = ref<RetentionPolicy | null>(null)
  const retention = ref<RetentionPolicy>(defaultRetention())
  const cleanupInterval = ref(15)
  const loading = ref(false), busy = ref(false), error = ref(''), saved = ref(false), confirming = ref(false)
  const changes = computed(() => PRIVACY_CATEGORIES.filter(item => original.value && retention.value[item.key] !== original.value[item.key]))
  const shortened = computed(() => changes.value.filter(item => retention.value[item.key] !== null && (original.value?.[item.key] === null || retention.value[item.key]! < original.value![item.key]!)))
  const dirty = computed(() => changes.value.length > 0)
  let active = true
  let sequence = 0
  let confirmationSnapshot = ''
  const message = (failure: unknown) => (failure as { data?: { message?: string }; message?: string }).data?.message ?? (failure as Error).message ?? 'Your retention settings could not be saved.'
  watch(() => JSON.stringify(retention.value), () => { saved.value = false; confirming.value = false }, { flush: 'sync' })
  async function load() {
    const current = ++sequence
    loading.value = true; error.value = ''
    try {
      const result = await useAuthenticatedFetch<PrivacySettings>('/api/privacy/settings')
      if (!active || current !== sequence) return
      original.value = { ...result.retention }; retention.value = { ...result.retention }; cleanupInterval.value = result.cleanupIntervalMinutes
    } catch (failure) { if (active && current === sequence) error.value = message(failure) }
    finally { if (active && current === sequence) loading.value = false }
  }
  async function persist() {
    if (!dirty.value || busy.value || loading.value) return
    busy.value = true; error.value = ''; saved.value = false
    const snapshot = { ...retention.value }
    const partial = Object.fromEntries(changes.value.map(item => [item.key, snapshot[item.key]]))
    try {
      const result = await useAuthenticatedFetch<PrivacySettings>('/api/privacy/settings', { method: 'PATCH', body: { retention: partial } })
      if (!active) return
      original.value = { ...result.retention }; retention.value = { ...result.retention }; cleanupInterval.value = result.cleanupIntervalMinutes
      confirming.value = false; saved.value = true
    } catch (failure) { if (active) error.value = message(failure) }
    finally { if (active) busy.value = false }
  }
  async function save() {
    if (!dirty.value || busy.value || loading.value) return
    if (shortened.value.length) { confirmationSnapshot = JSON.stringify(retention.value); confirming.value = true; return }
    await persist()
  }
  async function confirm() {
    if (!confirming.value || confirmationSnapshot !== JSON.stringify(retention.value)) return
    await persist()
  }
  onMounted(() => { void load() })
  onBeforeUnmount(() => { active = false; sequence++ })
  return { retention, changes, shortened, dirty, loading, busy, error, saved, confirming, cleanupInterval, load, save, confirm }
}
