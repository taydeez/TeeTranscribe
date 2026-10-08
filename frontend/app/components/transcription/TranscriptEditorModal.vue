<script setup lang="ts">
import SyncedTranscriptEditor from './SyncedTranscriptEditor.vue'
import TranscriptionExportDownloads from './TranscriptionExportDownloads.vue'
import { hasTranscriptChanges } from '~/utils/transcript'
import type { FolderTranscription, TranscriptSegment, TranscriptUpdate } from '~/types/transcription'
const props = defineProps<{ transcription: FolderTranscription | null }>()
const emit = defineEmits<{ close: []; updated: [value: FolderTranscription] }>()
const draft = ref('')
const saving = ref(false)
const error = ref('')
const saved = ref(false)
const translation = useTranslationDraftStore()
const segments = ref<TranscriptSegment[]>([])
const synced = computed(() => props.transcription?.provider === 'deepgram' && segments.value.length > 0)
const text = computed(() => synced.value ? segments.value.map(segment => segment.text.trim()).join('\n') : draft.value.trim())
watch(() => props.transcription?.id, () => { const value = props.transcription; draft.value = value?.transcript ?? ''; segments.value = (value?.segments ?? []).map(segment => ({ ...segment })); error.value = ''; saved.value = false }, { immediate: true })
const dirty = computed(() => hasTranscriptChanges(props.transcription, text.value, synced.value ? segments.value : null))
watch(dirty, value => { if (value) saved.value = false })
function prepareTranslation() {
  if (props.transcription) translation.prepare(props.transcription.id, props.transcription.name, text.value, synced.value ? segments.value : [])
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
<template><Teleport to="body"><div v-if="transcription" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/55 p-4 backdrop-blur-sm" @mousedown.self="emit('close')"><section class="max-h-[92dvh] w-full max-w-5xl overflow-y-auto rounded-2xl bg-white p-7 shadow-lg" role="dialog" aria-modal="true"><div class="flex justify-between gap-5"><div class="min-w-0"><p class="text-xs font-semibold tracking-[.2em] text-indigo-600">TRANSCRIPT</p><h2 class="mt-2 truncate text-3xl font-semibold">{{ transcription.name }}</h2><p class="mt-2 truncate text-sm text-slate-500">{{ transcription.fileName }}</p></div><button class="size-10 shrink-0 rounded-full border" aria-label="Close transcript editor" @click="emit('close')"><UiAppIcon name="close" class="mx-auto" /></button></div><form class="mt-7" @submit.prevent="save"><div class="flex justify-between"><label class="text-xs font-bold" for="transcript-editor">Transcript text</label><span class="text-xs text-slate-400">{{ draft.length.toLocaleString() }} characters</span></div><SyncedTranscriptEditor v-if="synced" v-model="segments" :audio-url="transcription.audioUrl ?? null" :disabled="saving" class="mt-4" /><textarea v-else id="transcript-editor" v-model="draft" :disabled="saving" class="mt-2 min-h-72 w-full rounded-2xl border border-slate-300 bg-slate-50 p-4 text-base leading-[1.65]" required /><div class="mt-3 flex justify-between gap-3"><p v-if="error" class="text-sm text-rose-600">{{ error }}</p><p v-else-if="saved" class="text-sm text-emerald-600">Saved.</p><p v-else class="text-xs text-slate-400">Saving changes regenerates TXT, PDF and DOCX.</p><button class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white disabled:opacity-50" :disabled="saving || !text || !dirty">{{ saving ? 'Saving…' : 'Save changes' }}</button></div></form><NuxtLink v-if="text && !saving" class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-indigo-600 hover:text-indigo-800" to="/dashboard/translations" @click="prepareTranslation"><UiAppIcon name="translate" :size="18" />Translate this text</NuxtLink><TranscriptionExportDownloads :transcription="transcription" @updated="emit('updated', $event)" /></section></div></Teleport></template>
