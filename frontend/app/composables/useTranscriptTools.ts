import type { FolderTranscription } from '~/types/transcription'
import type { TranscriptTool, TranscriptToolOperation, TranscriptToolQuote, TranscriptToolsResponse } from '~/types/transcriptTools'

export function useTranscriptTools(transcription: () => FolderTranscription | null, blocked: () => boolean) {
  const operation = ref<TranscriptToolOperation>('cleanup')
  const configured = ref(false)
  const loading = ref(false)
  const busy = ref(false)
  const error = ref('')
  const records = ref<TranscriptTool[]>([])
  const quote = ref<TranscriptToolQuote | null>(null)
  const record = computed(() => records.value.find(item => item.operation === operation.value) ?? null)
  const source = () => JSON.stringify([transcription()?.id, transcription()?.transcript, transcription()?.segments ?? []])
  const recordSources = new Map<string, string>()
  const fresh = computed(() => Boolean(record.value && !record.value.stale && recordSources.get(record.value.id) === source()))
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
  function remember(item: TranscriptTool, savedSource: string) {
    records.value = [item, ...records.value.filter(existing => existing.operation !== item.operation)]
    recordSources.set(item.id, savedSource)
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
      if (active && current === context && savedSource === source()) { remember(result, savedSource); error.value = '' }
    } catch (failure) { if (active && current === context) error.value = message(failure) }
    finally { if (active && current === context) schedule() }
  }
  async function load() {
    const id = transcription()?.id
    if (!id) return
    const current = ++context
    const savedSource = source()
    stopPolling()
    loading.value = true; error.value = ''
    try {
      const result = await useAuthenticatedFetch<TranscriptToolsResponse>(`/api/transcriptions/${id}/tools`)
      if (!active || current !== context || savedSource !== source()) return
      configured.value = result.configured
      records.value = []
      for (const item of result.data) if (!records.value.some(existing => existing.operation === item.operation)) remember(item, savedSource)
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
      const result = await useAuthenticatedFetch<TranscriptToolQuote>(`/api/transcriptions/${id}/tools/quotes`, { method: 'POST', body: { operation: selected, client_key: clientKey } })
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
      remember(result, savedSource); quote.value = null; clientKey = ''; schedule()
    } catch (failure) { if (active && current === request) error.value = message(failure) }
    finally { if (active && current === request) { busy.value = false; submitting = false } }
  }
  function cancelQuote(force = false) {
    quote.value = null; clientKey = ''
    if (submitting && !force) return
    request++; busy.value = false; submitting = false
  }
  watch(source, () => {
    context++; stopPolling(); cancelQuote(true); records.value = []; recordSources.clear(); configured.value = false
    if (mounted) void load()
  }, { flush: 'sync' })
  watch(blocked, value => { if (value) cancelQuote() }, { flush: 'sync' })
  onMounted(() => { mounted = true; void load() })
  onBeforeUnmount(() => { active = false; context++; request++; stopPolling() })
  return { operation, configured, loading, busy, error, record, quote, working, fresh, canApply, select, load, checkPrice, confirm, cancelQuote }
}
