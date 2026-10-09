import { $fetch as ofetch } from 'ofetch'

export default defineEventHandler(async (event) => {
  const id = getRouterParam(event, 'id') ?? ''
  const path = getRouterParam(event, 'path') ?? ''
  const method = event.method
  const read = method === 'GET' && (path === '' || /^[0-9a-z]{26}$/i.test(path))
  const write = method === 'POST' && (path === '' || path === 'quotes')
  if (!/^[0-9a-z]{26}$/i.test(id) || (!read && !write)) throw createError({ statusCode: 404, message: 'Transcript tool endpoint not found.' })
  const config = useRuntimeConfig(event)
  const authorization = getHeader(event, 'authorization')
  try {
    return await ofetch(`${config.apiBase.replace(/\/$/, '')}/transcriptions/${id}/tools${path ? `/${path}` : ''}`, {
      method: method as 'GET' | 'POST',
      ...(method === 'POST' ? { body: await readBody(event) } : {}),
      headers: { Accept: 'application/json', ...(authorization ? { Authorization: authorization } : {}) },
      timeout: 60_000,
      retry: 0,
    })
  } catch (error: unknown) {
    const failure = error as { statusCode?: number; data?: { message?: string } }
    throw createError({ statusCode: failure.statusCode ?? 502, message: failure.data?.message ?? 'This transcript tool is temporarily unavailable.' })
  }
})
