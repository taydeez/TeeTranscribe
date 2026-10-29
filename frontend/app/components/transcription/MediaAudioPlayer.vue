<script setup lang="ts">
const props = defineProps<{ src: string }>()
const currentTime = defineModel<number>('currentTime', { default: 0 })
const audio = ref<HTMLAudioElement | null>(null)
const playing = ref(false)
const duration = ref(0)
const speed = ref('1')
const volume = ref(1)
const error = ref('')
const waveformError = ref('')
const loadingWaveform = ref(false)
const peaks = ref<number[]>([])
const abort = new AbortController()
function time(value: number) {
  const seconds = Math.max(0, Math.floor(value || 0))
  return [Math.floor(seconds / 3600), Math.floor(seconds / 60) % 60, seconds % 60].map(part => String(part).padStart(2, '0')).join(':')
}
function metadata() { duration.value = Number.isFinite(audio.value?.duration) ? audio.value!.duration : 0 }
function updateTime() { currentTime.value = audio.value?.currentTime ?? 0 }
async function toggle() {
  if (!audio.value) return
  if (playing.value) { audio.value.pause(); return }
  try { await audio.value.play(); error.value = '' }
  catch { error.value = 'Playback unavailable. Reload the folder to refresh the recording link.' }
}
async function seek(value: number) {
  if (!audio.value) return
  try { audio.value.currentTime = value; currentTime.value = value; await audio.value.play() }
  catch { error.value = 'Could not play this section. Try again after the recording loads.' }
}
function scrub(event: Event) { if (audio.value) audio.value.currentTime = Number((event.target as HTMLInputElement).value) }
watch(speed, value => { if (audio.value) audio.value.playbackRate = Number(value) })
watch(volume, value => { if (audio.value) audio.value.volume = value })
async function loadWaveform() {
  loadingWaveform.value = true
  waveformError.value = ''
  let context: AudioContext | undefined
  try {
    const response = await fetch(props.src, { signal: abort.signal })
    if (!response.ok || Number(response.headers.get('content-length')) > 20 * 1024 * 1024) throw new Error('unavailable')
    const bytes = await response.arrayBuffer()
    if (bytes.byteLength > 20 * 1024 * 1024) throw new Error('too large')
    context = new AudioContext()
    const buffer = await context.decodeAudioData(bytes)
    const samples = buffer.getChannelData(0)
    const bucketSize = Math.max(1, Math.floor(samples.length / 72))
    peaks.value = Array.from({ length: 72 }, (_, bucket) => {
      let peak = 0
      for (let i = bucket * bucketSize; i < Math.min(samples.length, (bucket + 1) * bucketSize); i += Math.max(1, Math.floor(bucketSize / 200))) peak = Math.max(peak, Math.abs(samples[i] ?? 0))
      return peak
    })
  } catch { waveformError.value = 'Waveform unavailable for this recording. Playback controls remain available.' }
  finally { await context?.close(); loadingWaveform.value = false }
}
onBeforeUnmount(() => { abort.abort(); audio.value?.pause() })
defineExpose({ seek })
</script>
<template>
  <div>
    <audio ref="audio" :src="src" preload="metadata" @loadedmetadata="metadata" @timeupdate="updateTime" @play="playing = true" @pause="playing = false" @ended="playing = false" @error="error = 'Recording unavailable. Reload the folder to refresh the link.'" />
    <div class="flex items-center gap-3"><button class="button-primary !size-11 !p-0" type="button" :aria-label="playing ? 'Pause recording' : 'Play recording'" @click="toggle"><UiAppIcon :name="playing ? 'pause' : 'play'" :size="18" /></button><div class="min-w-0"><p class="text-sm font-semibold">Recording</p><p class="timestamp mt-1 text-xs text-slate-500">{{ time(currentTime) }} / {{ time(duration) }}</p></div></div>
    <svg v-if="peaks.length" viewBox="0 0 288 60" role="img" aria-label="Audio waveform" class="mt-5 w-full text-cyan-500"><rect v-for="(peak, index) in peaks" :key="index" :x="index * 4" :y="30 - Math.max(2, peak * 28)" width="2" :height="Math.max(4, peak * 56)" rx="1" fill="currentColor" :opacity="index / peaks.length <= currentTime / duration ? 1 : 0.35" /></svg>
    <button v-else type="button" class="mt-4 min-h-11 text-xs font-medium text-cyan-700 disabled:opacity-50" :disabled="loadingWaveform" @click="loadWaveform">{{ loadingWaveform ? 'Reading waveform…' : 'Load audio waveform' }}</button>
    <input class="mt-3 w-full accent-cyan-600" type="range" min="0" :max="duration || 0" step="0.1" :value="currentTime" :disabled="!duration" aria-label="Seek recording" @input="scrub">
    <div class="mt-4 flex items-center justify-between gap-3"><label class="text-xs text-slate-500">Speed <select v-model="speed" class="ml-1 rounded-lg border border-slate-200 bg-white p-2 text-xs"><option v-for="rate in ['0.75', '1', '1.25', '1.5', '2']" :key="rate" :value="rate">{{ rate }}×</option></select></label><label class="flex items-center gap-2"><UiAppIcon name="volume" :size="16" /><input v-model.number="volume" class="w-16 accent-cyan-600" type="range" min="0" max="1" step="0.05" aria-label="Playback volume"></label></div>
    <p v-if="error" role="alert" class="mt-3 text-xs text-rose-600">{{ error }}</p><p v-if="waveformError" class="mt-3 text-xs text-slate-500">{{ waveformError }}</p>
  </div>
</template>
