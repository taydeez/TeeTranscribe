import type { UsageQuote } from '~/types/billing'

export function useTranscriptionQuote() {
  const quote = ref<UsageQuote | null>(null)
  let clientKey = ''
  let fingerprint = ''
  let cancelled = false

  function reset() { quote.value = null; clientKey = ''; fingerprint = ''; cancelled = true }

  async function request(source: { audio_url: string; audio_storage_path?: string; language_code: string }) {
    cancelled = false
    const next = JSON.stringify(source)
    if (fingerprint !== next) { quote.value = null; clientKey = crypto.randomUUID(); fingerprint = next }
    if (!quote.value) {
      quote.value = await useAuthenticatedFetch<UsageQuote>('/api/billing/quotes', { method: 'POST', retry: 0, body: { ...source, client_key: clientKey } })
    } else {
      quote.value = await useAuthenticatedFetch<UsageQuote>(`/api/billing/quotes/${quote.value.id}`, { retry: 0 })
    }
    const deadline = Date.now() + 10 * 60_000
    while (quote.value.status === 'measuring') {
      if (cancelled) throw new Error('Quote cancelled.')
      if (Date.now() > deadline) throw new Error('Audio is still being checked. Click “Check price” again to continue.')
      await new Promise(resolve => setTimeout(resolve, 2500))
      if (cancelled) throw new Error('Quote cancelled.')
      quote.value = await useAuthenticatedFetch<UsageQuote>(`/api/billing/quotes/${quote.value.id}`, { retry: 0 })
    }
    if (quote.value.status === 'failed') {
      const message = quote.value.failure_reason ?? 'Could not check the audio.'
      reset()
      throw new Error(message)
    }
    if (new Date(quote.value.expires_at).getTime() <= Date.now()) {
      reset()
      throw new Error('The price quote expired. Click “Check price” for a fresh quote.')
    }
    return quote.value
  }

  onBeforeUnmount(() => { cancelled = true })
  return { quote, reset, request }
}
