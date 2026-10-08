<script setup lang="ts">
import type { FolderTranscription, TranscriptionExportVariant } from '~/types/transcription'
import { exportDownloads } from '~/utils/transcript'

const props = defineProps<{ transcription: FolderTranscription }>()
const emit = defineEmits<{ updated: [value: FolderTranscription] }>()
const variant = ref<TranscriptionExportVariant>('plain')
const preparing = ref(false)
const error = ref('')
const hasSpeakers = computed(() => props.transcription.segments?.some(segment => Boolean(segment.speaker?.trim())) ?? false)
const downloads = computed(() => exportDownloads(props.transcription, hasSpeakers.value ? variant.value : 'plain'))
const needsPreparation = computed(() => props.transcription.status !== 'pending' && props.transcription.status !== 'processing' && Boolean(props.transcription.transcript) && downloads.value.some(download => !download.ready))
watch(() => props.transcription.id, () => { variant.value = 'plain'; error.value = '' })
async function prepare() {
  if (preparing.value) return
  preparing.value = true; error.value = ''
  try {
    const result = await useAuthenticatedFetch<{ id: string; status: string }>(`/api/transcriptions/${props.transcription.id}/exports`, { method: 'POST' })
    emit('updated', { ...props.transcription, status: result.status, exports: props.transcription.exports.map(item => item.status === 'failed' ? { ...item, status: 'pending' as const, downloadUrl: null } : item) })
  } catch (failure: unknown) {
    error.value = (failure as { data?: { message?: string } }).data?.message ?? 'Could not prepare your downloads.'
  } finally { preparing.value = false }
}
</script>

<template>
  <div class="mt-8 border-t pt-6">
    <p class="text-xs font-semibold tracking-[.2em] text-slate-500">EXPORTS</p>
    <label v-if="hasSpeakers" class="mt-4 block text-sm font-medium text-slate-700">Download style
      <select v-model="variant" class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 sm:w-64"><option value="plain">Plain text</option><option value="speakers">With speaker labels</option></select>
    </label>
    <p v-else class="mt-3 text-xs text-slate-500">Speaker labels are available when the transcript includes speaker data.</p>
    <button v-if="needsPreparation" type="button" class="mt-4 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="preparing" @click="prepare">{{ preparing ? 'Preparing…' : 'Prepare downloads' }}</button>
    <p v-if="error" role="alert" class="mt-3 text-sm text-rose-600">{{ error }}</p>
    <div class="mt-4 grid gap-3 sm:grid-cols-3">
      <template v-for="download in downloads" :key="download.format">
        <a v-if="download.ready" class="flex min-h-24 items-center justify-between rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4" :href="download.item?.downloadUrl ?? undefined" target="_blank" rel="noopener"><span><strong class="block text-sm uppercase">Download {{ download.format }}</strong><small class="text-xs text-slate-500">Ready to download</small></span><span aria-hidden="true">↓</span></a>
        <button v-else class="flex min-h-24 items-center justify-between rounded-2xl border bg-slate-50 p-4 text-left" type="button" disabled :aria-busy="download.working">
          <span><strong class="block text-sm uppercase">Download {{ download.format }}</strong><small class="block text-xs text-slate-500" role="status">{{ download.working ? 'Generating your file…' : download.item?.status === 'failed' ? 'Generation failed' : 'Not available yet' }}</small></span>
          <span v-if="download.working" class="size-5 shrink-0 rounded-full border-2 border-indigo-200 border-t-indigo-600 motion-safe:animate-spin" aria-hidden="true" />
        </button>
      </template>
    </div>
  </div>
</template>
