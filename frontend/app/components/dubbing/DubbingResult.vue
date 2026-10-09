<script setup lang="ts">
import type { DubbingRecord } from '~/types/dubbing'
import type { PrivacyCategory, PrivacyDeletion } from '~/types/privacy'
import PrivacyDeleteAction from '~/components/privacy/PrivacyDeleteAction.vue'
const props = defineProps<{ record: DubbingRecord | null; opening: boolean; busy: boolean }>()
defineEmits<{ retry: []; refresh: []; deletion: [record: PrivacyDeletion] }>()
const working = computed(() => Boolean(props.record && ['pending', 'processing'].includes(props.record.status)))
const downloads = computed(() => {
  const record = props.record
  if (!record) return []
  const files: { key: string; url: string | null; label: string; category: PrivacyCategory }[] = [
    { key: 'video', url: record.videoDownloadUrl, label: record.subtitlesEnabled ? 'Download subtitled video' : 'Download video', category: 'dubbed_video' },
    { key: 'mp3', url: record.audioMp3DownloadUrl, label: 'Download MP3', category: 'dubbed_audio' },
    { key: 'audio', url: record.audioDownloadUrl, label: record.mediaType === 'audio' ? 'Download FLAC' : 'Download audio', category: 'dubbed_audio' },
    { key: 'subtitles', url: record.subtitleDownloadUrl, label: 'Download subtitles (SRT)', category: 'subtitles' },
    { key: 'clean-video', url: record.cleanVideoDownloadUrl, label: 'Video without subtitles', category: 'dubbed_video' },
  ]
  return files.filter(file => file.url)
})
</script>
<template>
  <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" :aria-busy="working || opening">
    <NuxtLink v-if="record?.folderId" :to="`/dashboard/transcriptions/${record.folderId}`" class="mb-3 inline-flex items-center gap-2 text-sm font-medium text-indigo-600"><UiAppIcon name="folder" :size="16" />Open folder</NuxtLink>
    <h2 class="text-xl font-semibold text-slate-900">{{ record?.name ?? 'Your result' }}</h2>
    <p v-if="opening" class="mt-8 text-sm text-slate-500">Opening result…</p>
    <div v-else-if="!record" class="flex min-h-72 flex-col items-center justify-center text-center"><UiAppIcon name="video" class="mb-4 size-10 text-indigo-300" /><p class="text-sm text-slate-500">Reach a new audience in their language.</p><p class="mt-2 max-w-xs text-xs leading-relaxed text-slate-400">Your finished audio or video will appear here, ready to preview and download.</p></div>
    <div v-else class="mt-5 space-y-5">
      <div v-if="working" class="rounded-xl bg-indigo-50 p-5"><UiAppIcon name="loader" class="mb-3 size-6 animate-spin text-indigo-600" /><p class="font-medium text-indigo-900">{{ record.mediaType === 'audio' ? 'Preparing your dubbed audio' : record.subtitleStatus === 'processing' ? 'Adding subtitles to your video' : record.operation === 'subtitles' ? 'Preparing your translated subtitles' : 'Preparing your dubbed video' }}</p><p class="mt-2 text-sm text-indigo-700">You can leave this page. Your result will be saved here when it’s ready.</p></div>
      <div v-if="record.status === 'failed'" class="rounded-xl bg-rose-50 p-4"><p role="alert" class="text-sm text-rose-700">{{ record.failureReason }}</p><button v-if="record.canRetryExports" class="button-secondary mt-4" :disabled="busy" @click="$emit('retry')">{{ busy ? 'Retrying…' : 'Retry download preparation' }}</button></div>
      <template v-if="record.status === 'complete'">
        <video v-if="record.videoUrl" :key="record.videoUrl" :src="record.videoUrl" controls playsinline preload="metadata" class="aspect-video w-full rounded-xl bg-slate-950" />
        <div v-if="record.mediaType === 'audio' && record.audioUrl" class="rounded-xl border border-indigo-100 bg-indigo-50 p-5"><p class="mb-4 text-sm font-medium text-indigo-900">Listen to your dubbed audio</p><audio :key="record.audioUrl" :src="record.audioUrl" controls preload="metadata" class="w-full" /></div>
        <p class="text-sm text-emerald-700">{{ record.mediaType === 'audio' ? 'Your dubbed audio is ready.' : record.operation === 'subtitles' ? 'Your subtitled video is ready. The original audio is preserved.' : 'Your dubbed video is ready.' }}</p>
        <div class="grid gap-3 sm:grid-cols-2">
          <div v-for="download in downloads" :key="download.key" class="relative">
            <a :href="download.url ?? undefined" class="flex min-h-20 items-center rounded-xl border border-indigo-100 bg-indigo-50 p-4 pr-12 text-sm font-semibold text-indigo-700">{{ download.label }}</a>
            <PrivacyDeleteAction placement="corner" resource-type="dubbing" :resource-id="record.id" scope="generated" :category="download.category" :name="record.name" :label="download.category === 'dubbed_audio' ? 'Delete generated audio files' : download.category === 'dubbed_video' ? 'Delete generated video files' : 'Delete subtitle file'" :disabled="busy" @completed="$emit('refresh')" />
          </div>
        </div>
        <button type="button" class="text-xs text-slate-500 underline" @click="$emit('refresh')">Refresh download links</button>
      </template>
      <div class="flex flex-wrap gap-3 border-t border-slate-100 pt-4">
        <PrivacyDeleteAction resource-type="dubbing" :resource-id="record.id" scope="source" show-label :name="record.name" label="Delete source file" :disabled="busy" @completed="$emit('refresh')" />
        <PrivacyDeleteAction resource-type="dubbing" :resource-id="record.id" scope="project" show-label :name="record.name" label="Delete project" :disabled="busy" @accepted="$emit('deletion', $event)" />
      </div>
    </div>
  </section>
</template>
