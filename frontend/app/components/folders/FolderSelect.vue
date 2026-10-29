<script setup lang="ts">
import CreateFolderModal from './CreateFolderModal.vue'
import type { Folder, FolderOption, FolderOptionPage } from '~/types/folder'

withDefaults(defineProps<{ id: string; disabled?: boolean; automaticLabel?: string }>(), {
  disabled: false, automaticLabel: 'Today’s folder (automatic)',
})
const selected = defineModel<string>({ required: true })
const folders = ref<FolderOption[]>([])
const loading = ref(false)
const error = ref('')
const creating = ref(false)
let alive = true
async function load() {
  if (loading.value) return
  loading.value = true; error.value = ''
  try {
    const first = await useAuthenticatedFetch<FolderOptionPage>('/api/folders', { query: { page: 1, per_page: 50, sort: 'name', direction: 'asc' } })
    const items = [...first.data]
    for (let page = 2; page <= first.meta.lastPage && alive; page++) {
      const result = await useAuthenticatedFetch<FolderOptionPage>('/api/folders', { query: { page, per_page: 50, sort: 'name', direction: 'asc' } })
      items.push(...result.data)
    }
    if (alive) folders.value = items
  } catch { if (alive) error.value = 'Could not load folders. Try again or use the automatic folder.' }
  finally { if (alive) loading.value = false }
}
function created(folder: Folder) {
  folders.value = [...folders.value.filter(item => item.id !== folder.id), folder].sort((a, b) => a.name.localeCompare(b.name))
  selected.value = folder.id
}
onMounted(() => { void load() })
onBeforeUnmount(() => { alive = false })
</script>
<template>
  <div class="space-y-2">
    <div class="flex items-center justify-between gap-3"><label :for="id" class="text-sm font-medium text-slate-700">Save to folder</label><button type="button" class="text-xs font-semibold text-indigo-600 underline disabled:opacity-50" :disabled="disabled" @click="creating = true">Create new folder</button></div>
    <select :id="id" v-model="selected" class="min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" :disabled="disabled || loading" :aria-busy="loading">
      <option value="">{{ loading ? 'Loading folders…' : automaticLabel }}</option>
      <option v-for="folder in folders" :key="folder.id" :value="folder.id">{{ folder.name }}</option>
    </select>
    <p v-if="error" class="text-xs text-rose-600" role="alert">{{ error }} <button type="button" class="underline" :disabled="disabled || loading" @click="load">Retry</button></p>
    <CreateFolderModal v-model:open="creating" @created="created" />
  </div>
</template>
