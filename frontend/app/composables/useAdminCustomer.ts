import type { CustomerAccessInput, CustomerCreditInput, CustomerDetailResponse } from '~/types/adminCustomer'
import { customerCreditUnits } from '~/utils/customerCreditUnits'

export function useAdminCustomer() {
  const route = useRoute()
  const auth = useAuthStore()
  const id = computed(() => String(route.params.customerId))
  const canView = computed(() => auth.user?.permissions?.includes('View_User') ?? false)
  const canUpdate = computed(() => auth.user?.permissions?.includes('Update_User') ?? false)
  const canAdjust = computed(() => auth.user?.permissions?.includes('Create_CreditLedger') ?? false)
  const result = ref<CustomerDetailResponse | null>(null)
  const loading = ref(false)
  const busy = ref(false)
  const error = ref('')
  const actionError = ref('')
  const success = ref('')
  let revision = 0
  let adjustment: { signature: string; key: string } | undefined

  async function load() {
    if (!canView.value) return
    const current = ++revision
    loading.value = true
    error.value = ''
    result.value = null
    try {
      const response = await useAuthenticatedFetch<CustomerDetailResponse>(`/api/taydeez/customers/${id.value}`)
      if (current === revision) result.value = response
    } catch (failure: unknown) {
      if (current === revision) error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Customer could not be loaded.'
    } finally { if (current === revision) loading.value = false }
  }

  async function perform(operation: () => Promise<CustomerDetailResponse>, message: string) {
    if (busy.value) return false
    busy.value = true
    actionError.value = ''
    success.value = ''
    const current = revision
    try {
      const response = await operation()
      if (current !== revision) return false
      result.value = response
      success.value = message
      return true
    } catch (failure: unknown) {
      if (current === revision) {
        const response = failure as { data?: { message?: string }; message?: string }
        actionError.value = response.data?.message ?? response.message ?? 'The action could not be completed.'
      }
      return false
    } finally { busy.value = false }
  }

  async function updateAccess(input: CustomerAccessInput) {
    if (!canUpdate.value) return false
    return await perform(() => useAuthenticatedFetch<CustomerDetailResponse>(`/api/taydeez/customers/${id.value}/access`, {
      method: 'PATCH', body: input,
    }), 'Customer access updated.')
  }

  async function adjustCredits(input: CustomerCreditInput) {
    if (!canAdjust.value) return false
    return await perform(async () => {
      const units = customerCreditUnits(input.credits)
      const signature = JSON.stringify([id.value, input.action, units, input.reason.trim()])
      if (adjustment?.signature !== signature) adjustment = { signature, key: crypto.randomUUID() }
      const response = await useAuthenticatedFetch<CustomerDetailResponse>(`/api/taydeez/customers/${id.value}/credits`, {
        method: 'POST', body: { action: input.action, credit_units: units, reason: input.reason.trim(), client_key: adjustment.key },
      })
      adjustment = undefined
      return response
    }, 'Customer credits updated.')
  }

  watch(id, () => { revision++; adjustment = undefined; success.value = ''; actionError.value = ''; void load() })
  onMounted(() => { void load() })
  onBeforeUnmount(() => { revision++ })
  return { canView, canUpdate, canAdjust, result, loading, busy, error, actionError, success, load, updateAccess, adjustCredits }
}
