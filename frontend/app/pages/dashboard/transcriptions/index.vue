<script setup lang="ts">
import CreateFolderModal from '~/components/folders/CreateFolderModal.vue'
import FolderList from '~/components/folders/FolderList.vue'
import type { FolderPage } from '~/types/folder'

definePageMeta({ layout: 'dashboard', title: 'My Transcriptions' })
const route = useRoute()
const router = useRouter()
const folders = ref<FolderPage>({ data: [], meta: { currentPage: 1, lastPage: 1, perPage: 10, total: 0 } })
const loading = ref(true)
const error = ref('')
const createOpen = ref(false)
const search = ref(String(route.query.search ?? ''))
const sort = ref(String(route.query.sort ?? 'created_at:desc'))

async function load() {
  loading.value = true; error.value = ''
  try {
    const [sortField, direction] = sort.value.split(':')
    folders.value = await useAuthenticatedFetch<FolderPage>('/api/folders', { query: { page: Number(route.query.page ?? 1), per_page: 10, search: search.value || undefined, sort: sortField, direction } })
  } catch (failure: unknown) {
    const response = failure as { data?: { message?: string } }
    error.value = response.data?.message ?? 'Could not load your folders.'
  }
  finally { loading.value = false }
}

async function deleted() {
  if (folders.value.data.length === 1 && folders.value.meta.currentPage > 1) {
    await updateQuery({ page: folders.value.meta.currentPage - 1 })
  } else { await load() }
}

async function updateQuery(values: Record<string, string | number | undefined>) {
  await router.push({ query: { ...route.query, ...values } })
}
let searchTimer: ReturnType<typeof setTimeout>
watch(search, value => { clearTimeout(searchTimer); searchTimer = setTimeout(() => updateQuery({ search: value || undefined, page: 1 }), 300) })
watch(sort, value => updateQuery({ sort: value, page: 1 }))
watch(() => route.query, load, { immediate: true })
onBeforeUnmount(() => clearTimeout(searchTimer))
</script>

<template>
  <section class="mt-8 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="flex flex-col gap-5 border-b border-slate-200 bg-gradient-to-r from-white to-indigo-50/60 p-6 sm:flex-row sm:items-center sm:justify-between sm:p-8"><h2 class="text-3xl font-semibold tracking-[-.04em] text-slate-900 sm:text-3xl">Transcriptions</h2><button class="min-h-11 rounded-xl bg-indigo-600 px-5 text-sm font-bold text-white" @click="createOpen = true">+ Create folder</button></div>
    <div class="grid gap-3 border-b border-slate-200 p-5 sm:grid-cols-[minmax(0,1fr)_220px]"><input v-model="search" class="min-h-11 w-full rounded-xl border border-slate-300 px-4 text-sm" type="search" placeholder="Search folders by name"><select v-model="sort" class="min-h-11 rounded-xl border border-slate-300 px-3 text-sm font-semibold"><option value="created_at:desc">Newest first</option><option value="created_at:asc">Oldest first</option><option value="name:asc">Name A–Z</option><option value="name:desc">Name Z–A</option></select></div>
    <p v-if="error" class="m-6 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{{ error }}</p>
    <FolderList :folders="folders.data" :meta="folders.meta" :loading="loading" @page="updateQuery({ page: $event })" @deletion="deleted" />
  </section>
  <CreateFolderModal v-model:open="createOpen" @created="load" />
</template>
