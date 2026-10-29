import type { AdminCustomerList, CustomerSort } from '~/types/adminCustomer'

const sorts: CustomerSort[] = ['newest', 'oldest', 'name_asc', 'name_desc']

export function useAdminCustomers() {
  const route = useRoute()
  const router = useRouter()
  const auth = useAuthStore()
  const canView = computed(() => auth.isAdmin && (auth.user?.permissions?.includes('ViewAny_User') ?? false))
  const search = computed(() => typeof route.query.search === 'string' ? route.query.search : '')
  const searchInput = ref(search.value)
  const sort = computed<CustomerSort>(() => sorts.includes(route.query.sort as CustomerSort) ? route.query.sort as CustomerSort : 'newest')
  const page = computed(() => {
    const value = Number(route.query.page)
    return Number.isSafeInteger(value) && value > 0 ? value : 1
  })
  const result = ref<AdminCustomerList | null>(null)
  const loading = ref(false)
  const error = ref('')
  let requestId = 0

  async function load() {
    if (!canView.value) return
    const id = ++requestId
    loading.value = true
    error.value = ''
    result.value = null
    try {
      const response = await useAuthenticatedFetch<AdminCustomerList>('/api/taydeez/customers', {
        query: { page: page.value, per_page: 20, search: search.value, sort: sort.value },
      })
      if (id === requestId) result.value = response
    } catch (failure: unknown) {
      if (id !== requestId) return
      const response = failure as { data?: { message?: string } }
      error.value = response.data?.message ?? 'Customers could not be loaded. Please try again.'
    } finally {
      if (id === requestId) loading.value = false
    }
  }

  async function setQuery(values: { search?: string; sort?: string; page?: number }) {
    await router.replace({ query: { ...route.query, ...values } })
  }

  async function submitSearch() {
    await setQuery({ search: searchInput.value.trim() || undefined, page: 1 })
  }

  async function changeSort(value: string) {
    await setQuery({ sort: sorts.includes(value as CustomerSort) ? value : 'newest', page: 1 })
  }

  async function goToPage(value: number) {
    if (loading.value || value < 1 || value > (result.value?.meta.lastPage ?? 1)) return
    await setQuery({ page: value })
  }

  watch([search, sort, page], () => { searchInput.value = search.value; void load() })
  onMounted(() => { void load() })
  onBeforeUnmount(() => { requestId++ })
  return { canView, searchInput, sort, result, loading, error, load, submitSearch, changeSort, goToPage }
}
