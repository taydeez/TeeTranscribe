import type { AdminAccount, AdminAccountInput, AdminPage, AdminRole } from '~/types/adminAccount'

export function useAdminAccounts() {
  const auth = useAuthStore()
  const route = useRoute()
  const router = useRouter()
  const result = ref<AdminPage<AdminAccount> | null>(null)
  const roles = ref<AdminRole[]>([])
  const loading = ref(false)
  const busy = ref(false)
  const error = ref('')
  const success = ref('')
  const deleting = ref<AdminAccount | null>(null)
  const page = computed(() => Math.max(1, Number(route.query.page) || 1))
  const can = (permission: string) => auth.user?.permissions?.includes(permission) ?? false
  let version = 0

  async function load() {
    if (!can('ViewAny_AdminAccount')) return
    const current = ++version
    loading.value = true
    error.value = ''
    try {
      const response = await useAuthenticatedFetch<AdminPage<AdminAccount>>('/api/taydeez/accounts', { query: { page: page.value } })
      if (current === version) result.value = response
      if (can('ViewAny_Role')) {
        const available: AdminRole[] = []
        let rolePage = 1
        let lastPage = 1
        do {
          const response = await useAuthenticatedFetch<AdminPage<AdminRole>>('/api/taydeez/roles', { query: { per_page: 100, page: rolePage } })
          available.push(...response.data.filter(role => role.name !== 'user'))
          lastPage = response.meta.lastPage
          rolePage++
        } while (rolePage <= lastPage && current === version)
        if (current === version) roles.value = available
      }
    } catch (failure: unknown) {
      if (current === version) error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Admin accounts could not be loaded.'
    } finally { if (current === version) loading.value = false }
  }
  async function perform(operation: () => Promise<unknown>, message: string) {
    if (busy.value) return false
    busy.value = true
    error.value = ''
    success.value = ''
    try {
      await operation()
      deleting.value = null
      success.value = message
      await load()
      return true
    } catch (failure: unknown) {
      error.value = (failure as { data?: { message?: string } }).data?.message ?? 'The action could not be completed.'
      return false
    } finally { busy.value = false }
  }
  async function create(input: AdminAccountInput) {
    if (!can('Create_AdminAccount')) return false
    return await perform(() => useAuthenticatedFetch('/api/taydeez/accounts', { method: 'POST', body: input }), 'Administrator created. Share the initial password securely; they must change it on first login.')
  }
  async function updateRole(account: AdminAccount, roleId: number) {
    if (!can('Update_AdminAccount')) return false
    return await perform(() => useAuthenticatedFetch(`/api/taydeez/accounts/${account.id}/role`, { method: 'PATCH', body: { role_id: roleId } }), 'Role updated. Existing sessions were signed out.')
  }
  async function remove() {
    if (!deleting.value || !can('Delete_AdminAccount')) return false
    return await perform(() => useAuthenticatedFetch(`/api/taydeez/accounts/${deleting.value!.id}`, { method: 'DELETE' }), 'Administrator deleted.')
  }
  async function goToPage(value: number) { await router.replace({ query: { ...route.query, page: value } }) }
  watch(page, () => { void load() })
  onMounted(() => { void load() })
  onBeforeUnmount(() => { version++ })
  return { auth, can, result, roles, loading, busy, error, success, deleting, create, updateRole, remove, goToPage }
}
