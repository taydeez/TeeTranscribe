<script setup lang="ts">
import type { TranscriptSegment } from '~/types/transcription'
import MediaAudioPlayer from './MediaAudioPlayer.vue'

defineProps<{ audioUrl: string | null; disabled: boolean }>()
const segments = defineModel<TranscriptSegment[]>({ required: true })
const player = ref<InstanceType<typeof MediaAudioPlayer> | null>(null)
const currentTime = ref(0)
function timestamp(seconds: number) {
  return [Math.floor(seconds / 3600), Math.floor(seconds / 60) % 60, Math.floor(seconds % 60)].map(part => String(part).padStart(2, '0')).join(':')
}
async function seek(seconds: number) {
  await player.value?.seek(seconds)
}
</script>

<template>
  <div class="grid gap-6 md:grid-cols-[280px_1fr]">
    <aside class="self-start rounded-xl border border-slate-200 bg-slate-50 p-4 md:sticky md:top-0">
      <MediaAudioPlayer v-if="audioUrl" ref="player" v-model:current-time="currentTime" :src="audioUrl" />
      <p v-else class="text-sm text-slate-500">Recording unavailable.</p>
      <p class="mt-3 text-xs text-slate-500">Click a timestamp to play that section. Edit text and speaker names below.</p>
    </aside>
    <div class="space-y-3">
      <div v-for="(segment, index) in segments" :key="index" class="rounded-2xl border p-4 transition-colors" :class="currentTime >= segment.start && currentTime < segment.end ? 'border-indigo-400 bg-indigo-50' : 'border-slate-200'">
        <div class="mb-2 flex items-center justify-between gap-3">
          <input v-model="segment.speaker" :aria-label="`Speaker for segment ${index + 1}`" placeholder="Speaker" :disabled="disabled" class="min-w-0 rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-bold">
          <button type="button" :disabled="!audioUrl" :aria-label="`Play from ${timestamp(segment.start)}`" class="timestamp min-h-11 shrink-0 text-xs font-medium text-indigo-600 disabled:opacity-50" @click="seek(segment.start)">{{ timestamp(segment.start) }}–{{ timestamp(segment.end) }}</button>
        </div>
        <textarea v-model="segment.text" :aria-label="`Transcript segment ${index + 1}`" :disabled="disabled" required rows="3" class="w-full resize-y rounded-lg border border-slate-200 bg-white p-3 text-base leading-[1.65]" />
      </div>
    </div>
  </div>
</template>
