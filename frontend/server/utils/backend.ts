import type { H3Event } from 'h3'

export async function forwardToBackend(event: H3Event, path: string) {
  const config = useRuntimeConfig(event)
  const body = await readBody(event)

  try {
    return await $fetch(`${config.apiBase.replace(/\/$/, '')}/${path}`, {
      method: 'POST',
      body,
      headers: { Accept: 'application/json' },
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
