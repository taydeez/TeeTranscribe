<script setup lang="ts">
type UploadTicket = { upload_url: string; audio_url: string; headers: Record<string, string> }
type Stage = 'idle' | 'preparing' | 'uploading' | 'submitting' | 'done'

const file = ref<File | null>(null)
const language = ref('en')
const guestSessionId = ref('')
const stage = ref<Stage>('idle')
const progress = ref(0)
const error = ref('')
const requestId = ref('')
const uploadedAudioUrl = ref('')
const audioDuration = ref<number | null>(null)
const readingDuration = ref(false)
const estimatedCredits = 50
const dragging = ref(false)
const picker = ref<HTMLInputElement | null>(null)
const busy = computed(() => readingDuration.value || ['preparing', 'uploading', 'submitting'].includes(stage.value))
const maxBytes = 100 * 1024 * 1024
const formats: Record<string, string> = {
  mp3: 'audio/mpeg', wav: 'audio/wav', m4a: 'audio/mp4', mp4: 'audio/mp4',
  ogg: 'audio/ogg', oga: 'audio/ogg', flac: 'audio/flac', webm: 'audio/webm', aac: 'audio/aac',
}
const label = computed(() => readingDuration.value ? 'Reading audio length…' : ({
  idle: 'Upload & transcribe', preparing: 'Preparing upload…', uploading: `Uploading · ${progress.value}%`,
  submitting: 'Sending for transcription…', done: 'Transcribe another file',
})[stage.value])
const durationLabel = computed(() => {
  if (audioDuration.value === null) return ''
  const totalSeconds = Math.max(1, Math.ceil(audioDuration.value))
  const parts = [
    { value: Math.floor(totalSeconds / 3600), unit: 'hour' },
    { value: Math.floor(totalSeconds % 3600 / 60), unit: 'minute' },
    { value: totalSeconds % 60, unit: 'second' },
  ]
  return parts.filter(part => part.value > 0)
    .map(part => `${part.value} ${part.unit}${part.value === 1 ? '' : 's'}`)
    .join(' ')
})
const size = computed(() => {
  if (!file.value) return ''
  return file.value.size < 1024 * 1024
    ? `${Math.max(1, Math.round(file.value.size / 1024))} KB`
    : `${(file.value.size / 1024 / 1024).toFixed(1)} MB`
})
let activeUpload: XMLHttpRequest | null = null
let cancelDurationRead: (() => void) | null = null
let disposed = false

async function ensureGuestSession(): Promise<string> {
  if (guestSessionId.value) return guestSessionId.value
  const response = await $fetch<{ id: string }>('/api/guest-session', { method: 'POST', retry: 0 })
  guestSessionId.value = response.id
  return response.id
}

function readAudioDuration(audioFile: File): Promise<number> {
  return new Promise((resolve, reject) => {
    const audio = document.createElement('audio')
    const objectUrl = URL.createObjectURL(audioFile)
    const cleanup = () => {
      clearTimeout(timeout)
      audio.onloadedmetadata = null
      audio.onerror = null
      audio.removeAttribute('src')
      audio.load()
      URL.revokeObjectURL(objectUrl)
      cancelDurationRead = null
    }
    const fail = () => {
      cleanup()
      reject(new Error('Could not read this audio file’s length. Try another file or convert it to MP3 or WAV.'))
    }
    const timeout = setTimeout(fail, 15_000)
    cancelDurationRead = fail
    audio.preload = 'metadata'
    audio.onloadedmetadata = () => {
      const seconds = audio.duration
      if (!Number.isFinite(seconds) || seconds <= 0) {
        fail()
        return
      }
      cleanup()
      resolve(seconds)
    }
    audio.onerror = fail
    audio.src = objectUrl
  })
}

async function selectFile(candidate?: File) {
  if (busy.value || !candidate) return
  error.value = ''
  requestId.value = ''
  uploadedAudioUrl.value = ''
  audioDuration.value = null
  stage.value = 'idle'
  progress.value = 0
  file.value = null
  const extension = candidate.name.split('.').pop()?.toLowerCase() ?? ''
  if (!formats[extension]) {
    error.value = 'Choose an MP3, WAV, M4A, MP4, OGG, FLAC, WebM, or AAC audio file.'
  } else if (!candidate.size || candidate.size > maxBytes) {
    error.value = 'Choose a non-empty audio file smaller than 100 MB.'
  } else {
    file.value = candidate
    readingDuration.value = true
    try {
      const seconds = await readAudioDuration(candidate)
      if (!disposed) audioDuration.value = seconds
    } catch (failure: unknown) {
      if (!disposed) error.value = failure instanceof Error ? failure.message : 'Could not read the audio length.'
    } finally {
      if (!disposed) readingDuration.value = false
    }
  }
}

