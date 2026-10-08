import { $fetch as ofetch } from 'ofetch'

export default defineEventHandler(async (event) => {
  const path = getRouterParam(event, 'path') ?? ''
  const method = event.method
  const read = method === 'GET' && (path === '' || path === 'languages' || /^[0-9a-z]{26}$/i.test(path))
  const write = method === 'POST' && (path === '' || path === 'quotes')
  const edit = method === 'PATCH' && /^[0-9a-z]{26}$/i.test(path)
  if (!read && !write && !edit) throw createError({ statusCode: 404, message: 'Translation endpoint not found.' })
  const config = useRuntimeConfig(event)
  const authorization = getHeader(event, 'authorization')
  try {
    return await ofetch(`${config.apiBase.replace(/\/$/, '')}/translations${path ? `/${path}` : ''}`, {
      method: method as 'GET' | 'POST' | 'PATCH',
      ...(method === 'GET' ? { query: getQuery(event) } : { body: await readBody(event) }),
      headers: { Accept: 'application/json', ...(authorization ? { Authorization: authorization } : {}) },
      timeout: 60_000,
      retry: 0,
    })
  } catch (error: unknown) {
    const failure = error as { statusCode?: number; data?: { message?: string } }
    throw createError({ statusCode: failure.statusCode ?? 502, message: failure.data?.message ?? 'Translation is temporarily unavailable.' })
  }
})
