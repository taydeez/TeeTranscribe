import { $fetch as ofetch } from 'ofetch'

export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig(event)
  const authorization = getHeader(event, 'authorization')
  const id = getRouterParam(event, 'id')
  try {
    return await ofetch(`${config.apiBase.replace(/\/$/, '')}/transcriptions/${encodeURIComponent(id ?? '')}/exports`, {
      method: 'POST',
      headers: { Accept: 'application/json', ...(authorization ? { Authorization: authorization } : {}) },
      retry: 0,
    })
  } catch (error: unknown) {
    const failure = error as { statusCode?: number; data?: { message?: string } }
    throw createError({ statusCode: failure.statusCode ?? 502, message: failure.data?.message ?? 'Downloads could not be prepared.' })
  }
})
