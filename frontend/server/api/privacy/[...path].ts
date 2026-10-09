import { $fetch as ofetch } from 'ofetch'

export default defineEventHandler(async (event) => {
  const path = getRouterParam(event, 'path') ?? ''
  const method = event.method
  const read = method === 'GET' && (['settings', 'files', 'deletions'].includes(path) || /^deletions\/[0-9a-z]{26}$/i.test(path))
  const write = method === 'POST' && (path === 'deletions' || /^deletions\/[0-9a-z]{26}\/retry$/i.test(path))
  const edit = method === 'PATCH' && path === 'settings'
  if (!read && !write && !edit) throw createError({ statusCode: 404, message: 'Privacy endpoint not found.' })
  const config = useRuntimeConfig(event)
  const authorization = getHeader(event, 'authorization')
  try {
    return await ofetch(`${config.apiBase.replace(/\/$/, '')}/privacy/${path}`, {
      method: method as 'GET' | 'POST' | 'PATCH',
      ...(method === 'GET' ? { query: getQuery(event) } : { body: await readBody(event) }),
      headers: { Accept: 'application/json', ...(authorization ? { Authorization: authorization } : {}) },
      timeout: 60_000,
      retry: 0,
    })
  } catch (error: unknown) {
    const failure = error as { statusCode?: number; data?: { message?: string } }
    throw createError({ statusCode: failure.statusCode ?? 502, message: failure.data?.message ?? 'Privacy settings are temporarily unavailable.' })
  }
})
