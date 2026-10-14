import { $fetch as ofetch } from 'ofetch'

export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig(event)
  const authorization = getHeader(event, 'authorization')

  try {
    return await ofetch(`${config.apiBase.replace(/\/$/, '')}/user`, {
      headers: {
        Accept: 'application/json',
        ...(authorization ? { Authorization: authorization } : {}),
      },
      timeout: 15_000,
      retry: 0,
    })
  } catch (error: unknown) {
    const failure = error as { statusCode?: number; response?: { status?: number }; data?: { message?: string } }
    const status = failure.statusCode ?? failure.response?.status ?? 502
    throw createError({ statusCode: status, message: status >= 500 ? 'Your session could not be checked. Please try again.' : failure.data?.message ?? 'Your session is no longer available. Please sign in again.' })
  }
})
