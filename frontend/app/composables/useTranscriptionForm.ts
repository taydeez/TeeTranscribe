import type { FolderOption, FolderOptionPage } from '~/types/folder'
import type { TranscriptionSource, TranscriptionStage, TranscriptionSubmission, UploadTicket } from '~/types/transcription'

const formats: Record<string, string> = {
  mp3: 'audio/mpeg', wav: 'audio/wav', m4a: 'audio/mp4', mp4: 'audio/mp4',
  ogg: 'audio/ogg', oga: 'audio/ogg', flac: 'audio/flac', webm: 'audio/webm', aac: 'audio/aac',
}

export function useTranscriptionForm() {
  const auth = useAuthStore()
  const source = ref<TranscriptionSource>('file')
  const file = ref<File | null>(null)
  const pastedUrl = ref('')
  const language = ref('en-NG')
  const folderId = ref('')
  const folders = ref<FolderOption[]>([])
  const foldersLoading = ref(false)
  const stage = ref<TranscriptionStage>('idle')
  const progress = ref(0)
  const duration = ref<number | null>(null)
  const readingDuration = ref(false)
  const error = ref('')
  const requestId = ref('')
  const uploadedUrl = ref('')
  const guestSessionId = ref('')

  const maxBytes = 100 * 1024 * 1024
  const busy = computed(() => readingDuration.value || ['preparing', 'uploading', 'submitting'].includes(stage.value))
  const canSubmit = computed(() => source.value === 'url' ? pastedUrl.value.trim() !== '' : Boolean(file.value && duration.value))
  const sizeLabel = computed(() => !file.value ? '' : file.value.size < 1024 * 1024
    ? `${Math.max(1, Math.round(file.value.size / 1024))} KB`
    : `${(file.value.size / 1024 / 1024).toFixed(1)} MB`)
  const durationLabel = computed(() => {
    if (duration.value === null) return ''
    const seconds = Math.max(1, Math.ceil(duration.value))
    const parts = [
      [Math.floor(seconds / 3600), 'hour'],
      [Math.floor((seconds % 3600) / 60), 'minute'],
      [seconds % 60, 'second'],
    ] as const
    return parts.filter(([value]) => value > 0).map(([value, unit]) => `${value} ${unit}${value === 1 ? '' : 's'}`).join(' ')
  })
  const submitLabel = computed(() => ({
    idle: source.value === 'url' ? 'Transcribe from URL' : 'Upload & transcribe',
    preparing: 'Preparing upload…', uploading: `Uploading · ${progress.value}%`,
    submitting: 'Sending for transcription…', done: 'Transcribe another',
  })[stage.value])

  let activeUpload: XMLHttpRequest | null = null

  function resetResult() {
    error.value = ''
    requestId.value = ''
    uploadedUrl.value = ''
    progress.value = 0
    stage.value = 'idle'
  }

  function chooseSource(value: TranscriptionSource) {
    if (busy.value || source.value === value) return
    source.value = value
    resetResult()
  }

  function readDuration(candidate: File): Promise<number> {
    return new Promise((resolve, reject) => {
      const audio = document.createElement('audio')
      const objectUrl = URL.createObjectURL(candidate)
      const timeout = setTimeout(fail, 15_000)
      function cleanup() {
        clearTimeout(timeout)
        audio.removeAttribute('src')
        audio.load()
        URL.revokeObjectURL(objectUrl)
      }
      function fail() {
        cleanup()
        reject(new Error('Could not read this audio file’s length. Try another file or convert it to MP3 or WAV.'))
      }
      audio.preload = 'metadata'
      audio.onloadedmetadata = () => {
        if (!Number.isFinite(audio.duration) || audio.duration <= 0) return fail()
        const value = audio.duration
        cleanup()
        resolve(value)
      }
      audio.onerror = fail
      audio.src = objectUrl
    })
  }

  async function selectFile(candidate?: File, knownDuration?: number) {
    if (!candidate || busy.value) return
    resetResult()
    file.value = null
    duration.value = null
    const extension = candidate.name.split('.').pop()?.toLowerCase() ?? ''
    if (!formats[extension]) {
      error.value = 'Choose an MP3, WAV, M4A, MP4, OGG, FLAC, WebM, or AAC audio file.'
      return
    }
    if (!candidate.size || candidate.size > maxBytes) {
      error.value = 'Choose a non-empty audio file smaller than 100 MB.'
      return
    }
    file.value = candidate
    if (knownDuration && Number.isFinite(knownDuration)) {
      duration.value = knownDuration
      return
    }
    readingDuration.value = true
    try { duration.value = await readDuration(candidate) }
    catch (failure: unknown) { error.value = failure instanceof Error ? failure.message : 'Could not read the audio length.' }
    finally { readingDuration.value = false }
  }

  function clearFile() {
    file.value = null
    duration.value = null
    resetResult()
  }

  async function loadFolders() {
    if (!auth.isAuthenticated || foldersLoading.value) return
    foldersLoading.value = true
    try {
      const first = await useAuthenticatedFetch<FolderOptionPage>('/api/folders', { query: { page: 1, per_page: 50, sort: 'name', direction: 'asc' } })
      const pages = [first]
      for (let page = 2; page <= first.meta.lastPage; page += 1) {
        pages.push(await useAuthenticatedFetch<FolderOptionPage>('/api/folders', { query: { page, per_page: 50, sort: 'name', direction: 'asc' } }))
      }
      folders.value = pages.flatMap(page => page.data)
    } catch { folders.value = [] }
    finally { foldersLoading.value = false }
  }

  async function ensureGuestSession(): Promise<string> {
    if (guestSessionId.value) return guestSessionId.value
    const response = await $fetch<{ id: string }>('/api/guest-session', { method: 'POST', retry: 0 })
    guestSessionId.value = response.id
    return response.id
  }

  function validatedUrl(): string | null {
    try {
      const value = new URL(pastedUrl.value.trim())
      return ['http:', 'https:'].includes(value.protocol) ? value.toString() : null
    } catch { return null }
  }

  function upload(ticket: UploadTicket, audio: File): Promise<void> {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest()
      activeUpload = xhr
      xhr.open('PUT', ticket.upload_url)
      xhr.timeout = 15 * 60 * 1000
      Object.entries(ticket.headers).forEach(([name, value]) => xhr.setRequestHeader(name, value))
      xhr.upload.onprogress = event => { if (event.lengthComputable) progress.value = Math.round(event.loaded / event.total * 100) }
      xhr.onload = () => {
        activeUpload = null
        if (xhr.status >= 200 && xhr.status < 300) return resolve()
        const code = xhr.responseText.match(/<Code>([^<]+)<\/Code>/)?.[1]
        reject(new Error(`The audio upload was rejected by storage (${code ?? xhr.status}). Please try again.`))
      }
      xhr.onerror = () => reject(new Error('Could not upload the audio. Check your connection and try again.'))
      xhr.ontimeout = () => reject(new Error('The upload took too long. Please try again.'))
      xhr.onabort = () => reject(new Error('Upload cancelled.'))
      xhr.send(audio)
    })
  }

  async function submit(): Promise<string | null> {
    if (busy.value) return null
    if (stage.value === 'done') {
      file.value = null; pastedUrl.value = ''; duration.value = null; resetResult()
      return null
    }
    const remoteUrl = source.value === 'url' ? validatedUrl() : null
    if (source.value === 'url' && !remoteUrl) { error.value = 'Enter a valid HTTP or HTTPS audio URL.'; return null }
    if (source.value !== 'url' && (!file.value || !duration.value)) { error.value = 'Choose or record audio before submitting.'; return null }
    error.value = ''; progress.value = 0; uploadedUrl.value = ''
    let audioStoragePath: string | undefined
    try {
      const identity = auth.isAuthenticated && auth.user ? { user_id: auth.user.id } : { guest_session_id: await ensureGuestSession() }
      if (source.value !== 'url' && file.value) {
        stage.value = 'preparing'
        const extension = file.value.name.split('.').pop()!.toLowerCase()
        const ticket = await $fetch<UploadTicket>('/api/uploads/presign', {
          method: 'POST', retry: 0,
          body: { filename: file.value.name, content_type: formats[extension], size: file.value.size },
        })
        stage.value = 'uploading'
        await upload(ticket, file.value)
        uploadedUrl.value = ticket.audio_url
        audioStoragePath = ticket.audio_storage_path
      } else uploadedUrl.value = remoteUrl!

      stage.value = 'submitting'
      const options = {
        method: 'POST' as const, timeout: 135_000, retry: 0,
        body: {
          audio_url: uploadedUrl.value, language_code: language.value,
          ...(audioStoragePath ? { audio_storage_path: audioStoragePath } : {}),
          ...(auth.isAuthenticated && folderId.value ? { folder_id: folderId.value } : {}),
          ...(source.value !== 'url' && file.value && duration.value ? { file_name: file.value.name, duration: Number(duration.value.toFixed(3)) } : {}),
          ...identity,
        },
      }
      const response = auth.isAuthenticated
        ? await useAuthenticatedFetch<TranscriptionSubmission>('/api/transcribe', options)
        : await $fetch<TranscriptionSubmission>('/api/transcribe', options)
      if (!response.id) throw new Error('The server did not return a transcription request ID.')
      requestId.value = response.id
      stage.value = 'done'
      return response.id
    } catch (failure: unknown) {
      const issue = failure as { data?: { message?: string }; message?: string }
      error.value = issue.data?.message ?? issue.message ?? 'Something went wrong. Please try again.'
      stage.value = 'idle'
      return null
    }
  }

  onBeforeUnmount(() => activeUpload?.abort())

  return { source, file, pastedUrl, language, folderId, folders, foldersLoading, stage, progress, duration, busy, canSubmit, sizeLabel, durationLabel, submitLabel, error, requestId, uploadedUrl, chooseSource, selectFile, clearFile, loadFolders, submit }
}
