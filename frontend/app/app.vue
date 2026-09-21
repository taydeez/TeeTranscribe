<script setup lang="ts">
type UploadTicket = { upload_url: string; audio_url: string; headers: Record<string, string> }
type Stage = 'idle' | 'preparing' | 'uploading' | 'submitting' | 'done'
type AudioSource = 'file' | 'url'
type AuthUser = { id: number; name: string; email: string }
type AuthResponse = { token?: string; requires_two_factor?: boolean; email?: string; user?: AuthUser }
type FolderOption = { id: string; name: string }
type FolderOptionPage = { data: FolderOption[]; meta: { currentPage: number; lastPage: number } }

const file = ref<File | null>(null)
const audioSource = ref<AudioSource>('file')
const pastedAudioUrl = ref('')
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
const authMode = ref<'login' | 'register' | 'verify'>('login')
const authName = ref('')
const authEmail = ref('')
const authPassword = ref('')
const authPasswordConfirmation = ref('')
const authCode = ref('')
const authToken = ref('')
const authUser = ref<AuthResponse['user']>()
const authError = ref('')
const authBusy = ref(false)
const authModalOpen = ref(false)
const uploadFolders = ref<FolderOption[]>([])
const selectedFolderId = ref('')
const uploadFoldersLoading = ref(false)
const route = useRoute()
const busy = computed(() => readingDuration.value || ['preparing', 'uploading', 'submitting'].includes(stage.value))
const canSubmit = computed(() => audioSource.value === 'url'
  ? pastedAudioUrl.value.trim().length > 0
  : Boolean(file.value && audioDuration.value !== null))
