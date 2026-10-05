<script setup lang="ts">
import SyncedTranscriptEditor from './SyncedTranscriptEditor.vue'
import type { FolderTranscription, TranscriptSegment, TranscriptUpdate } from '~/types/transcription'
const props = defineProps<{ transcription: FolderTranscription | null }>()
const emit = defineEmits<{ close: []; updated: [value: FolderTranscription] }>()
const draft = ref('')
const saving = ref(false)
const error = ref('')
const saved = ref(false)
const segments = ref<TranscriptSegment[]>([])
const synced = computed(() => props.transcription?.provider === 'deepgram' && segments.value.length > 0)
const text = computed(() => synced.value ? segments.value.map(segment => segment.text.trim()).join('\n') : draft.value.trim())
watch(() => props.transcription, value => { draft.value = value?.transcript ?? ''; segments.value = (value?.segments ?? []).map(segment => ({ ...segment })); error.value = ''; saved.value = false }, { immediate: true })
async function save() {
  if (!props.transcription || !text.value || saving.value) return
  saving.value = true; error.value = ''; saved.value = false
  try {
    const result = await useAuthenticatedFetch<TranscriptUpdate>(`/api/transcriptions/${props.transcription.id}`, { method: 'PATCH', body: { transcript: text.value, ...(synced.value ? { segments: segments.value.map(segment => ({ text: segment.text, speaker: segment.speaker })) } : {}) } })
    const updated = { ...props.transcription, segments: result.segments, transcript: result.transcript, status: result.status, exports: props.transcription.exports.map(item => ({ ...item, status: 'pending' as const, downloadUrl: null })) }
    emit('updated', updated); draft.value = result.transcript; saved.value = true
  } catch (failure: unknown) {
    const response = failure as { data?: { message?: string } }
    error.value = response.data?.message ?? 'Could not save this transcript.'
  }
  finally { saving.value = false }
}
</script>
<template><Teleport to="body"><div v-if="transcription" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/55 p-4 backdrop-blur-sm" @mousedown.self="emit('close')"><section class="max-h-[92dvh] w-full max-w-5xl overflow-y-auto rounded-2xl bg-white p-7 shadow-lg" role="dialog" aria-modal="true"><div class="flex justify-between gap-5"><div class="min-w-0"><p class="text-xs font-semibold tracking-[.2em] text-indigo-600">TRANSCRIPT</p><h2 class="mt-2 truncate text-3xl font-semibold">{{ transcription.name }}</h2><p class="mt-2 truncate text-sm text-slate-500">{{ transcription.fileName }}</p></div><button class="size-10 shrink-0 rounded-full border" aria-label="Close transcript editor" @click="emit('close')"><UiAppIcon name="close" class="mx-auto" /></button></div><form class="mt-7" @submit.prevent="save"><div class="flex justify-between"><label class="text-xs font-bold" for="transcript-editor">Transcript text</label><span class="text-xs text-slate-400">{{ draft.length.toLocaleString() }} characters</span></div><SyncedTranscriptEditor v-if="synced" v-model="segments" :audio-url="transcription.audioUrl ?? null" :disabled="saving" class="mt-4" /><textarea v-else id="transcript-editor" v-model="draft" class="mt-2 min-h-72 w-full rounded-2xl border border-slate-300 bg-slate-50 p-4 text-base leading-[1.65]" required /><div class="mt-3 flex justify-between gap-3"><p v-if="error" class="text-sm text-rose-600">{{ error }}</p><p v-else-if="saved" class="text-sm text-emerald-600">Saved. New exports are being generated.</p><p v-else class="text-xs text-slate-400">Saving regenerates TXT and PDF.</p><button class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white disabled:opacity-50" :disabled="saving || !text">{{ saving ? 'Saving…' : 'Save changes' }}</button></div></form><div class="mt-8 border-t pt-6"><p class="text-xs font-semibold tracking-[.2em] text-slate-500">EXPORTS</p><div v-if="transcription.exports.length" class="mt-4 grid gap-3 sm:grid-cols-2"><template v-for="item in transcription.exports" :key="item.id"><a v-if="item.downloadUrl" class="flex min-h-24 items-center justify-between rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4" :href="item.downloadUrl" target="_blank"><span><strong class="block text-sm uppercase">{{ item.format }}</strong><small class="text-xs text-slate-500">Ready to download</small></span><span>↓</span></a><div v-else class="rounded-2xl border bg-slate-50 p-4"><strong class="text-sm uppercase">{{ item.format }}</strong><small class="block text-xs capitalize text-slate-500">{{ item.status }}</small></div></template></div><p v-else class="mt-4 rounded-2xl border border-dashed p-6 text-center text-sm text-slate-500">Exports are not available yet.</p></div></section></div></Teleport></template>
