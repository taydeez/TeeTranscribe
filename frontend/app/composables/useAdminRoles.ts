import type { AdminPage, AdminRole } from '~/types/adminAccount'
export function useAdminRoles() {
  const auth = useAuthStore()
  const route = useRoute()
  const router = useRouter()
  const roles = ref<AdminRole[]>([])
  const allPermissions = ref<string[]>([])
  const selectedPermissions = ref<string[]>([])
  const loading = ref(false)
  const busy = ref(false)
  const error = ref('')
  const success = ref('')
  const can = (value: string) => auth.user?.permissions?.includes(value) ?? false
  const selected = computed(() => roles.value.find(role => String(role.id) === String(route.query.role)) ?? roles.value[0])
  const mutable = computed(() => !!selected.value && !['user', 'super_admin'].includes(selected.value.name) && can('Update_Role'))
  async function load() {
    if (!can('ViewAny_Role')) return
    loading.value = true
    error.value = ''
    try {
      const items: AdminRole[] = []
      let page = 1
      let lastPage = 1
      do {
        const response = await useAuthenticatedFetch<AdminPage<AdminRole>>('/api/taydeez/roles', { query: { per_page: 100, page } })
        items.push(...response.data); lastPage = response.meta.lastPage; page++
      } while (page <= lastPage)
      roles.value = items
      const response = await useAuthenticatedFetch<{ data: string[] }>('/api/taydeez/permissions')
      allPermissions.value = response.data
    } catch (failure: unknown) { error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Roles could not be loaded.' }
    finally { loading.value = false }
  }
  watch(selected, role => { selectedPermissions.value = [...(role?.permissions ?? [])] }, { immediate: true })
  async function selectRole(id: string) { await router.replace({ query: { ...route.query, role: id } }) }
  async function perform(operation: () => Promise<void>, message: string) {
    if (busy.value) return false
    busy.value = true; error.value = ''; success.value = ''
    try { await operation(); await load(); success.value = message; return true }
    catch (failure: unknown) { error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Role could not be saved.'; return false }
    finally { busy.value = false }
  }
  async function create(name: string) {
    if (!can('Create_Role')) return false
    return await perform(async () => {
      const response = await useAuthenticatedFetch<{ data: AdminRole }>('/api/taydeez/roles', { method: 'POST', body: { name: name.trim() } })
      await selectRole(String(response.data.id))
    }, 'Role created. Select its permissions below.')
  }
  async function save() {
    if (!mutable.value || !selected.value) return false
    const id = selected.value.id
    const permissions = [...selectedPermissions.value]
    return await perform(async () => { await useAuthenticatedFetch(`/api/taydeez/roles/${id}/permissions`, { method: 'PUT', body: { permissions } }); await auth.refreshUser() }, 'Permissions updated.')
  }
  onMounted(() => { void load() })
  return { can, roles, allPermissions, selectedPermissions, selected, mutable, loading, busy, error, success, create, save, selectRole }
}
