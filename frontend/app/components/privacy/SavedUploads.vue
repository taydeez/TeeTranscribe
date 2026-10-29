<script setup lang="ts">
import PrivacyDeleteAction from './PrivacyDeleteAction.vue'
import type { PrivacyDeletion } from '~/types/privacy'
import { privacyCategoryLabel } from '~/config/privacy'
const props = defineProps<{ version: number }>()
const emit = defineEmits<{ accepted: [record: PrivacyDeletion] }>()
const route = useRoute()
const { files, loading, error, page, load, accepted } = usePrivacyFiles()
watch(() => props.version, () => { void load(true) })
function requested(record: PrivacyDeletion) { accepted(record); emit('accepted', record) }
function size(bytes: number | null) { return bytes === null ? 'Size unavailable' : bytes < 1024 ** 2 ? `${Math.round(bytes / 1024)} KB` : `${(bytes / 1024 ** 2).toFixed(1)} MB` }
</script>
<template>
  <section class="surface overflow-hidden" :aria-busy="loading" aria-labelledby="saved-uploads-heading">
    <div class="flex items-center justify-between gap-3 border-b border-slate-100 p-5 sm:p-7"><div><h2 id="saved-uploads-heading" class="text-xl font-semibold">Saved source files</h2><p class="mt-2 text-sm text-slate-500">Manage uploaded audio, videos and recordings.</p></div><button type="button" class="min-h-11 px-2 text-sm text-indigo-600" :disabled="loading" @click="load(true)">Refresh</button></div>
    <p v-if="error" class="p-5 text-sm text-rose-700" role="alert">{{ error }} <button type="button" class="underline" @click="load()">Retry</button></p>
    <p v-if="loading" class="p-5 text-sm text-slate-500" role="status">Loading saved files…</p>
    <div v-else-if="files?.data.length" class="divide-y divide-slate-100"><div v-for="file in files.data" :key="`${file.resourceType}:${file.id}`" class="flex flex-wrap items-start justify-between gap-3 p-5 sm:px-7"><div class="min-w-0 flex-1"><h3 class="break-words text-sm font-semibold">{{ file.name }}</h3><p class="mt-1 text-xs text-slate-500">{{ privacyCategoryLabel(file.category) }} · {{ size(file.size) }}</p><p class="mt-1 text-xs text-slate-400">{{ new Date(file.createdAt).toLocaleDateString() }}</p></div><PrivacyDeleteAction :resource-type="file.resourceType" :resource-id="file.id" scope="source" :category="file.category" :name="file.name" label="Delete source" @accepted="requested" @completed="load(true)" /></div></div>
    <p v-else-if="!error" class="p-7 text-center text-sm text-slate-500">No saved source files.</p>
    <nav v-if="files && files.meta.lastPage > 1" class="flex items-center justify-between gap-3 border-t border-slate-100 p-5 text-sm" aria-label="Saved file pages"><NuxtLink v-if="page > 1" class="button-secondary" :to="{ query: { ...route.query, filesPage: page - 1 } }">Previous</NuxtLink><span v-else /><span>{{ page }} / {{ files.meta.lastPage }}</span><NuxtLink v-if="page < files.meta.lastPage" class="button-secondary" :to="{ query: { ...route.query, filesPage: page + 1 } }">Next</NuxtLink><span v-else /></nav>
  </section>
</template>
