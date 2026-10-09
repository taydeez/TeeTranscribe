<script setup lang="ts">
import PrivacyDeleteAction from '~/components/privacy/PrivacyDeleteAction.vue'
import TranscriptEditorModal from '~/components/transcription/TranscriptEditorModal.vue'
import TranscriptionList from '~/components/transcription/TranscriptionList.vue'
import FolderProjectList from '~/components/folders/FolderProjectList.vue'
import type { FolderTranscription } from '~/types/transcription'
import type { PrivacyDeletion } from '~/types/privacy'

definePageMeta({ layout: 'dashboard', title: 'My Transcriptions' })
const route = useRoute()
const { folder, loading, error, load } = useFolderTranscriptions(() => String(route.params.folderId))
const selected = ref<FolderTranscription | null>(null)
const deletionRequested = ref(false)
watch(folder, (value) => {
  if (selected.value) selected.value = value?.transcriptions.find(item => item.id === selected.value?.id) ?? null
})
function updated(value: FolderTranscription) {
  selected.value = value
  if (folder.value) folder.value.transcriptions = folder.value.transcriptions.map(item => item.id === value.id ? value : item)
}
function deletion(record: PrivacyDeletion) {
  deletionRequested.value = true
  if (record.resourceType === 'transcription' && selected.value?.id === record.resourceId) selected.value = null
  if (folder.value) {
    folder.value.transcriptions = folder.value.transcriptions.filter(item => record.resourceType !== 'transcription' || item.id !== record.resourceId)
    folder.value.projects = folder.value.projects.filter(item => item.id !== record.resourceId || (item.type === 'translation' ? 'translation' : 'dubbing') !== record.resourceType)
  }
  void load(true)
}
</script>
<template>
  <section class="mt-8 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="flex items-center gap-4 border-b border-slate-200 bg-gradient-to-r from-white to-indigo-50/60 p-6 sm:p-8"><NuxtLink class="grid size-11 place-items-center rounded-xl border bg-white text-lg" to="/dashboard/transcriptions">←</NuxtLink><div><p class="text-xs font-semibold tracking-[.16em] text-indigo-600">FOLDER</p><h2 class="text-3xl font-semibold tracking-[-.04em] text-slate-900 sm:text-3xl">{{ folder?.name ?? 'Transcriptions' }}</h2></div></div>
    <div v-if="loading" class="grid min-h-64 place-items-center text-sm text-slate-500">Loading folder…</div>
    <template v-else>
      <p v-if="deletionRequested" class="m-6 rounded-xl bg-indigo-50 p-4 text-sm text-indigo-700" role="status">Deletion requested. <NuxtLink to="/dashboard/settings#privacy" class="font-semibold underline">Follow cleanup progress</NuxtLink></p>
      <p v-if="error" class="m-6 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700" role="alert">{{ error }}</p>
      <div v-if="folder" class="flex flex-wrap gap-3 px-6 py-5 sm:px-8"><PrivacyDeleteAction resource-type="folder" :resource-id="folder.id" scope="project" :name="folder.name" label="Delete folder" show-label @accepted="navigateTo('/dashboard/transcriptions')" /><NuxtLink class="button-secondary" :to="{ path: '/dashboard/translations', query: { folder: folder.id } }">New translation</NuxtLink><NuxtLink class="button-secondary" :to="{ path: '/dashboard/dubbing', query: { folder: folder.id } }">New dubbing or subtitles</NuxtLink></div>
      <TranscriptionList v-if="folder?.transcriptions.length || !folder?.projects.length" :items="folder?.transcriptions ?? []" @select="selected = $event" @deletion="deletion" />
      <FolderProjectList v-if="folder" :items="folder.projects ?? []" :folder-id="folder.id" @deletion="deletion" />
    </template>
  </section>
  <TranscriptEditorModal :transcription="selected" @close="selected = null" @updated="updated" @deletion="deletion" @refresh="load(true)" />
</template>
