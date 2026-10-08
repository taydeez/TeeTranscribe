import type { FolderOption, FolderOptionPage } from '~/types/folder'
import type { TranscriptionSource, TranscriptionStage, TranscriptionSubmission } from '~/types/transcription'
import { formatCredits } from '~/utils/credits'

const formats: Record<string, string> = {
  mp3: 'audio/mpeg', wav: 'audio/wav', m4a: 'audio/mp4', mp4: 'audio/mp4',
  ogg: 'audio/ogg', oga: 'audio/ogg', flac: 'audio/flac', webm: 'audio/webm', aac: 'audio/aac',
}

export function useTranscriptionForm() {
  const auth = useAuthStore()
  const resumable = useResumableUpload()
  const pricing = useTranscriptionQuote()
  const source = ref<TranscriptionSource>('file')
  const file = ref<File | null>(null)
  const pastedUrl = ref('')
  const language = ref('en')
  const name = ref('')
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
  let audioStoragePath: string | undefined

  const uploadLimit = computed(() => '5 GB')
  const busy = computed(() => readingDuration.value || resumable.cancelling.value || ['preparing', 'uploading', 'pricing', 'submitting'].includes(stage.value))
  const canSubmit = computed(() => stage.value === 'quoted' ? pricing.quote.value?.enough_credits : source.value === 'url' ? pastedUrl.value.trim() !== '' : Boolean(file.value))
  const sizeLabel = computed(() => !file.value ? '' : file.value.size < 1024 * 1024
    ? `${Math.max(1, Math.round(file.value.size / 1024))} KB`
    : `${(file.value.size / 1024 / 1024).toFixed(1)} MB`)
  const durationLabel = computed(() => {
    const value = pricing.quote.value?.quantity ? pricing.quote.value.quantity / 1000 : duration.value
    if (value === null) return ''
    const seconds = Math.max(1, Math.ceil(value))
    const parts = [[Math.floor(seconds / 3600), 'hour'], [Math.floor((seconds % 3600) / 60), 'minute'], [seconds % 60, 'second']] as const
    return parts.filter(([count]) => count > 0).map(([count, unit]) => `${count} ${unit}${count === 1 ? '' : 's'}`).join(' ')
  })
  const submitLabel = computed(() => ({
    idle: 'Check price', preparing: 'Preparing upload…', uploading: 'Uploading…',
    pricing: 'Checking audio & price…', quoted: `Confirm · ${formatCredits(pricing.quote.value?.credit_units ?? 0)} credits`,
    submitting: 'Sending for transcription…', done: 'Transcribe another',
  })[stage.value])
  watch(resumable.progress, value => { if (stage.value === 'uploading') progress.value = value })
  watch([language, pastedUrl], () => {
    if (busy.value) return
    pricing.reset(); stage.value = 'idle'; error.value = ''
    if (source.value === 'url') uploadedUrl.value = ''
  })

  function resetResult() {
    error.value = ''; requestId.value = ''; uploadedUrl.value = ''; audioStoragePath = undefined
    progress.value = 0; stage.value = 'idle'; pricing.reset()
  }

  function chooseSource(value: TranscriptionSource) {
    if (busy.value || source.value === value) return
    source.value = value; resetResult()
  }

  function readDuration(candidate: File): Promise<number> {
    return new Promise((resolve, reject) => {
      const audio = document.createElement('audio')
      const objectUrl = URL.createObjectURL(candidate)
      const timeout = setTimeout(fail, 15_000)
      function cleanup() { clearTimeout(timeout); audio.removeAttribute('src'); audio.load(); URL.revokeObjectURL(objectUrl) }
      function fail() { cleanup(); reject(new Error('The server will check this file’s audio length.')) }
      audio.preload = 'metadata'
      audio.onloadedmetadata = () => {
        if (!Number.isFinite(audio.duration) || audio.duration <= 0) return fail()
        const value = audio.duration; cleanup(); resolve(value)
      }
      audio.onerror = fail; audio.src = objectUrl
    })
  }

  async function selectFile(candidate?: File, knownDuration?: number) {
    if (!candidate || busy.value) return
    resetResult(); file.value = null; duration.value = null
    const extension = candidate.name.split('.').pop()?.toLowerCase() ?? ''
    if (!formats[extension]) { error.value = 'Choose an MP3, WAV, M4A, MP4, OGG, FLAC, WebM, or AAC file.'; return }
    if (!candidate.size || candidate.size > 5 * 1024 ** 3) { error.value = 'Choose a non-empty file up to 5 GB.'; return }
    file.value = candidate
    if (knownDuration && Number.isFinite(knownDuration)) { duration.value = knownDuration; return }
    readingDuration.value = true
    try { duration.value = await readDuration(candidate) }
    catch { duration.value = null }
    finally { readingDuration.value = false }
  }

  function clearFile() {
    if (busy.value) return
    file.value = null; duration.value = null; resetResult()
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

  function validatedUrl(): string | null {
    try { const value = new URL(pastedUrl.value.trim()); return ['http:', 'https:'].includes(value.protocol) ? value.toString() : null }
    catch { return null }
  }

  async function checkPrice() {
    if (!uploadedUrl.value) {
      if (source.value === 'url') {
        const url = validatedUrl()
        if (!url) throw new Error('Enter a valid HTTP or HTTPS audio URL.')
        uploadedUrl.value = url
      } else {
        if (!file.value) throw new Error('Choose or record audio before submitting.')
        stage.value = 'uploading'
        const extension = file.value.name.split('.').pop()!.toLowerCase()
        const result = await resumable.upload(file.value, formats[extension]!)
        uploadedUrl.value = result.audio_url; audioStoragePath = result.audio_storage_path
      }
    }
    stage.value = 'pricing'
    const quote = await pricing.request({ audio_url: uploadedUrl.value, ...(audioStoragePath ? { audio_storage_path: audioStoragePath } : {}), language_code: language.value })
    stage.value = 'quoted'
    return quote
  }

  async function submit(): Promise<string | null> {
    if (busy.value) return null
    if (!auth.isAuthenticated) { error.value = 'Sign in or create an account to transcribe your audio.'; return null }
    if (stage.value === 'done') { file.value = null; pastedUrl.value = ''; name.value = ''; duration.value = null; resetResult(); return null }
    error.value = ''
    try {
      if (stage.value !== 'quoted') { await checkPrice(); return null }
      const quote = pricing.quote.value!
      if (!quote.enough_credits) throw new Error('Add credits and refresh your balance before confirming.')
      stage.value = 'submitting'
      const response = await useAuthenticatedFetch<TranscriptionSubmission>('/api/transcribe', {
        method: 'POST', retry: 0, body: { quote_id: quote.id, ...(folderId.value ? { folder_id: folderId.value } : {}), ...(name.value.trim() ? { name: name.value.trim() } : {}) },
      })
      requestId.value = response.id; stage.value = 'done'
      if (source.value !== 'url') {
        try { await resumable.acknowledge() }
        catch { resumable.notice.value = 'Submitted successfully. Could not clear this browser’s saved upload.' }
      }
      return response.id
    } catch (failure: unknown) {
      const issue = failure as { data?: { message?: string }; message?: string; statusCode?: number }
      error.value = issue.data?.message ?? issue.message ?? 'Something went wrong. Please try again.'
      stage.value = pricing.quote.value?.status === 'ready' ? 'quoted' : 'idle'
      if (issue.statusCode === 409) { pricing.reset(); stage.value = 'idle' }
      if (issue.statusCode === 402 && pricing.quote.value) pricing.quote.value.enough_credits = false
      return null
    }
  }

  async function refreshPrice() {
    if (busy.value) return
    stage.value = 'idle'; await submit()
  }

  async function cancelUpload() {
    try { await resumable.cancel(); resetResult() }
    catch (failure: unknown) { error.value = failure instanceof Error ? failure.message : 'Could not cancel the upload.' }
  }

  async function discardUpload(record: import('~/types/upload').SavedUpload) {
    try { await resumable.discard(record) }
    catch (failure: unknown) { error.value = failure instanceof Error ? failure.message : 'Could not cancel the saved upload.' }
  }

  return { source, file, pastedUrl, language, name, folderId, folders, foldersLoading, stage, progress, duration, busy, canSubmit, sizeLabel, durationLabel, submitLabel, error, requestId, uploadedUrl, uploadLimit, resumable, quote: pricing.quote, refreshPrice, cancelUpload, discardUpload, chooseSource, selectFile, clearFile, loadFolders, submit }
}
