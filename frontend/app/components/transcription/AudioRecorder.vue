<script setup lang="ts">
const emit = defineEmits<{ recorded: [file: File, duration: number]; cleared: [] }>()

const recording = ref(false)
const elapsed = ref(0)
const previewUrl = ref('')
const recordedFile = ref<File | null>(null)
const error = ref('')

let recorder: MediaRecorder | null = null
let stream: MediaStream | null = null
let chunks: Blob[] = []
let timer: ReturnType<typeof setInterval> | null = null
let startedAt = 0

const timeLabel = computed(() => `${String(Math.floor(elapsed.value / 60)).padStart(2, '0')}:${String(elapsed.value % 60).padStart(2, '0')}`)

function stopResources() {
  if (timer) clearInterval(timer)
  timer = null
  stream?.getTracks().forEach(track => track.stop())
  stream = null
}

function clearPreview() {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = ''
}

function mimeType(): string {
  return ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus', 'audio/webm']
    .find(type => MediaRecorder.isTypeSupported(type)) ?? ''
}

function normalizedType(type: string): { extension: string; contentType: string } {
  if (type.includes('mp4')) return { extension: 'm4a', contentType: 'audio/mp4' }
  if (type.includes('ogg')) return { extension: 'ogg', contentType: 'audio/ogg' }
  return { extension: 'webm', contentType: 'audio/webm' }
}

async function start() {
  if (recording.value) return
  error.value = ''
  clearPreview()
  recordedFile.value = null
  elapsed.value = 0
  chunks = []
  emit('cleared')

  if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
    error.value = 'Audio recording is not supported by this browser.'
    return
  }

  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true })
    const preferred = mimeType()
    recorder = preferred ? new MediaRecorder(stream, { mimeType: preferred }) : new MediaRecorder(stream)
    recorder.ondataavailable = event => { if (event.data.size) chunks.push(event.data) }
    recorder.onerror = () => {
      error.value = 'The browser could not finish the recording. Please try again.'
      recording.value = false
      stopResources()
    }
    recorder.onstop = () => {
      const actualType = recorder?.mimeType || preferred || 'audio/webm'
      const duration = Math.max(0.1, (performance.now() - startedAt) / 1000)
      const format = normalizedType(actualType)
      const blob = new Blob(chunks, { type: actualType })
      chunks = []
      recorder = null
      if (!blob.size) {
        error.value = 'No audio was captured. Please try recording again.'
        return
      }
      const file = new File([blob], `recording-${new Date().toISOString().replace(/[:.]/g, '-')}.${format.extension}`, { type: format.contentType })
      recordedFile.value = file
      previewUrl.value = URL.createObjectURL(file)
      emit('recorded', file, duration)
    }
    recorder.start(1000)
    startedAt = performance.now()
    recording.value = true
    timer = setInterval(() => { elapsed.value += 1 }, 1000)
  } catch (failure: unknown) {
    stopResources()
    error.value = (failure as { name?: string }).name === 'NotAllowedError'
      ? 'Microphone access was denied. Allow it in your browser and try again.'
      : 'The microphone could not be started. Check that it is connected and available.'
  }
}

function stop() {
  if (!recorder || recorder.state === 'inactive') return
  recording.value = false
  recorder.stop()
  stopResources()
}

function clear() {
  clearPreview()
  recordedFile.value = null
  elapsed.value = 0
  error.value = ''
  emit('cleared')
}

onBeforeUnmount(() => {
  if (recorder?.state !== 'inactive') recorder?.stop()
  stopResources()
  clearPreview()
})
</script>

<template>
  <div class="rounded-2xl border border-indigo-100 bg-gradient-to-br from-indigo-50 to-cyan-50/60 p-5 text-center">
    <template v-if="recording">
      <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-rose-100"><span class="size-4 animate-pulse rounded-full bg-rose-500" /></span>
      <strong class="mt-4 block text-base text-slate-900">Recording {{ timeLabel }}</strong>
      <p class="mt-1 text-xs text-slate-500">Keep this tab open while you record.</p>
      <button class="mt-5 min-h-11 rounded-xl bg-rose-600 px-6 text-sm font-bold text-white" type="button" @click="stop">Stop recording</button>
    </template>
    <template v-else-if="recordedFile && previewUrl">
      <strong class="block truncate text-sm text-slate-900">{{ recordedFile.name }}</strong>
      <audio class="mt-4 w-full" :src="previewUrl" controls preload="metadata" />
      <div class="mt-4 flex justify-center gap-3">
        <a class="min-h-10 rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-600" :href="previewUrl" :download="recordedFile.name">Save recording</a>
        <button class="min-h-10 rounded-xl bg-indigo-600 px-4 text-xs font-bold text-white" type="button" @click="start">Record again</button>
        <button class="min-h-10 rounded-xl border border-slate-300 bg-white px-4 text-xs font-bold text-slate-600" type="button" @click="clear">Discard</button>
      </div>
    </template>
    <template v-else>
      <span class="mx-auto grid size-14 place-items-center rounded-full bg-indigo-600 text-2xl text-white" aria-hidden="true">●</span>
      <strong class="mt-4 block text-base text-slate-900">Record from your microphone</strong>
      <p class="mt-1 text-xs text-slate-500">You can listen back before uploading.</p>
      <button class="mt-5 min-h-11 rounded-xl bg-indigo-600 px-6 text-sm font-bold text-white" type="button" @click="start">Start recording</button>
    </template>
    <p v-if="error" class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700" role="alert">{{ error }}</p>
  </div>
</template>