const maxBytes = 100 * 1024 * 1024
const formats: Record<string, string> = {
  mp3: 'audio/mpeg', wav: 'audio/wav', m4a: 'audio/mp4', mp4: 'audio/mp4',
  ogg: 'audio/ogg', oga: 'audio/ogg', flac: 'audio/flac', webm: 'audio/webm', aac: 'audio/aac',
}
const languages = [
  { name: 'Afrikaans', codes: ['af', 'af-ZA'] },
  { name: 'Arabic', codes: ['ar', 'ar-AE', 'ar-SA', 'ar-QA', 'ar-KW', 'ar-SY', 'ar-LB', 'ar-PS', 'ar-JO', 'ar-EG', 'ar-SD', 'ar-TD', 'ar-MA', 'ar-DZ', 'ar-TN', 'ar-IQ', 'ar-IR'] },
  { name: 'Armenian', codes: ['hy'] },
  { name: 'Assamese', codes: ['as', 'as-IN'] },
  { name: 'Belarusian', codes: ['be'] },
  { name: 'Bengali', codes: ['bn'] },
  { name: 'Bosnian', codes: ['bs'] },
  { name: 'Bulgarian', codes: ['bg'] },
  { name: 'Catalan', codes: ['ca'] },
  { name: 'Chinese (Cantonese, Traditional)', codes: ['zh-HK'] },
  { name: 'Chinese (Mandarin, Simplified)', codes: ['zh', 'zh-CN', 'zh-Hans'] },
  { name: 'Chinese (Mandarin, Traditional)', codes: ['zh-TW', 'zh-Hant'] },
  { name: 'Croatian', codes: ['hr'] },
  { name: 'Czech', codes: ['cs', 'cs-CZ'] },
  { name: 'Danish', codes: ['da', 'da-DK'] },
  { name: 'Dutch', codes: ['nl'] },
  { name: 'English', codes: ['en', 'en-US', 'en-AU', 'en-GB', 'en-IN', 'en-NZ'] },
  { name: 'Estonian', codes: ['et'] },
  { name: 'Finnish', codes: ['fi'] },
  { name: 'Flemish', codes: ['nl-BE'] },
  { name: 'French', codes: ['fr', 'fr-CA'] },
  { name: 'Georgian', codes: ['ka', 'ka-GE'] },
  { name: 'German', codes: ['de'] },
  { name: 'German (Switzerland)', codes: ['de-CH'] },
  { name: 'Greek', codes: ['el'] },
  { name: 'Gujarati', codes: ['gu', 'gu-IN'] },
  { name: 'Hebrew', codes: ['he'] },
  { name: 'Hindi', codes: ['hi'] },
  { name: 'Hungarian', codes: ['hu'] },
  { name: 'Indonesian', codes: ['id'] },
  { name: 'Italian', codes: ['it'] },
  { name: 'Japanese', codes: ['ja'] },
  { name: 'Kannada', codes: ['kn'] },
  { name: 'Kazakh', codes: ['kk', 'kk-KZ'] },
  { name: 'Korean', codes: ['ko', 'ko-KR'] },
  { name: 'Latvian', codes: ['lv'] },
  { name: 'Lithuanian', codes: ['lt'] },
  { name: 'Macedonian', codes: ['mk'] },
  { name: 'Malay', codes: ['ms'] },
  { name: 'Marathi', codes: ['mr'] },
  { name: 'Mongolian', codes: ['mn'] },
  { name: 'Nepali', codes: ['ne'] },
  { name: 'Norwegian', codes: ['no'] },
  { name: 'Pashto', codes: ['ps', 'ps-AF'] },
  { name: 'Persian', codes: ['fa'] },
  { name: 'Polish', codes: ['pl'] },
  { name: 'Portuguese', codes: ['pt', 'pt-BR', 'pt-PT'] },
  { name: 'Punjabi', codes: ['pa', 'pa-IN'] },
  { name: 'Romanian', codes: ['ro'] },
  { name: 'Russian', codes: ['ru'] },
  { name: 'Serbian', codes: ['sr'] },
  { name: 'Slovak', codes: ['sk'] },
  { name: 'Slovenian', codes: ['sl'] },
  { name: 'Spanish', codes: ['es', 'es-419'] },
  { name: 'Swedish', codes: ['sv', 'sv-SE'] },
  { name: 'Tagalog', codes: ['tl'] },
  { name: 'Tamil', codes: ['ta'] },
  { name: 'Telugu', codes: ['te'] },
  { name: 'Thai', codes: ['th', 'th-TH'] },
  { name: 'Turkish', codes: ['tr', 'tr-TR'] },
  { name: 'Ukrainian', codes: ['uk'] },
  { name: 'Urdu', codes: ['ur'] },
  { name: 'Vietnamese', codes: ['vi'] },
]
const label = computed(() => readingDuration.value ? 'Reading audio length…' : ({
  idle: audioSource.value === 'url' ? 'Transcribe from URL' : 'Upload & transcribe', preparing: 'Preparing upload…', uploading: `Uploading · ${progress.value}%`,
  submitting: 'Sending for transcription…', done: `Transcribe another ${audioSource.value === 'url' ? 'URL' : 'file'}`,
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

async function submitAuth() {
  authBusy.value = true; authError.value = ''
  try {
    const endpoint = authMode.value === 'register' ? '/api/auth/register' : authMode.value === 'verify' ? '/api/auth/admin-verify' : '/api/auth/login'
    const body = authMode.value === 'register'
      ? { name: authName.value, email: authEmail.value, password: authPassword.value, password_confirmation: authPasswordConfirmation.value }
      : authMode.value === 'verify' ? { email: authEmail.value, code: authCode.value } : { email: authEmail.value, password: authPassword.value }
    const response = await $fetch<AuthResponse>(endpoint, { method: 'POST', body })
    if (response.requires_two_factor) { authMode.value = 'verify'; authEmail.value = response.email ?? authEmail.value; return }
    if (response.token) {
      authToken.value = response.token
      localStorage.setItem('auth_token', response.token)
      authUser.value = response.user ?? await loadAuthUser(response.token)
      await loadUploadFolders()
      authModalOpen.value = false
      await navigateTo('/dashboard')
    }
  } catch (failure: any) { authError.value = failure.data?.message ?? 'Authentication failed.' }
  finally { authBusy.value = false }
}

async function loadAuthUser(token: string): Promise<AuthUser> {
  return await $fetch<AuthUser>('/api/auth/user', {
    headers: { Authorization: `Bearer ${token}` },
    retry: 0,
  })
}

async function loadUploadFolders() {
  if (!authToken.value || uploadFoldersLoading.value) return
  uploadFoldersLoading.value = true
  try {
    const firstPage = await $fetch<FolderOptionPage>('/api/folders', {
      headers: { Authorization: `Bearer ${authToken.value}` },
      query: { page: 1, per_page: 50, sort: 'name', direction: 'asc' },
      retry: 0,
    })
    const pages = [firstPage]
    for (let page = 2; page <= firstPage.meta.lastPage; page += 1) {
      pages.push(await $fetch<FolderOptionPage>('/api/folders', {
        headers: { Authorization: `Bearer ${authToken.value}` },
        query: { page, per_page: 50, sort: 'name', direction: 'asc' },
        retry: 0,
      }))
    }
    uploadFolders.value = pages.flatMap(result => result.data)
  } catch {
    uploadFolders.value = []
  } finally {
    uploadFoldersLoading.value = false
  }
}

function openAuth(mode: 'login' | 'register') {
  authMode.value = mode
  authError.value = ''
  authModalOpen.value = true
}

function closeAuth() {
  if (!authBusy.value) authModalOpen.value = false
}

async function signOut() {
  const token = authToken.value
  if (token) {
    try { await $fetch('/api/auth/logout', { method: 'POST', headers: { Authorization: `Bearer ${token}` } }) } catch { /* Clear local access even if the API is unavailable. */ }
  }
  authToken.value = ''
  authUser.value = undefined
  uploadFolders.value = []
  selectedFolderId.value = ''
  localStorage.removeItem('auth_token')
}

async function dashboardLogout() {
  await signOut()
  await navigateTo('/')
}

async function startNewTranscription() {
  if (route.path !== '/dashboard') await navigateTo('/dashboard')
}

function handleEscape(event: KeyboardEvent) {
  if (event.key === 'Escape') closeAuth()
}

function chooseAudioSource(source: AudioSource) {
  if (busy.value || audioSource.value === source) return
  audioSource.value = source
  error.value = ''
  requestId.value = ''
  uploadedAudioUrl.value = ''
  stage.value = 'idle'
  progress.value = 0
}

function validatedPastedAudioUrl(): string | null {
  const value = pastedAudioUrl.value.trim()
  try {
    const url = new URL(value)
    return ['http:', 'https:'].includes(url.protocol) ? url.toString() : null
  } catch {
    return null
  }
}

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
    pastedAudioUrl.value = ''
    stage.value = 'idle'
    requestId.value = ''
    uploadedAudioUrl.value = ''
    audioDuration.value = null
    if (audioSource.value === 'file') picker.value?.click()
    return
  }
  const audio = file.value
  const duration = audioDuration.value
  const remoteAudioUrl = audioSource.value === 'url' ? validatedPastedAudioUrl() : null
  if (audioSource.value === 'url' && remoteAudioUrl === null) { error.value = 'Enter a valid HTTP or HTTPS audio URL.'; return }
  if (audioSource.value === 'file' && !audio) { error.value = 'Choose an audio file to get started.'; return }
  if (audioSource.value === 'file' && duration === null) { error.value = 'The audio length must be available before submitting.'; return }
  error.value = ''
  progress.value = 0
  uploadedAudioUrl.value = ''
  try {
    let identity: { user_id: number } | { guest_session_id: string }
    if (authToken.value) {
      authUser.value ??= await loadAuthUser(authToken.value)
      identity = { user_id: authUser.value.id }
    } else {
      identity = { guest_session_id: await ensureGuestSession() }
    }
    if (audioSource.value === 'file' && audio) {
      stage.value = 'preparing'
      const extension = audio.name.split('.').pop()!.toLowerCase()
      const ticket = await $fetch<UploadTicket>('/api/uploads/presign', {
        method: 'POST', retry: 0,
        body: { filename: audio.name, content_type: formats[extension], size: audio.size },
      })
      stage.value = 'uploading'
      await upload(ticket, audio)
      uploadedAudioUrl.value = ticket.audio_url
    } else {
      uploadedAudioUrl.value = remoteAudioUrl!
    }
    stage.value = 'submitting'
    const response = await $fetch<string | { request_id?: string; id?: string }>('/api/transcribe', {
      method: 'POST', timeout: 135_000, retry: 0,
      headers: authToken.value ? { Authorization: `Bearer ${authToken.value}` } : undefined,
      body: {
        audio_url: uploadedAudioUrl.value,
        language_code: language.value,
        ...(authToken.value && selectedFolderId.value ? { folder_id: selectedFolderId.value } : {}),
        ...(audioSource.value === 'file' && audio && duration !== null ? { file_name: audio.name, duration: Number(duration.toFixed(3)) } : {}),
        ...identity,
      },
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
  window.removeEventListener('keydown', handleEscape)
})

onMounted(async () => {
  window.addEventListener('keydown', handleEscape)
  const hashToken = new URLSearchParams(window.location.hash.slice(1)).get('token')
  if (hashToken) { localStorage.setItem('auth_token', hashToken); authToken.value = hashToken; history.replaceState(null, '', window.location.pathname); await navigateTo('/dashboard') }
  authToken.value = localStorage.getItem('auth_token') ?? ''
  if (route.path === '/dashboard' && !authToken.value) await navigateTo('/')
  try {
    if (authToken.value) {
      authUser.value = await loadAuthUser(authToken.value)
      await loadUploadFolders()
    } else {
      const response = await $fetch<{ id: string }>('/api/guest-session', { method: 'POST', retry: 0 })
      guestSessionId.value = response.id
    }
  } catch {
    // The transcription request can still report the server error if session creation is unavailable.
  }
})
</script>

<template>
  <NuxtRouteAnnouncer />
  <DashboardView v-if="$route.path === '/dashboard'" @logout="dashboardLogout" @new-transcription="startNewTranscription" @folder-created="loadUploadFolders">
    <template #transcription-form>
      <section class="upload-card dashboard-upload-card" aria-labelledby="dashboard-form-title">
        <div class="card-heading"><h2 id="dashboard-form-title">Start a new transcription.</h2><span>UPLOAD AUDIO</span></div>
        <form @submit.prevent="submit">
          <div class="mb-4 grid grid-cols-2 rounded-xl bg-slate-100 p-1" aria-label="Audio source">
            <button class="min-h-10 rounded-lg px-3 text-xs font-bold transition" :class="audioSource === 'file' ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500 hover:text-slate-800'" type="button" :disabled="busy" @click="chooseAudioSource('file')">Upload file</button>
            <button class="min-h-10 rounded-lg px-3 text-xs font-bold transition" :class="audioSource === 'url' ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500 hover:text-slate-800'" type="button" :disabled="busy" @click="chooseAudioSource('url')">Paste audio URL</button>
          </div>
          <input ref="picker" class="file-input" type="file" accept=".mp3,.wav,.m4a,.mp4,.ogg,.oga,.flac,.webm,.aac" :disabled="busy" aria-label="Choose an audio file" @change="choose">
          <button v-if="audioSource === 'file'" class="dropzone" :class="{ dragging, selected: file }" type="button" :disabled="busy" @click="picker?.click()" @dragover.prevent="dragging = !busy" @dragleave.prevent="dragging = false" @drop.prevent="drop">
            <span class="audio-symbol" aria-hidden="true"><i /><i /><i /><i /><i /></span>
            <template v-if="file"><strong class="filename">{{ file.name }}</strong><span>{{ size }} <span aria-hidden="true">·</span> Click to replace</span></template>
            <template v-else><strong>Drop your audio here</strong><span>or <u>browse files</u></span></template>
            <small>MP3, WAV, M4A & more <span aria-hidden="true">·</span> Up to 100 MB</small>
          </button>
          <div v-else class="rounded-2xl border border-indigo-100 bg-indigo-50/50 p-5">
            <label class="block text-xs font-bold text-slate-700" for="dashboard-audio-url">Public audio URL</label>
            <p class="mt-1 text-[11px] text-slate-500">Paste a direct HTTP or HTTPS link that the transcription provider can access.</p>
            <input id="dashboard-audio-url" v-model.trim="pastedAudioUrl" class="mt-4 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10" type="url" inputmode="url" autocomplete="url" placeholder="https://example.com/recording.mp3" :disabled="busy" required>
          </div>
          <div class="language-row">
            <div><label for="dashboard-language">Spoken language</label><p>The language in your recording.</p></div>
            <select id="dashboard-language" v-model="language" :disabled="busy">
              <optgroup v-for="item in languages" :key="item.name" :label="item.name">
                <option v-for="code in item.codes" :key="code" :value="code">{{ item.name }} ({{ code }})</option>
              </optgroup>
            </select>
          </div>
          <div class="language-row border-t border-slate-100">
            <div><label for="dashboard-folder">Folder</label><p>Choose where this transcription should be saved.</p></div>
            <select id="dashboard-folder" v-model="selectedFolderId" :disabled="busy">
              <option value="">{{ uploadFoldersLoading ? 'Loading folders…' : 'Today’s folder (automatic)' }}</option>
              <option v-for="folder in uploadFolders" :key="folder.id" :value="folder.id">{{ folder.name }}</option>
            </select>
          </div>
          <div v-if="busy" class="progress-area" role="status" aria-live="polite">
            <span>{{ label }}</span>
            <progress v-if="stage === 'uploading'" :value="progress" max="100" aria-label="Audio upload progress" />
            <progress v-else aria-label="Waiting for server" />
          </div>
          <p v-if="error" class="error" role="alert">{{ error }}</p>
          <div v-if="uploadedAudioUrl" class="uploaded-file">
            <label for="dashboard-uploaded-audio-url">Audio URL</label>
            <input id="dashboard-uploaded-audio-url" :value="uploadedAudioUrl" type="url" readonly @focus="($event.target as HTMLInputElement).select()">
            <a :href="uploadedAudioUrl" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">Open uploaded audio ↗</a>
            <small v-if="audioSource === 'file'">This download link is temporary and will expire.</small>
          </div>
          <div v-if="stage === 'done'" class="success" role="status">
            <strong>Uploaded. Over to transcription.</strong>
            <p>Your recording was submitted successfully.</p>
            <span>REQUEST ID</span><code>{{ requestId }}</code>
          </div>
          <p v-if="audioSource === 'file' && audioDuration !== null && stage !== 'done'" class="cost-notice" role="status" aria-live="polite">
            Your audio file is {{ durationLabel }} long. It would cost you <strong>{{ estimatedCredits }} credits</strong>.
          </p>
          <button class="submit" type="submit" :disabled="busy || (stage !== 'done' && !canSubmit)"><span>{{ label }}</span><span aria-hidden="true">↗</span></button>
          <p class="footnote">{{ audioSource === 'file' ? 'Your file uploads directly from your browser.' : 'The URL is sent directly for transcription.' }}</p>
        </form>
      </section>
    </template>
  </DashboardView>
  <template v-else>
  <div class="page">
    <header class="masthead">
      <a class="brand" href="/" aria-label="TeeTranscribe home"><span class="brand-mark" aria-hidden="true">t.</span>TeeTranscribe</a>
      <div class="flex items-center gap-2 sm:gap-3">
        <template v-if="authToken">
          <span class="hidden text-xs text-slate-500 sm:inline">{{ authUser?.name || 'Signed in' }}</span>
          <a class="rounded-lg px-4 py-2.5 text-xs font-bold text-indigo-700 transition hover:bg-indigo-50" href="/dashboard">Dashboard</a>
          <button class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 transition hover:border-indigo-300 hover:text-indigo-700" type="button" @click="signOut">Sign out</button>
        </template>
        <template v-else>
          <button class="rounded-lg px-4 py-2.5 text-xs font-bold text-slate-700 transition hover:bg-white/80" type="button" @click="openAuth('login')">Log in</button>
          <button class="rounded-lg bg-gradient-to-r from-indigo-600 to-violet-600 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-indigo-500/20 transition hover:-translate-y-0.5" type="button" @click="openAuth('register')">Create account</button>
        </template>
      </div>
    </header>
    <main class="home-main">
      <div class="intro">
        <p class="eyebrow">YOUR WORDS, WITHIN REACH</p>
        <h1>Good audio.<br><em>Great starting point.</em></h1>
        <p class="intro-copy">A voice note, an interview, a new idea.<br>Give your recording somewhere to begin.</p>
      </div>
      <section class="upload-card" aria-labelledby="form-title">
        <div class="card-heading"><h2 id="form-title">Let’s hear it.</h2><span>01 / UPLOAD</span></div>
        <form @submit.prevent="submit">
          <div class="mb-4 grid grid-cols-2 rounded-xl bg-slate-100 p-1" aria-label="Audio source">
            <button class="min-h-10 rounded-lg px-3 text-xs font-bold transition" :class="audioSource === 'file' ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500 hover:text-slate-800'" type="button" :disabled="busy" @click="chooseAudioSource('file')">Upload file</button>
            <button class="min-h-10 rounded-lg px-3 text-xs font-bold transition" :class="audioSource === 'url' ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500 hover:text-slate-800'" type="button" :disabled="busy" @click="chooseAudioSource('url')">Paste audio URL</button>
          </div>
          <input ref="picker" class="file-input" type="file" accept=".mp3,.wav,.m4a,.mp4,.ogg,.oga,.flac,.webm,.aac" :disabled="busy" aria-label="Choose an audio file" @change="choose">
          <button v-if="audioSource === 'file'" class="dropzone" :class="{ dragging, selected: file }" type="button" :disabled="busy" @click="picker?.click()" @dragover.prevent="dragging = !busy" @dragleave.prevent="dragging = false" @drop.prevent="drop">
            <span class="audio-symbol" aria-hidden="true"><i /><i /><i /><i /><i /></span>
            <template v-if="file"><strong class="filename">{{ file.name }}</strong><span>{{ size }} <span aria-hidden="true">·</span> Click to replace</span></template>
            <template v-else><strong>Drop your audio here</strong><span>or <u>browse files</u></span></template>
            <small>MP3, WAV, M4A & more <span aria-hidden="true">·</span> Up to 100 MB</small>
          </button>
          <div v-else class="rounded-2xl border border-indigo-100 bg-indigo-50/50 p-5">
            <label class="block text-xs font-bold text-slate-700" for="audio-url">Public audio URL</label>
            <p class="mt-1 text-[11px] text-slate-500">Paste a direct HTTP or HTTPS link that the transcription provider can access.</p>
            <input id="audio-url" v-model.trim="pastedAudioUrl" class="mt-4 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10" type="url" inputmode="url" autocomplete="url" placeholder="https://example.com/recording.mp3" :disabled="busy" required>
          </div>
          <div class="language-row">
            <div><label for="language">Spoken language</label><p>The language in your recording.</p></div>
            <select id="language" v-model="language" :disabled="busy">
              <optgroup v-for="item in languages" :key="item.name" :label="item.name">
                <option v-for="code in item.codes" :key="code" :value="code">{{ item.name }} ({{ code }})</option>
              </optgroup>
            </select>
          </div>
          <div v-if="busy" class="progress-area" role="status" aria-live="polite">
            <span>{{ label }}</span>
            <progress v-if="stage === 'uploading'" :value="progress" max="100" aria-label="Audio upload progress" />
            <progress v-else aria-label="Waiting for server" />
          </div>
          <p v-if="error" class="error" role="alert">{{ error }}</p>
          <div v-if="uploadedAudioUrl" class="uploaded-file">
            <label for="uploaded-audio-url">Audio URL</label>
            <input id="uploaded-audio-url" :value="uploadedAudioUrl" type="url" readonly @focus="($event.target as HTMLInputElement).select()">
            <a :href="uploadedAudioUrl" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">Open uploaded audio ↗</a>
            <small v-if="audioSource === 'file'">This download link is temporary and will expire.</small>
          </div>
          <div v-if="stage === 'done'" class="success" role="status">
            <strong>Uploaded. Over to transcription.</strong>
            <p>Your recording was submitted successfully.</p>
            <span>REQUEST ID</span><code>{{ requestId }}</code>
          </div>
          <p v-if="audioSource === 'file' && audioDuration !== null && stage !== 'done'" class="cost-notice" role="status" aria-live="polite">
            Your audio file is {{ durationLabel }} long. It would cost you <strong>{{ estimatedCredits }} credits</strong>.
          </p>
          <button class="submit" type="submit" :disabled="busy || (stage !== 'done' && !canSubmit)"><span>{{ label }}</span><span aria-hidden="true">↗</span></button>
          <p class="footnote">{{ audioSource === 'file' ? 'Your file uploads directly from your browser.' : 'The URL is sent directly for transcription.' }}</p>
        </form>
      </section>
    </main>
    <Teleport to="body">
      <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0" leave-active-class="transition duration-150 ease-in" leave-to-class="opacity-0">
        <div v-if="authModalOpen" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/60 p-4 backdrop-blur-sm" role="presentation" @mousedown.self="closeAuth">
          <section class="relative w-full max-w-md overflow-hidden rounded-3xl border border-white/70 bg-white p-7 shadow-2xl sm:p-9" role="dialog" aria-modal="true" aria-labelledby="auth-title">
            <div class="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-indigo-500 via-violet-500 to-cyan-400" />
            <button class="absolute right-5 top-5 grid size-10 place-items-center rounded-full border border-slate-200 text-lg text-slate-500 transition hover:bg-slate-50 hover:text-slate-900" type="button" aria-label="Close authentication dialog" @click="closeAuth">×</button>
            <span class="mb-5 grid size-11 place-items-center rounded-xl bg-indigo-50 text-lg font-extrabold text-indigo-600">t.</span>
            <p class="mb-3 text-[10px] font-extrabold tracking-[.2em] text-indigo-600">TEETRANSCRIBE ACCOUNT</p>
            <h2 id="auth-title" class="text-3xl font-extrabold tracking-[-.04em] text-slate-900">{{ authMode === 'register' ? 'Create your account' : authMode === 'verify' ? 'Check your email' : 'Welcome back' }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">{{ authMode === 'verify' ? 'Enter the six-digit administrator code we sent you.' : 'Save your recordings and keep every transcript within reach.' }}</p>
            <form class="mt-7 grid gap-4" @submit.prevent="submitAuth">
              <input v-if="authMode === 'register'" v-model="authName" class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10" required placeholder="your name or Organization name." autocomplete="name">
              <input v-model="authEmail" class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10" required type="email" placeholder="Email address" autocomplete="email">
              <input v-if="authMode !== 'verify'" v-model="authPassword" class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10" required type="password" placeholder="Password" :autocomplete="authMode === 'register' ? 'new-password' : 'current-password'">
              <input v-if="authMode === 'register'" v-model="authPasswordConfirmation" class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10" required type="password" placeholder="Confirm password" autocomplete="new-password">
              <input v-if="authMode === 'verify'" v-model="authCode" class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-center text-xl tracking-[.35em] outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10" required inputmode="numeric" maxlength="6" placeholder="000000">
              <p v-if="authError" class="error" role="alert">{{ authError }}</p>
              <button class="rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-5 py-3.5 text-sm font-bold text-white shadow-lg shadow-indigo-500/20 transition hover:-translate-y-0.5 disabled:opacity-50" :disabled="authBusy">{{ authBusy ? 'Please wait…' : authMode === 'register' ? 'Create account' : authMode === 'verify' ? 'Verify code' : 'Log in' }}</button>
              <a v-if="authMode !== 'verify'" class="rounded-xl border border-slate-300 bg-white px-5 py-3.5 text-center text-sm font-semibold text-slate-700 transition hover:border-indigo-300 hover:bg-indigo-50/40" href="/auth/google">Continue with Google</a>
              <button v-if="authMode !== 'verify'" class="text-sm font-semibold text-moss hover:text-forest" type="button" @click="authMode = authMode === 'login' ? 'register' : 'login'">{{ authMode === 'login' ? 'New here? Create an account' : 'Already have an account? Log in' }}</button>
            </form>
          </section>
        </div>
      </Transition>
    </Teleport>
    <footer><span>Made for the things worth saying.</span><span>TeeTranscribe © {{ new Date().getFullYear() }}</span></footer>
  </div>
  </template>
</template>

<style>
:root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #172033; background: #f7f8fc; font-synthesis: none; }
* { box-sizing: border-box; }
body { margin: 0; background: #f7f8fc; }
button, select { font: inherit; }
button { cursor: pointer; }
button:disabled { cursor: default; }
button:focus-visible, select:focus-visible, a:focus-visible, input:focus-visible { outline: 3px solid #a5b4fc; outline-offset: 3px; }
.page { position: relative; isolation: isolate; max-width: 1440px; margin: auto; padding: 0 72px; min-height: 100dvh; display: flex; flex-direction: column; overflow: hidden; }
.page::before { content: ''; position: absolute; z-index: -1; width: 520px; height: 520px; top: -210px; right: -120px; border-radius: 50%; background: radial-gradient(circle, #c7d2fe 0, #e0e7ff99 42%, transparent 70%); filter: blur(8px); }
.page::after { content: ''; position: absolute; z-index: -1; width: 430px; height: 430px; bottom: -260px; left: -130px; border-radius: 50%; background: radial-gradient(circle, #a5f3fc99 0, transparent 70%); }
.masthead { height: 92px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e5e7ef; }
.brand { display: flex; align-items: center; gap: 11px; text-decoration: none; color: #172033; font-weight: 750; letter-spacing: -.7px; font-size: 20px; }
.brand-mark { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 12px; background: linear-gradient(135deg, #6366f1, #7c3aed); box-shadow: 0 8px 20px #6366f133; color: white; font-size: 21px; font-weight: 800; }
.home-main { display: grid; grid-template-columns: minmax(0, 1fr) minmax(420px, .86fr); align-items: center; gap: clamp(48px, 7vw, 108px); flex: 1; padding: 72px 0 82px; }
.intro { animation: rise-in .65s cubic-bezier(.22,1,.36,1) both; }
.eyebrow { display: inline-flex; align-items: center; gap: 8px; color: #4f46e5; background: #eef2ff; border: 1px solid #dfe3ff; border-radius: 999px; padding: 8px 12px; letter-spacing: 1.2px; font-size: 10px; font-weight: 800; margin: 0 0 24px; }
.eyebrow::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 4px #dcfce7; }
.page h1 { max-width: 680px; font: 750 clamp(44px, 5vw, 72px)/1.02 Inter, ui-sans-serif, system-ui, sans-serif; letter-spacing: -.055em; margin: 0; color: #111827; }
.page h1 em { font-style: normal; font-weight: 750; color: transparent; background: linear-gradient(100deg, #4f46e5, #7c3aed 52%, #0891b2); background-clip: text; -webkit-background-clip: text; }
.intro-copy { max-width: 540px; font-size: 17px; line-height: 1.7; color: #667085; margin: 28px 0 0; }
.upload-card { background: #ffffff; padding: 30px; border: 1px solid #e6e8f0; border-radius: 24px; box-shadow: 0 24px 70px #47556917, 0 2px 8px #4755690a; animation: rise-in .65s .08s cubic-bezier(.22,1,.36,1) both; }
.dashboard-upload-card { box-shadow: 0 8px 30px #47556910; }
.card-heading { display: flex; align-items: center; justify-content: space-between; margin-bottom: 22px; }
.page h2 { margin: 0; font-size: 21px; font-weight: 750; letter-spacing: -.5px; color: #172033; }
.card-heading > span { font-size: 9px; font-weight: 800; letter-spacing: 1.5px; color: #6366f1; background: #eef2ff; border-radius: 999px; padding: 6px 9px; }
.file-input { display: none; }
.dropzone { width: 100%; border: 1.5px dashed #c7d2fe; border-radius: 16px; padding: 30px 18px 24px; background: linear-gradient(145deg, #fafaff, #f5f7ff); color: #344054; display: flex; flex-direction: column; align-items: center; transition: transform .2s, background .2s, border-color .2s, box-shadow .2s; }
.dropzone:hover:not(:disabled), .dropzone.dragging { transform: translateY(-2px); background: #eef2ff; border-color: #6366f1; box-shadow: 0 12px 30px #6366f11a; }
.dropzone.selected { border-style: solid; border-color: #818cf8; }
.dropzone strong { font-size: 15px; margin-top: 16px; font-weight: 700; }
.dropzone > span:not(.audio-symbol) { font-size: 13px; color: #667085; margin-top: 6px; }
.dropzone u { text-underline-offset: 3px; color: #4f46e5; font-weight: 650; }
.dropzone small { font-size: 10px; color: #98a2b3; margin-top: 20px; }
.audio-symbol { display: flex; gap: 4px; height: 38px; align-items: center; }
.audio-symbol i { width: 4px; height: 14px; border-radius: 4px; background: linear-gradient(#6366f1, #22d3ee); }
.audio-symbol i:nth-child(2), .audio-symbol i:nth-child(4) { height: 26px; }
.audio-symbol i:nth-child(3) { height: 38px; }
.filename { max-width: 100%; overflow-wrap: anywhere; }
.uploaded-file { display: grid; gap: 9px; margin-bottom: 20px; }
.uploaded-file label { font-size: 13px; font-weight: 700; }
.uploaded-file input { min-width: 0; width: 100%; padding: 11px; border: 1px solid #d0d5dd; border-radius: 9px; background: #f9fafb; color: #344054; font: inherit; font-size: 12px; }
.uploaded-file a { color: #4f46e5; font-size: 12px; font-weight: 650; text-underline-offset: 3px; }
.uploaded-file small { color: #667085; font-size: 11px; }
.language-row { display: flex; align-items: center; justify-content: space-between; gap: 15px; padding: 25px 0; }
.language-row label { font-size: 13px; font-weight: 700; color: #344054; }
.language-row p { font-size: 11px; color: #98a2b3; margin: 5px 0 0; }
select { border: 1px solid #d0d5dd; border-radius: 10px; padding: 11px 30px 11px 13px; color: #344054; background: #fff; font-size: 12px; max-width: 52%; }
.submit { width: 100%; display: flex; align-items: center; justify-content: space-between; min-height: 50px; padding: 14px 18px; border: 0; border-radius: 12px; background: linear-gradient(100deg, #5b5cf0, #7c3aed); box-shadow: 0 10px 24px #6366f12e; color: #fff; font-weight: 700; font-size: 14px; transition: transform .2s, box-shadow .2s; }
.submit:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 15px 30px #6366f13d; }
.submit:disabled { opacity: .5; }
.submit > span:last-child { font-size: 21px; }
.footnote { text-align: center; color: #98a2b3; font-size: 10px; margin: 15px 0 0; }
.cost-notice { margin: 0 0 16px; padding: 14px; border: 1px solid #c7d2fe; border-radius: 10px; background: #eef2ff; color: #4338ca; font-size: 13px; line-height: 1.6; }
.error { background: #fff1f2; border: 1px solid #fecdd3; border-radius: 10px; color: #be123c; padding: 12px; font-size: 13px; line-height: 1.5; }
.success { background: #ecfdf3; border: 1px solid #a7f3d0; border-radius: 10px; padding: 15px; margin-bottom: 20px; font-size: 13px; }
.success p { margin: 6px 0 16px; color: #047857; }
.success > span { display: block; font-size: 9px; letter-spacing: 1px; margin-bottom: 5px; }
.success code { font-size: 11px; overflow-wrap: anywhere; }
.progress-area { font-size: 12px; color: #4f46e5; font-weight: 650; margin-bottom: 20px; }
progress { display: block; width: 100%; height: 6px; margin-top: 10px; accent-color: #6366f1; }
footer { display: flex; justify-content: space-between; border-top: 1px solid #e5e7ef; padding: 24px 0; font-size: 11px; color: #98a2b3; }
@keyframes rise-in { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: translateY(0); } }
@media (max-width: 900px) { .page { padding: 0 28px; } .masthead { height: 80px; } .home-main { grid-template-columns: 1fr; gap: 40px; padding: 52px 0 64px; max-width: 620px; width: 100%; align-self: center; } .page h1 { font-size: clamp(44px, 9vw, 64px); } }
@media (max-width: 520px) { .upload-card { padding: 22px 18px; border-radius: 20px; } .page { padding: 0 18px; } .page h1 { font-size: 42px; } .language-row { align-items: flex-start; flex-direction: column; } select { max-width: 100%; width: 100%; } footer { gap: 16px; font-size: 10px; } }
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; scroll-behavior: auto !important; transition: none !important; } }
</style>
