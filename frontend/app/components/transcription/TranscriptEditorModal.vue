<script setup lang="ts">
import SyncedTranscriptEditor from './SyncedTranscriptEditor.vue'
import TranscriptionExportDownloads from './TranscriptionExportDownloads.vue'
import TranscriptTools from './TranscriptTools.vue'
import PrivacyDeleteAction from '~/components/privacy/PrivacyDeleteAction.vue'
import type { PrivacyDeletion } from '~/types/privacy'
import { hasTranscriptChanges } from '~/utils/transcript'
import type { FolderTranscription, TranscriptSegment, TranscriptUpdate } from '~/types/transcription'
import type { TranscriptToolResult } from '~/types/transcriptTools'
const props = defineProps<{ transcription: FolderTranscription | null }>()
const emit = defineEmits<{ close: []; updated: [value: FolderTranscription]; deletion: [record: PrivacyDeletion]; refresh: [] }>()
const draft = ref('')
const saving = ref(false)
const error = ref('')
const saved = ref(false)
const translation = useTranslationDraftStore()
const segments = ref<TranscriptSegment[]>([])
const synced = computed(() => segments.value.length > 0)
const text = computed(() => synced.value ? segments.value.map(segment => segment.text.trim()).join('\n') : draft.value.trim())
watch(() => props.transcription?.id, () => { const value = props.transcription; draft.value = value?.transcript ?? ''; segments.value = (value?.segments ?? []).map(segment => ({ ...segment })); error.value = ''; saved.value = false }, { immediate: true })
const dirty = computed(() => hasTranscriptChanges(props.transcription, text.value, synced.value ? segments.value : null))
watch(dirty, value => { if (value) saved.value = false })
function prepareTranslation() {
  if (props.transcription) translation.prepare(props.transcription.id, props.transcription.name, text.value, synced.value ? segments.value : [])
}
function applyCleanup(result: TranscriptToolResult) {
  if (dirty.value || saving.value || !result.text?.trim()) return
  if (synced.value) {
    if (result.segments?.length !== segments.value.length) { error.value = 'This preview does not match your transcript. Refresh the saved results and try again.'; return }
    segments.value = segments.value.map((segment, index) => ({ ...segment, text: result.segments![index]!.text }))
  }
  draft.value = result.text
  saved.value = false
  error.value = ''
}
async function save() {
  if (!props.transcription || !text.value || !dirty.value || saving.value) return
  saving.value = true; error.value = ''; saved.value = false
  try {
    const result = await useAuthenticatedFetch<TranscriptUpdate>(`/api/transcriptions/${props.transcription.id}`, { method: 'PATCH', body: { transcript: text.value, ...(synced.value ? { segments: segments.value.map(segment => ({ text: segment.text, speaker: segment.speaker })) } : {}) } })
    const updated = { ...props.transcription, segments: result.segments, transcript: result.transcript, status: result.status, exports: result.status === 'processing' ? props.transcription.exports.map(item => ({ ...item, status: 'pending' as const, downloadUrl: null })) : props.transcription.exports }
    emit('updated', updated); draft.value = result.transcript; segments.value = result.segments.map(segment => ({ ...segment })); saved.value = true
  } catch (failure: unknown) {
    const response = failure as { data?: { message?: string } }
    error.value = response.data?.message ?? 'Could not save this transcript.'
  }
  finally { saving.value = false }
}
</script>
<template>
  <Teleport to="body">
    <div v-if="transcription" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/55 p-3 backdrop-blur-sm sm:p-4" @mousedown.self="emit('close')">
      <section class="max-h-[92dvh] w-full max-w-5xl overflow-y-auto rounded-2xl bg-white p-4 shadow-lg sm:p-7" role="dialog" aria-modal="true" aria-labelledby="transcript-editor-heading">
        <div class="flex justify-between gap-5">
          <div class="min-w-0"><p class="text-xs font-semibold tracking-[.2em] text-indigo-600">TRANSCRIPT</p><h2 id="transcript-editor-heading" class="mt-2 truncate text-2xl font-semibold sm:text-3xl">{{ transcription.name }}</h2><p class="mt-2 truncate text-sm text-slate-500">{{ transcription.fileName }}</p></div>
          <button class="size-11 shrink-0 rounded-full border" aria-label="Close transcript editor" @click="emit('close')"><UiAppIcon name="close" class="mx-auto" /></button>
        </div>
        <TranscriptTools v-if="transcription.transcript?.trim()" :key="transcription.id" :transcription="transcription" :blocked="dirty || saving" @apply="applyCleanup" />
        <form class="mt-7" @submit.prevent="save">
          <div class="flex justify-between gap-3"><label class="text-xs font-bold" for="transcript-editor">Transcript text</label><span class="text-xs text-slate-400">{{ text.length.toLocaleString() }} characters</span></div>
          <SyncedTranscriptEditor v-if="synced" v-model="segments" :audio-url="transcription.audioUrl ?? null" :disabled="saving" class="mt-4" />
          <textarea v-else id="transcript-editor" v-model="draft" :disabled="saving" class="mt-2 min-h-72 w-full rounded-2xl border border-slate-300 bg-slate-50 p-4 text-base leading-[1.65]" required />
          <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <p v-if="error" class="text-sm text-rose-600" role="alert">{{ error }}</p><p v-else-if="saved" class="text-sm text-emerald-600">Saved.</p><p v-else class="text-xs text-slate-400">Saving changes regenerates TXT, PDF and DOCX.</p>
            <button class="w-full rounded-xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white disabled:opacity-50 sm:w-auto" :disabled="saving || !text || !dirty">{{ saving ? 'Saving…' : 'Save changes' }}</button>
          </div>
        </form>
        <NuxtLink v-if="text && !saving" class="mt-5 inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-indigo-600 hover:text-indigo-800" to="/dashboard/translations" @click="prepareTranslation"><UiAppIcon name="translate" :size="18" />Translate this text</NuxtLink>
        <TranscriptionExportDownloads :transcription="transcription" @updated="emit('updated', $event)" @refresh="emit('refresh')" />
        <div class="mt-6 flex flex-wrap gap-4 border-t border-slate-100 pt-4"><PrivacyDeleteAction resource-type="transcription" :resource-id="transcription.id" scope="source" show-label :name="transcription.name" label="Delete source file" :disabled="saving" @completed="emit('refresh')" /><PrivacyDeleteAction resource-type="transcription" :resource-id="transcription.id" scope="project" show-label :name="transcription.name" label="Delete transcription" :disabled="saving" @accepted="emit('deletion', $event)" /></div>
      </section>
    </div>
  </Teleport>
</template>
