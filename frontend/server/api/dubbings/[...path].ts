import { $fetch as ofetch } from 'ofetch'
export default defineEventHandler(async event => {
  const path = getRouterParam(event, 'path') ?? ''
  const method = event.method
  const valid = method === 'GET' ? (path === '' || path === 'languages' || /^[0-9a-z]{26}$/i.test(path) || /^quotes\/[0-9a-z]{26}$/i.test(path))
    : method === 'POST' && (path === '' || path === 'quotes' || /^[0-9a-z]{26}\/retry$/i.test(path))
  if (!valid) throw createError({ statusCode: 404, message: 'Dubbing endpoint not found.' })
  const config = useRuntimeConfig(event)
  const authorization = getHeader(event, 'authorization')
  try {
    return await ofetch(`${config.apiBase.replace(/\/$/, '')}/dubbings${path ? `/${path}` : ''}`, {
      method: method as 'GET' | 'POST',
      ...(method === 'GET' ? { query: getQuery(event) } : { body: await readBody(event) }),
      headers: { Accept: 'application/json', ...(authorization ? { Authorization: authorization } : {}) },
      timeout: 60_000, retry: 0,
    })
  } catch (error: unknown) {
    const failure = error as { statusCode?: number; data?: { message?: string } }
    throw createError({ statusCode: failure.statusCode ?? 502, message: failure.data?.message ?? 'Dubbing is temporarily unavailable.' })
  }
})