function choose(event: Event) {
  const input = event.target as HTMLInputElement
  selectFile(input.files?.[0])
  input.value = ''
}

function drop(event: DragEvent) {
  dragging.value = false
  if (event.dataTransfer?.files.length !== 1) {
    if (!busy.value) error.value = 'Please select one audio file at a time.'
    return
  }
  selectFile(event.dataTransfer.files[0])
}

function upload(ticket: UploadTicket, audio: File): Promise<void> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    activeUpload = xhr
    xhr.open('PUT', ticket.upload_url)
    xhr.timeout = 15 * 60 * 1000
    Object.entries(ticket.headers).forEach(([name, value]) => xhr.setRequestHeader(name, value))
    xhr.upload.onprogress = event => {
      if (event.lengthComputable) progress.value = Math.round(event.loaded / event.total * 100)
    }
    xhr.onload = () => {
      activeUpload = null
      if (xhr.status >= 200 && xhr.status < 300) resolve()
      else reject(new Error('The audio upload was rejected. Please try again.'))
    }
    xhr.onerror = () => { activeUpload = null; reject(new Error('Could not upload the audio. Check your connection and try again.')) }
    xhr.ontimeout = () => { activeUpload = null; reject(new Error('The upload took too long. Please try again.')) }
    xhr.onabort = () => { activeUpload = null; reject(new Error('Upload cancelled.')) }
    xhr.send(audio)
  })
}

async function submit() {
  if (busy.value) return
  if (stage.value === 'done') {
    file.value = null
    stage.value = 'idle'
    requestId.value = ''
    uploadedAudioUrl.value = ''
    audioDuration.value = null
    picker.value?.click()
    return
  }
  const audio = file.value
  if (!audio) { error.value = 'Choose an audio file to get started.'; return }
  const duration = audioDuration.value
  if (duration === null) { error.value = 'The audio length must be available before submitting.'; return }
  error.value = ''
  progress.value = 0
  uploadedAudioUrl.value = ''
  try {
    const sessionId = await ensureGuestSession()
    stage.value = 'preparing'
    const extension = audio.name.split('.').pop()!.toLowerCase()
    const ticket = await $fetch<UploadTicket>('/api/uploads/presign', {
      method: 'POST', retry: 0,
      body: { filename: audio.name, content_type: formats[extension], size: audio.size },
    })
    stage.value = 'uploading'
    await upload(ticket, audio)
    uploadedAudioUrl.value = ticket.audio_url
    stage.value = 'submitting'
    const response = await $fetch<string | { request_id?: string; id?: string }>('/api/transcribe', {
      method: 'POST', timeout: 135_000, retry: 0,
      body: { audio_url: uploadedAudioUrl.value, file_name: audio.name, language_code: language.value, duration: Number(duration.toFixed(3)), guest_session_id: sessionId },
    })
    const id = typeof response === 'string' ? response : response?.request_id ?? response?.id
    if (!id) throw new Error('The server did not return a transcription request ID.')
    requestId.value = id
    stage.value = 'done'
  } catch (failure: unknown) {
    const issue = failure as { data?: { message?: string }; message?: string }
    error.value = issue.data?.message ?? issue.message ?? 'Something went wrong. Please try again.'
    stage.value = 'idle'
  }
}

onBeforeUnmount(() => {
  disposed = true
  cancelDurationRead?.()
  activeUpload?.abort()
})

onMounted(async () => {
  try {
    const response = await $fetch<{ id: string }>('/api/guest-session', { method: 'POST', retry: 0 })
    guestSessionId.value = response.id
  } catch {
    // The transcription request can still report the server error if session creation is unavailable.
  }
})
</script>

