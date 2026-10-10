import { $fetch as ofetch } from 'ofetch'

export default defineEventHandler(async (event) => {
  setHeader(event, 'Cache-Control', 'private, no-store')
  const config = useRuntimeConfig(event)
  const authorization = getHeader(event, 'authorization')
  try {
    return await ofetch(`${config.apiBase.replace(/\/$/, '')}/admin/user`, {
      headers: { Accept: 'application/json', ...(authorization ? { Authorization: authorization } : {}) },
      query: getQuery(event),
      retry: 0,
      timeout: 15_000,
    })
  } catch (error: unknown) {
    const failure = error as { statusCode?: number; data?: { message?: string } }
    const statusCode = failure.statusCode ?? 502
    throw createError({ statusCode, message: statusCode >= 500 ? 'The server is unavailable. Please try again.' : failure.data?.message ?? 'Your request could not be processed.' })
  }
})
