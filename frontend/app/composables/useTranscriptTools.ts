import type { FolderTranscription } from '~/types/transcription'
import type { TranscriptTool, TranscriptToolOperation, TranscriptToolQuote, TranscriptToolsResponse, TranscriptToolSource } from '~/types/transcriptTools'

export function useTranscriptTools(transcription: () => FolderTranscription | null, blocked: () => boolean, draft?: () => TranscriptToolSource) {
  const operation = ref<TranscriptToolOperation>('cleanup')
  const configured = ref(false)
  const loading = ref(false)
  const busy = ref(false)
  const error = ref('')
  const records = ref<TranscriptTool[]>([])
  const quote = ref<TranscriptToolQuote | null>(null)
  const record = computed(() => records.value.find(item => item.operation === operation.value && matches(item)) ?? records.value.find(item => item.operation === operation.value) ?? null)
  const pendingRecords = computed(() => records.value.filter(item => ['pending', 'processing'].includes(item.status)))
  const input = (): TranscriptToolSource => draft?.() ?? { text: transcription()?.transcript ?? '', segments: transcription()?.segments ?? [] }
  const serialize = (value: TranscriptToolSource) => JSON.stringify([value.text.trim(), value.segments.map(segment => ({ text: segment.text.trim(), speaker: segment.speaker ?? null }))])
  const source = () => serialize(input())
  const matches = (item: TranscriptTool) => serialize(item.source) === source()
  const fresh = computed(() => Boolean(record.value && matches(record.value)))
  const working = computed(() => Boolean(fresh.value && record.value && ['pending', 'processing'].includes(record.value.status)))
  const canApply = computed(() => operation.value === 'cleanup' && record.value?.status === 'complete' && Boolean(record.value.result?.text?.trim()) && fresh.value && !blocked())
  let active = true
  let mounted = false
  let context = 0
  let request = 0
  let clientKey = ''
  let submitting = false
  let timer: ReturnType<typeof setTimeout> | null = null
  const message = (failure: unknown) => (failure as { data?: { message?: string }; message?: string }).data?.message ?? (failure as Error).message ?? 'This transcript tool is temporarily unavailable.'

  function stopPolling() {
    if (timer) clearTimeout(timer)
    timer = null
  }
  function remember(item: TranscriptTool) {
    records.value = [item, ...records.value.filter(existing => existing.id !== item.id)]
  }
  function schedule() {
    stopPolling()
    if (!active || !working.value || !record.value) return
    const tool = record.value.id
    timer = setTimeout(() => { void refresh(tool) }, 5000)
  }
  async function refresh(tool: string) {
    const id = transcription()?.id
    const current = context
    const savedSource = source()
    if (!id) return
    try {
      const result = await useAuthenticatedFetch<TranscriptTool>(`/api/transcriptions/${id}/tools/${tool}`)
      if (active && current === context && savedSource === source()) { remember(result); error.value = '' }
    } catch (failure) { if (active && current === context) error.value = message(failure) }
    finally { if (active && current === context) schedule() }
  }
  async function load() {
    const id = transcription()?.id
    if (!id) return
    const current = ++context
    stopPolling()
    loading.value = true; error.value = ''
    try {
      const result = await useAuthenticatedFetch<TranscriptToolsResponse>(`/api/transcriptions/${id}/tools`)
      if (!active || current !== context) return
      configured.value = result.configured
      records.value = result.data
      schedule()
    } catch (failure) { if (active && current === context) error.value = message(failure) }
    finally { if (active && current === context) loading.value = false }
  }
  function select(value: TranscriptToolOperation) {
    if (busy.value || loading.value || operation.value === value) return
    context++; request++; stopPolling()
    operation.value = value; quote.value = null; clientKey = ''; error.value = ''
    schedule()
  }
  async function checkPrice() {
    const id = transcription()?.id
    if (!id || !configured.value || blocked() || busy.value || loading.value) return
    if (record.value && fresh.value && ['pending', 'processing', 'complete'].includes(record.value.status)) { schedule(); return }
    const current = ++request
    const savedSource = source()
    const selected = operation.value
    clientKey ||= crypto.randomUUID()
    busy.value = true; error.value = ''
    try {
      const currentDraft = input()
      const result = await useAuthenticatedFetch<TranscriptToolQuote>(`/api/transcriptions/${id}/tools/quotes`, { method: 'POST', body: { operation: selected, client_key: clientKey, draft: { transcript: currentDraft.text.trim(), segments: currentDraft.segments.map(segment => ({ text: segment.text.trim(), speaker: segment.speaker ?? null })) } } })
      if (active && current === request && savedSource === source() && selected === operation.value && !blocked()) quote.value = result
    } catch (failure) { if (active && current === request) error.value = message(failure) }
    finally { if (active && current === request) busy.value = false }
  }
  async function confirm() {
    const id = transcription()?.id
    if (!id || blocked() || busy.value || !quote.value?.enough_credits) return
    if (Date.parse(quote.value.expires_at) <= Date.now()) { quote.value = null; clientKey = ''; error.value = 'Your quote expired. Check the price again.'; return }
    const current = ++request
    const savedSource = source()
    submitting = true
    busy.value = true; error.value = ''
    try {
      const result = await useAuthenticatedFetch<TranscriptTool>(`/api/transcriptions/${id}/tools`, { method: 'POST', body: { quote_id: quote.value.id } })
      if (!active || current !== request || savedSource !== source()) return
      remember(result); quote.value = null; clientKey = ''; schedule()
    } catch (failure) { if (active && current === request) error.value = message(failure) }
    finally { if (active && current === request) { busy.value = false; submitting = false } }
  }
  function cancelQuote(force = false) {
    quote.value = null; clientKey = ''
    if (submitting && !force) return
    request++; busy.value = false; submitting = false
  }
  watch(source, () => {
    stopPolling(); cancelQuote(true); schedule()
  }, { flush: 'sync' })
  watch(() => JSON.stringify([transcription()?.id, transcription()?.transcript, transcription()?.segments]), () => {
    context++; stopPolling(); cancelQuote(true); records.value = []; configured.value = false
    if (mounted) void load()
  }, { flush: 'sync' })
  watch(blocked, value => { if (value) cancelQuote() }, { flush: 'sync' })
  onMounted(() => { mounted = true; void load() })
  onBeforeUnmount(() => { active = false; context++; request++; stopPolling() })
  return { operation, configured, loading, busy, error, record, pendingRecords, quote, working, fresh, canApply, select, load, checkPrice, confirm, cancelQuote }
}