<template>
  <NuxtRouteAnnouncer />
  <div class="page">
    <header class="masthead">
      <a class="brand" href="/" aria-label="TeeTranscribe home"><span class="brand-mark" aria-hidden="true">t.</span>TeeTranscribe</a>
      <span class="header-note">A little less typing.</span>
    </header>
    <main>
      <div class="intro">
        <p class="eyebrow">YOUR WORDS, WITHIN REACH</p>
        <h1>Good audio.<br><em>Great starting point.</em></h1>
        <p class="intro-copy">A voice note, an interview, a new idea.<br>Give your recording somewhere to begin.</p>
      </div>
      <section class="upload-card" aria-labelledby="form-title">
        <div class="card-heading"><h2 id="form-title">Let’s hear it.</h2><span>01 / UPLOAD</span></div>
        <form @submit.prevent="submit">
          <input ref="picker" class="file-input" type="file" accept=".mp3,.wav,.m4a,.mp4,.ogg,.oga,.flac,.webm,.aac" :disabled="busy" aria-label="Choose an audio file" @change="choose">
          <button class="dropzone" :class="{ dragging, selected: file }" type="button" :disabled="busy" @click="picker?.click()" @dragover.prevent="dragging = !busy" @dragleave.prevent="dragging = false" @drop.prevent="drop">
            <span class="audio-symbol" aria-hidden="true"><i /><i /><i /><i /><i /></span>
            <template v-if="file"><strong class="filename">{{ file.name }}</strong><span>{{ size }} <span aria-hidden="true">·</span> Click to replace</span></template>
            <template v-else><strong>Drop your audio here</strong><span>or <u>browse files</u></span></template>
            <small>MP3, WAV, M4A & more <span aria-hidden="true">·</span> Up to 100 MB</small>
          </button>
          <div class="language-row">
            <div><label for="language">Spoken language</label><p>The language in your recording.</p></div>
            <select id="language" v-model="language" :disabled="busy">
              <option value="en">English</option><option value="es">Spanish</option><option value="fr">French</option>
              <option value="de">German</option><option value="pt">Portuguese</option><option value="it">Italian</option>
              <option value="nl">Dutch</option><option value="hi">Hindi</option>
            </select>
          </div>
          <div v-if="busy" class="progress-area" role="status" aria-live="polite">
            <span>{{ label }}</span>
            <progress v-if="stage === 'uploading'" :value="progress" max="100" aria-label="Audio upload progress" />
            <progress v-else aria-label="Waiting for server" />
          </div>
          <p v-if="error" class="error" role="alert">{{ error }}</p>
          <div v-if="uploadedAudioUrl" class="uploaded-file">
            <label for="uploaded-audio-url">Uploaded audio URL</label>
            <input id="uploaded-audio-url" :value="uploadedAudioUrl" type="url" readonly @focus="($event.target as HTMLInputElement).select()">
            <a :href="uploadedAudioUrl" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">Open uploaded audio ↗</a>
            <small>This download link is temporary and will expire.</small>
          </div>
          <div v-if="stage === 'done'" class="success" role="status">
            <strong>Uploaded. Over to transcription.</strong>
            <p>Your recording was submitted successfully.</p>
            <span>REQUEST ID</span><code>{{ requestId }}</code>
          </div>
          <p v-if="audioDuration !== null && stage !== 'done'" class="cost-notice" role="status" aria-live="polite">
            Your audio file is {{ durationLabel }} long. It would cost you <strong>{{ estimatedCredits }} credits</strong>.
          </p>
          <button class="submit" type="submit" :disabled="busy || (stage !== 'done' && (!file || audioDuration === null))"><span>{{ label }}</span><span aria-hidden="true">↗</span></button>
          <p class="footnote">Your file uploads directly from your browser.</p>
        </form>
      </section>
    </main>
    <footer><span>Made for the things worth saying.</span><span>TeeTranscribe © {{ new Date().getFullYear() }}</span></footer>
  </div>
</template>

