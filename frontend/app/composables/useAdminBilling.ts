import type { BillingConfiguration, BillingSettingsRecord } from '~/types/adminBilling'

export function useAdminBilling() {
  const auth = useAuthStore()
  const record = ref<BillingSettingsRecord | null>(null)
  const draft = ref<BillingConfiguration | null>(null)
  const reason = ref('')
  const loading = ref(false)
  const busy = ref(false)
  const error = ref('')
  const success = ref('')
  const can = (permission: string) => auth.user?.permissions?.includes(permission) ?? false
  const dirty = computed(() => !!record.value && JSON.stringify(record.value.configuration) !== JSON.stringify(draft.value))
  function accept(value: BillingSettingsRecord) {
    record.value = value
    draft.value = JSON.parse(JSON.stringify(value.configuration))
    reason.value = ''
  }
  async function load() {
    if (loading.value || busy.value || !can('ViewAny_Pricing')) return
    loading.value = true; error.value = ''; success.value = ''
    try { accept((await useAuthenticatedFetch<{ data: BillingSettingsRecord }>('/api/taydeez/billing/settings')).data) }
    catch (failure: unknown) { error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Billing settings could not be loaded.' }
    finally { loading.value = false }
  }
  function reset() { if (record.value && !busy.value) { accept(record.value); error.value = ''; success.value = '' } }
  async function save() {
    if (busy.value || loading.value || !draft.value || !record.value || !reason.value.trim() || !can('Update_Pricing')) return
    busy.value = true; error.value = ''; success.value = ''
    try {
      const result = await useAuthenticatedFetch<{ data: BillingSettingsRecord }>('/api/taydeez/billing/settings', {
        method: 'PUT', body: { configuration: JSON.parse(JSON.stringify(draft.value)), version: record.value.version, reason: reason.value.trim() },
      })
      accept(result.data)
      success.value = 'Billing settings saved. New purchases, usage quotes and task admissions will use these settings.'
    } catch (failure: unknown) { error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Billing settings could not be saved.' }
    finally { busy.value = false }
  }
  onMounted(() => { void load() })
  return { record, draft, reason, loading, busy, error, success, dirty, can, load, reset, save }
}
