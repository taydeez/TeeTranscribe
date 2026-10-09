import type { TranslationLanguage, TranslationQuote, TranslationRecord } from '~/types/translation'
import type { TranscriptSegment } from '~/types/transcription'

export function useTranslationWorkflow() {
  const draft = useTranslationDraftStore()
  const route = useRoute()
  const router = useRouter()
  const languages = ref<TranslationLanguage[]>([])
  const languagesLoading = ref(false)
  const sourceLanguage = ref('')
  const targetLanguage = ref('en')
  const name = ref(draft.sourceName)
  const folderId = ref(typeof route.query.folder === 'string' ? route.query.folder : '')
  const quote = ref<TranslationQuote | null>(null)
  const record = ref<TranslationRecord | null>(null)
  const busy = ref(false)
  const opening = ref(false)
  const error = ref('')
  const historyVersion = ref(0)
  let key = ''
  let active = true
  let sequence = 0
  let timer: ReturnType<typeof setTimeout> | null = null
  const source = () => ({ text: draft.text.trim(), source_language: sourceLanguage.value || null, target_language: targetLanguage.value, name: name.value.trim() || null, transcription_id: draft.transcriptionId || null, folder_id: folderId.value || null,
    ...(draft.segments.length && draft.text.trim() === draft.segments.map(item => item.text.trim()).join('\n') ? { segments: draft.segments.map(item => ({ text: item.text, speaker: item.speaker })) } : {}) })
  watch([() => draft.text, sourceLanguage, targetLanguage, name, folderId, () => draft.transcriptionId, () => JSON.stringify(draft.segments)], () => { quote.value = null; key = '' }, { flush: 'sync' })
  const message = (failure: unknown) => (failure as { data?: { message?: string }; message?: string }).data?.message ?? (failure as Error).message ?? 'Translation could not be completed.'

  async function loadLanguages() {
    languagesLoading.value = true; error.value = ''
    try {
      const result = await useAuthenticatedFetch<{ data: TranslationLanguage[] }>('/api/translations/languages')
      if (active) languages.value = result.data
    } catch (failure) { if (active) error.value = message(failure) }
    finally { if (active) languagesLoading.value = false }
  }
  async function checkPrice() {
    if (busy.value || !draft.text.trim()) return
    busy.value = true; error.value = ''
    key ||= crypto.randomUUID()
    const input = source()
    try {
      const result = await useAuthenticatedFetch<TranslationQuote>('/api/translations/quotes', { method: 'POST', body: { ...input, client_key: key } })
      if (active && JSON.stringify(input) === JSON.stringify(source())) quote.value = result
    } catch (failure) { if (active) error.value = message(failure) }
    finally { if (active) busy.value = false }
  }
  async function submit() {
    if (busy.value || !quote.value?.enough_credits) return
    if (Date.parse(quote.value.expires_at) <= Date.now()) { quote.value = null; key = ''; error.value = 'Your quote expired. Check the price again.'; return }
    busy.value = true; error.value = ''
    try {
      const result = await useAuthenticatedFetch<TranslationRecord>('/api/translations', { method: 'POST', body: { quote_id: quote.value.id } })
      if (!active) return
      ++sequence
      if (timer) clearTimeout(timer)
      record.value = result; quote.value = null; key = ''; historyVersion.value++
      await router.replace({ query: { ...route.query, translation: result.id } })
      schedule()
    } catch (failure) { if (active) error.value = message(failure) }
    finally { if (active) busy.value = false }
  }
  function schedule() {
    if (timer) clearTimeout(timer)
    timer = null
    if (active && record.value && ['pending', 'processing'].includes(record.value.status)) {
      const id = record.value.id
      timer = setTimeout(() => { void refresh(id) }, 5000)
    }
  }
  async function refresh(id: string) {
    const current = ++sequence
    try {
      const result = await useAuthenticatedFetch<TranslationRecord>(`/api/translations/${id}`)
      if (!active || current !== sequence) return
      const wasWorking = record.value && ['pending', 'processing'].includes(record.value.status)
      record.value = result
      if (wasWorking && !['pending', 'processing'].includes(result.status)) historyVersion.value++
    } catch (failure) { if (active && current === sequence) error.value = message(failure) }
    finally { if (active && current === sequence) schedule() }
  }
  async function open(id: string) {
    if (record.value?.id === id) return
    if (timer) clearTimeout(timer)
    record.value = null; opening.value = true; error.value = ''
    await refresh(id)
    if (active) opening.value = false
  }
  async function save(text: string, segments: TranscriptSegment[] | null) {
    if (!record.value || busy.value || !text.trim()) return
    busy.value = true; error.value = ''
    const id = record.value.id
    ++sequence
    if (timer) clearTimeout(timer)
    try {
      const result = await useAuthenticatedFetch<TranslationRecord>(`/api/translations/${id}`, { method: 'PATCH', body: { translated_text: text.trim(), ...(segments ? { segments: segments.map(item => ({ text: item.text, speaker: item.speaker })) } : {}) } })
      if (active && record.value?.id === id) { record.value = result; historyVersion.value++ }
    } catch (failure) { if (active) error.value = message(failure) }
    finally { if (active) { busy.value = false; schedule() } }
  }
  async function remove(id: string) {
    historyVersion.value++
    if (record.value?.id !== id && route.query.translation !== id) return
    sequence++; if (timer) clearTimeout(timer)
    record.value = null
    const query = { ...route.query }; delete query.translation
    await router.replace({ query })
  }
  watch(() => route.query.translation, id => {
    if (typeof id === 'string') void open(id)
    else { sequence++; if (timer) clearTimeout(timer); record.value = null }
  })
  onMounted(() => { void loadLanguages(); if (typeof route.query.translation === 'string') void open(route.query.translation) })
  onBeforeUnmount(() => { active = false; sequence++; if (timer) clearTimeout(timer) })
  return { draft, languages, languagesLoading, sourceLanguage, targetLanguage, name, folderId, quote, record, busy, opening, error, historyVersion, loadLanguages, checkPrice, submit, save, refresh, remove }
}