<style>
:root { color-scheme: light; font-family: 'Segoe UI', sans-serif; color: #24372c; background: #f5f4ee; font-synthesis: none; }
* { box-sizing: border-box; }
body { margin: 0; }
button, select { font: inherit; }
button { cursor: pointer; }
button:disabled { cursor: default; }
button:focus-visible, select:focus-visible, a:focus-visible { outline: 3px solid #b27132; outline-offset: 5px; }
.page { max-width: 1280px; margin: auto; padding: 0 64px; min-height: 100dvh; display: flex; flex-direction: column; }
.masthead { height: 112px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #dcded4; }
.brand { display: flex; align-items: center; gap: 11px; text-decoration: none; color: inherit; font-weight: 650; letter-spacing: -.6px; font-size: 21px; }
.brand-mark { display: grid; place-items: center; width: 35px; height: 35px; border-radius: 50%; background: #274b3a; color: #f7f7ed; font-family: Georgia, serif; font-size: 27px; }
.header-note { font-size: 13px; color: #70776c; }
main { display: grid; grid-template-columns: 1fr 1fr; align-items: center; gap: 65px; flex: 1; padding: 72px 0; }
.eyebrow { color: #788066; letter-spacing: 2px; font-size: 11px; font-weight: 650; margin: 0 0 27px; }
h1 { font: normal clamp(42px, 4.5vw, 63px)/1.1 Georgia, 'Times New Roman', serif; letter-spacing: -2.6px; margin: 0; }
h1 em { font-weight: normal; color: #637956; }
.intro-copy { font-size: 16px; line-height: 1.8; color: #737b6d; margin: 27px 0 0; }
.upload-card { background: #fffefa; padding: 30px; border: 1px solid #e0e2d7; border-radius: 18px; box-shadow: 0 12px 35px #38462a06; }
.card-heading { display: flex; align-items: center; justify-content: space-between; margin-bottom: 23px; }
h2 { margin: 0; font-size: 21px; font-weight: 600; letter-spacing: -.7px; }
.card-heading > span { font-size: 9px; letter-spacing: 1.5px; color: #828878; }
.file-input { display: none; }
.dropzone { width: 100%; border: 1px dashed #bbc5af; border-radius: 10px; padding: 32px 18px 25px; background: #f7f8f2; color: #344831; display: flex; flex-direction: column; align-items: center; transition: background .2s, border-color .2s; }
.dropzone:hover:not(:disabled), .dropzone.dragging { background: #edf1e5; border-color: #527846; }
.dropzone strong { font-size: 15px; margin-top: 17px; font-weight: 600; }
.dropzone > span:not(.audio-symbol) { font-size: 13px; color: #7a8371; margin-top: 6px; }
.dropzone u { text-underline-offset: 3px; color: #43603d; }
.dropzone small { font-size: 10px; color: #818977; margin-top: 22px; }
.audio-symbol { display: flex; gap: 4px; height: 38px; align-items: center; }
.audio-symbol i { width: 4px; height: 14px; border-radius: 4px; background: #788f61; }
.audio-symbol i:nth-child(2), .audio-symbol i:nth-child(4) { height: 26px; }
.audio-symbol i:nth-child(3) { height: 38px; }
.filename { max-width: 100%; overflow-wrap: anywhere; }
.uploaded-file { display: grid; gap: 9px; margin-bottom: 20px; }
.uploaded-file label { font-size: 13px; font-weight: 600; }
.uploaded-file input { min-width: 0; width: 100%; padding: 11px; border: 1px solid #dedfd5; border-radius: 7px; background: #f7f8f2; color: #344831; font: inherit; font-size: 12px; }
.uploaded-file input:focus-visible { outline: 3px solid #b27132; outline-offset: 3px; }
.uploaded-file a { color: #43603d; font-size: 12px; text-underline-offset: 3px; }
.uploaded-file small { color: #737b6d; font-size: 11px; }
.language-row { display: flex; align-items: center; justify-content: space-between; gap: 15px; padding: 25px 0; }
.language-row label { font-size: 13px; font-weight: 600; }
.language-row p { font-size: 11px; color: #838778; margin: 5px 0 0; }
select { border: 1px solid #dedfd5; border-radius: 7px; padding: 10px 25px 10px 12px; color: #344831; background: #fffefa; font-size: 12px; max-width: 45%; }
.submit { width: 100%; display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border: 0; border-radius: 8px; background: #284c39; color: #fffef4; font-weight: 500; font-size: 14px; transition: background .2s; }
.submit:hover:not(:disabled) { background: #3d644a; }
.submit:disabled { opacity: .5; }
.submit > span:last-child { font-size: 21px; }
.footnote { text-align: center; color: #89907e; font-size: 10px; margin: 16px 0 0; }
.cost-notice { margin: 0 0 16px; padding: 14px; border: 1px solid #dce5d1; border-radius: 8px; background: #f0f4e9; color: #43603d; font-size: 13px; line-height: 1.6; }
.error { background: #fff1e9; border: 1px solid #edcdb9; border-radius: 7px; color: #964521; padding: 12px; font-size: 13px; line-height: 1.5; }
.success { background: #edf3e7; border-radius: 8px; padding: 15px; margin-bottom: 20px; font-size: 13px; }
.success p { margin: 6px 0 16px; color: #5e7356; }
.success > span { display: block; font-size: 9px; letter-spacing: 1px; margin-bottom: 5px; }
.success code { font-size: 11px; overflow-wrap: anywhere; }
.progress-area { font-size: 12px; color: #536b43; margin-bottom: 20px; }
progress { display: block; width: 100%; height: 5px; margin-top: 10px; accent-color: #527846; }
footer { display: flex; justify-content: space-between; border-top: 1px solid #dcded4; padding: 25px 0; font-size: 11px; color: #8a8f80; }
@media (max-width: 800px) { .page { padding: 0 25px; } .masthead { height: 83px; } main { grid-template-columns: 1fr; gap: 35px; padding: 45px 0; max-width: 490px; width: 100%; align-self: center; } h1 { font-size: 45px; } .intro-copy { font-size: 14px; margin-top: 18px; } .eyebrow { margin-bottom: 18px; } }
@media (max-width: 420px) { .header-note { display: none; } .upload-card { padding: 22px; } .page { padding: 0 18px; } h1 { font-size: 40px; } footer { gap: 16px; font-size: 10px; } }
@media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
</style>
