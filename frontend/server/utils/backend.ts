import type { H3Event } from 'h3'
import { $fetch as ofetch } from 'ofetch'

export async function forwardToBackend(event: H3Event, path: string) {
  const config = useRuntimeConfig(event)
  const body = await readBody(event)
  const authorization = getHeader(event, 'authorization')

  try {
    return await ofetch(`${config.apiBase.replace(/\/$/, '')}/${path}`, {
      method: 'POST',
      body,
      headers: { Accept: 'application/json', ...(authorization ? { Authorization: authorization } : {}) },
      timeout: 130_000,
      retry: 0,
    })
  } catch (error: unknown) {
    const failure = error as { statusCode?: number; data?: { message?: string } }
    const status = failure.statusCode ?? 502
    throw createError({
      statusCode: status,
      message: status >= 500
        ? 'The transcription server is unavailable. Please try again shortly.'
        : failure.data?.message ?? 'Your request could not be processed.',
    })
  }
}
