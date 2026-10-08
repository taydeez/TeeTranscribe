<script setup lang="ts">
import type { DubbingRecord } from '~/types/dubbing'
const props = defineProps<{ record: DubbingRecord | null; opening: boolean; busy: boolean }>()
defineEmits<{ retry: []; refresh: [] }>()
const working = computed(() => Boolean(props.record && ['pending', 'processing'].includes(props.record.status)))
</script>
<template>
  <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" :aria-busy="working || opening">
    <h2 class="text-xl font-semibold text-slate-900">{{ record?.name ?? 'Your dubbed video' }}</h2>
    <p v-if="opening" class="mt-8 text-sm text-slate-500">Opening video…</p>
    <div v-else-if="!record" class="flex min-h-72 flex-col items-center justify-center text-center"><UiAppIcon name="video" class="mb-4 size-10 text-indigo-300" /><p class="text-sm text-slate-500">Reach a new audience in their language.</p><p class="mt-2 max-w-xs text-xs leading-relaxed text-slate-400">Your finished video will appear here, ready to preview and download.</p></div>
    <div v-else class="mt-5 space-y-5">
      <div v-if="working" class="rounded-xl bg-indigo-50 p-5"><UiAppIcon name="loader" class="mb-3 size-6 animate-spin text-indigo-600" /><p class="font-medium text-indigo-900">Preparing your dubbed video</p><p class="mt-2 text-sm text-indigo-700">You can leave this page. Your video will be saved here when it’s ready.</p></div>
      <div v-if="record.status === 'failed'" class="rounded-xl bg-rose-50 p-4"><p role="alert" class="text-sm text-rose-700">{{ record.failureReason }}</p><button v-if="record.canRetryExports" class="button-secondary mt-4" :disabled="busy" @click="$emit('retry')">{{ busy ? 'Retrying…' : 'Retry download preparation' }}</button></div>
      <template v-if="record.status === 'complete'">
        <video v-if="record.videoUrl" :key="record.videoUrl" :src="record.videoUrl" controls playsinline preload="metadata" class="aspect-video w-full rounded-xl bg-slate-950" />
        <p class="text-sm text-emerald-700">Your dubbed video is ready.</p>
        <div class="flex flex-wrap gap-3"><a v-if="record.videoDownloadUrl" :href="record.videoDownloadUrl" class="button-primary">Download video</a><a v-if="record.audioDownloadUrl" :href="record.audioDownloadUrl" class="button-secondary">Download audio</a></div>
        <button type="button" class="text-xs text-slate-500 underline" @click="$emit('refresh')">Refresh download links</button>
      </template>
    </div>
  </section>
</template>
