import type { DubbingCatalog, DubbingLanguage, DubbingQuote, DubbingRecord, SubtitleStyle, SubtitleStyleId, VideoOperation, DubbingMediaType } from '~/types/dubbing'
import { DUBBING_AUDIO_MIME_TYPES } from '~/config/dubbing'
import type { CompletedUpload } from '~/types/upload'

export function useDubbingWorkflow() {
  const uploader = useResumableUpload()
  const operation = ref<VideoOperation>('dubbing')
  const mediaType = ref<DubbingMediaType>('video')
  const route = useRoute(), router = useRouter()
  const folderId = ref(typeof route.query.folder === 'string' ? route.query.folder : '')
  const file = ref<File | null>(null), name = ref(''), sourceLanguage = ref(''), targetLanguage = ref('en')
  const languages = ref<DubbingLanguage[]>([]), configured = ref(false), loadingLanguages = ref(false)
  const sourceLanguages = ref<DubbingLanguage[]>([])
  const subtitlesEnabled = ref(false), subtitleStyle = ref<SubtitleStyleId>('classic'), subtitleStyles = ref<SubtitleStyle[]>([])
  const quote = ref<DubbingQuote | null>(null), record = ref<DubbingRecord | null>(null)
  const busy = ref(false), opening = ref(false), error = ref(''), historyVersion = ref(0)
  let uploaded: CompletedUpload | null = null, key = '', alive = true, sequence = 0, quoteSequence = 0
  let catalog: DubbingCatalog | null = null
  let quoteTimer: ReturnType<typeof setTimeout> | null = null, recordTimer: ReturnType<typeof setTimeout> | null = null
  const message = (failure: unknown) => (failure as { data?: { message?: string }; message?: string }).data?.message ?? (failure as Error).message ?? 'Dubbing could not be completed.'
  watch(file, () => { uploaded = null }, { flush: 'sync' })
  watch([file, name, folderId, sourceLanguage, targetLanguage, subtitlesEnabled, subtitleStyle, operation, mediaType], () => {
    quote.value = null; key = ''; quoteSequence++
    if (quoteTimer) clearTimeout(quoteTimer)
  }, { flush: 'sync' })
  function applyCatalog() {
    if (!catalog) return
    const selected = mediaType.value === 'audio' ? catalog.audioDubbing : operation.value === 'subtitles' ? catalog.subtitlesOnly : catalog
    languages.value = selected?.data ?? []; sourceLanguages.value = selected?.sourceLanguages ?? []
    configured.value = selected?.configured ?? false
    if (sourceLanguage.value && !sourceLanguages.value.some(item => item.code === sourceLanguage.value)) sourceLanguage.value = ''
    if (!languages.value.some(item => item.code === targetLanguage.value)) {
      targetLanguage.value = languages.value.find(item => item.name === 'English')?.code ?? languages.value[0]?.code ?? ''
    }
  }
  watch(operation, value => { subtitlesEnabled.value = value === 'subtitles'; applyCatalog() }, { flush: 'sync' })
  watch(mediaType, () => { operation.value = 'dubbing'; subtitlesEnabled.value = false; file.value = null; error.value = ''; applyCatalog() }, { flush: 'sync' })

  async function loadLanguages() {
    loadingLanguages.value = true; error.value = ''
    try {
      const result = await useAuthenticatedFetch<DubbingCatalog>('/api/dubbings/languages')
      if (alive) {
        catalog = result; applyCatalog()
        subtitleStyles.value = result.subtitleStyles ?? []
      }
    } catch (failure) { if (alive) error.value = message(failure) }
    finally { if (alive) loadingLanguages.value = false }
  }
  function selectFile(event: Event) {
    const selected = (event.target as HTMLInputElement).files?.[0] ?? null
    if (!selected) return
    const extension = selected.name.split('.').pop()?.toLowerCase() ?? ''
    if (!(mediaType.value === 'audio' ? DUBBING_AUDIO_MIME_TYPES[extension] : /\.(mp4|webm)$/i.test(selected.name)) || selected.size > 3 * 1024 ** 3) {
      error.value = mediaType.value === 'audio' ? 'Choose a supported audio file, up to 3 GiB.' : 'Choose an MP4 or WebM video, up to 3 GiB.'; return
    }
    file.value = selected; error.value = ''
  }
  function scheduleQuote(current: number) {
    if (quoteTimer) clearTimeout(quoteTimer)
    quoteTimer = null
    if (alive && current === quoteSequence && quote.value?.status === 'measuring') {
      const id = quote.value.id
      quoteTimer = setTimeout(() => { void refreshQuote(id, current) }, 2000)
    }
  }
  async function refreshQuote(id: string, current: number) {
    try {
      const result = await useAuthenticatedFetch<DubbingQuote>(`/api/dubbings/quotes/${id}`)
      if (alive && current === quoteSequence) quote.value = result
    } catch (failure) { if (alive && current === quoteSequence) error.value = message(failure) }
    finally { scheduleQuote(current) }
  }
  async function checkPrice() {
    if (busy.value || !file.value || !configured.value) return
    busy.value = true; error.value = ''; key ||= crypto.randomUUID()
    const current = ++quoteSequence
    const selected = file.value
    try {
      const extension = selected.name.split('.').pop()?.toLowerCase() ?? ''
      const contentType = mediaType.value === 'audio' ? DUBBING_AUDIO_MIME_TYPES[extension] : /\.webm$/i.test(selected.name) ? 'video/webm' : 'video/mp4'
      if (!contentType) throw new Error('Choose a supported audio file.')
      uploaded ??= await uploader.upload(selected, contentType)
      const result = await useAuthenticatedFetch<DubbingQuote>('/api/dubbings/quotes', { method: 'POST', body: {
        client_key: key, operation: operation.value, media_type: mediaType.value, folder_id: folderId.value || null,
        ...(mediaType.value === 'audio' ? { audio_storage_path: uploaded.audio_storage_path } : { video_storage_path: uploaded.audio_storage_path }), name: name.value.trim() || null,
        source_language: sourceLanguage.value || null, target_language: targetLanguage.value,
        subtitles_enabled: operation.value === 'subtitles' || subtitlesEnabled.value,
        subtitle_style: operation.value === 'subtitles' || subtitlesEnabled.value ? subtitleStyle.value : null,
      } })
      if (alive && current === quoteSequence) { quote.value = result; scheduleQuote(current) }
    } catch (failure) { if (alive) error.value = message(failure) }
    finally { if (alive) busy.value = false }
  }
  function scheduleRecord() {
    if (recordTimer) clearTimeout(recordTimer)
    recordTimer = null
    if (alive && record.value && ['pending', 'processing'].includes(record.value.status)) {
      const id = record.value.id
      recordTimer = setTimeout(() => { void refresh(id) }, 5000)
    }
  }
  async function refresh(id: string) {
    const current = ++sequence
    try {
      const result = await useAuthenticatedFetch<DubbingRecord>(`/api/dubbings/${id}`)
      if (!alive || current !== sequence) return
      const working = record.value && ['pending', 'processing'].includes(record.value.status)
      record.value = result
      if (working && !['pending', 'processing'].includes(result.status)) historyVersion.value++
    } catch (failure) { if (alive && current === sequence) error.value = message(failure) }
    finally { if (alive && current === sequence) scheduleRecord() }
  }
  async function open(id: string) {
    if (record.value?.id === id) return
    if (recordTimer) clearTimeout(recordTimer)
    record.value = null; opening.value = true; error.value = ''
    await refresh(id)
    if (alive) opening.value = false
  }
  async function submit() {
    if (busy.value || quote.value?.status !== 'ready' || !quote.value.enough_credits) return
    if (Date.parse(quote.value.expires_at) <= Date.now()) { quote.value = null; key = ''; error.value = 'Your quote expired. Check the price again.'; return }
    busy.value = true; error.value = ''
    try {
      const result = await useAuthenticatedFetch<DubbingRecord>('/api/dubbings', { method: 'POST', body: { quote_id: quote.value.id } })
      if (!alive) return
      sequence++; if (recordTimer) clearTimeout(recordTimer)
      record.value = result; quote.value = null; key = ''; historyVersion.value++
      await uploader.acknowledge(); uploaded = null; file.value = null
      await router.replace({ query: { ...route.query, dubbing: result.id } })
      scheduleRecord()
    } catch (failure) { if (alive) error.value = message(failure) }
    finally { if (alive) busy.value = false }
  }
  async function retryExports() {
    if (busy.value || !record.value?.canRetryExports) return
    busy.value = true; error.value = ''
    const id = record.value.id
    sequence++; if (recordTimer) clearTimeout(recordTimer)
    try {
      const result = await useAuthenticatedFetch<DubbingRecord>(`/api/dubbings/${id}/retry`, { method: 'POST' })
      if (alive && record.value?.id === id) { record.value = result; historyVersion.value++ }
    } catch (failure) { if (alive) error.value = message(failure) }
    finally { if (alive) { busy.value = false; scheduleRecord() } }
  }
  async function remove(id: string) {
    historyVersion.value++
    if (record.value?.id !== id && route.query.dubbing !== id) return
    sequence++; if (recordTimer) clearTimeout(recordTimer)
    record.value = null
    const query = { ...route.query }; delete query.dubbing
    await router.replace({ query })
  }
  watch(() => route.query.dubbing, id => {
    if (typeof id === 'string') void open(id)
    else { sequence++; if (recordTimer) clearTimeout(recordTimer); record.value = null }
  })
  onMounted(() => { void loadLanguages(); if (typeof route.query.dubbing === 'string') void open(route.query.dubbing) })
  onBeforeUnmount(() => { alive = false; sequence++; quoteSequence++; if (recordTimer) clearTimeout(recordTimer); if (quoteTimer) clearTimeout(quoteTimer) })
  return { folderId, mediaType, operation, uploader, file, name, sourceLanguage, targetLanguage, languages, sourceLanguages, subtitlesEnabled, subtitleStyle, subtitleStyles, configured, loadingLanguages, quote, record, busy, opening, error, historyVersion, selectFile, loadLanguages, checkPrice, submit, retryExports, refresh, remove }
}
