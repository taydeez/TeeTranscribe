<script setup lang="ts">
import type { Folder } from '~/types/folder'
defineProps<{ open: boolean }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; created: [folder: Folder] }>()
const name = ref('')
const error = ref('')
const busy = ref(false)
async function create() {
  if (!name.value.trim() || busy.value) return
  busy.value = true; error.value = ''
  try {
    const folder = await useAuthenticatedFetch<Folder>('/api/folders', { method: 'POST', body: { name: name.value.trim() } })
    name.value = ''; emit('created', folder); emit('update:open', false)
  } catch (failure: unknown) {
    const response = failure as { data?: { message?: string } }
    error.value = response.data?.message ?? 'Could not create the folder.'
  }
  finally { busy.value = false }
}
</script>
<template><Teleport to="body"><div v-if="open" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/55 p-4 " @mousedown.self="emit('update:open', false)"><section class="w-full max-w-md rounded-2xl bg-white p-7 shadow-lg" role="dialog" aria-modal="true" aria-label="Create folder"><div class="flex justify-between"><div><p class="text-xs font-semibold tracking-[.2em] text-indigo-600">NEW FOLDER</p><h2 class="mt-2 text-3xl font-semibold text-slate-900">Create folder</h2></div><button class="size-10 rounded-full border border-slate-200" type="button" aria-label="Close" @click="emit('update:open', false)"><UiAppIcon name="close" class="mx-auto" /></button></div><form class="mt-7" @submit.prevent="create"><label class="text-xs font-bold text-slate-700" for="folder-name">Folder name</label><input id="folder-name" v-model="name" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3.5 text-sm" required maxlength="255" placeholder="e.g. Client interviews"><p v-if="error" class="mt-3 text-sm text-rose-600">{{ error }}</p><div class="mt-6 flex justify-end gap-3"><button class="px-5 text-sm font-bold text-slate-600" type="button" @click="emit('update:open', false)">Cancel</button><button class="min-h-11 rounded-xl bg-indigo-600 px-5 text-sm font-bold text-white disabled:opacity-50" :disabled="busy">{{ busy ? 'Creating…' : 'Create folder' }}</button></div></form></section></div></Teleport></template>
