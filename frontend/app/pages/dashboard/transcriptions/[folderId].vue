<script setup lang="ts">
import TranscriptEditorModal from '~/components/transcription/TranscriptEditorModal.vue'
import TranscriptionList from '~/components/transcription/TranscriptionList.vue'
import type { FolderTranscription } from '~/types/transcription'

definePageMeta({ layout: 'dashboard', title: 'My Transcriptions' })
const route = useRoute()
const { folder, loading, error } = useFolderTranscriptions(() => String(route.params.folderId))
const selected = ref<FolderTranscription | null>(null)
watch(folder, (value) => {
  if (selected.value) selected.value = value?.transcriptions.find(item => item.id === selected.value?.id) ?? null
})
function updated(value: FolderTranscription) {
  selected.value = value
  if (folder.value) folder.value.transcriptions = folder.value.transcriptions.map(item => item.id === value.id ? value : item)
}
</script>
<template>
  <section class="mt-8 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="flex items-center gap-4 border-b border-slate-200 bg-gradient-to-r from-white to-indigo-50/60 p-6 sm:p-8"><NuxtLink class="grid size-11 place-items-center rounded-xl border bg-white text-lg" to="/dashboard/transcriptions">←</NuxtLink><div><p class="text-xs font-semibold tracking-[.16em] text-indigo-600">FOLDER</p><h2 class="text-3xl font-semibold tracking-[-.04em] text-slate-900 sm:text-3xl">{{ folder?.name ?? 'Transcriptions' }}</h2></div></div>
    <div v-if="loading" class="grid min-h-64 place-items-center text-sm text-slate-500">Loading transcriptions…</div>
    <template v-else>
      <p v-if="error" class="m-6 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700" role="alert">{{ error }}</p>
      <TranscriptionList :items="folder?.transcriptions ?? []" @select="selected = $event" />
    </template>
  </section>
  <TranscriptEditorModal :transcription="selected" @close="selected = null" @updated="updated" />
</template>
