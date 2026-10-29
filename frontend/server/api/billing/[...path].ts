import { $fetch as ofetch } from 'ofetch'

export default defineEventHandler(async (event) => {
  const path = getRouterParam(event, 'path') ?? ''
  const method = event.method
  const allowed = method === 'GET'
    ? /^(balance|packages|payment-methods|history\/(payments|usage|ledger)|quotes\/[0-9A-HJKMNP-TV-Z]{26}|payments\/[0-9A-HJKMNP-TV-Z]{26}\/invoice)$/i.test(path)
    : method === 'POST' && /^(quotes|purchases|purchases\/[0-9A-HJKMNP-TV-Z]{26}\/checkout|payments\/verify)$/i.test(path)
  if (!allowed) throw createError({ statusCode: 404 })
  const config = useRuntimeConfig(event)
  const authorization = getHeader(event, 'authorization')
  try {
    return await ofetch(`${config.apiBase.replace(/\/$/, '')}/billing/${path}`, {
      method: method as 'GET' | 'POST', query: getQuery(event),
      ...(method === 'POST' ? { body: await readBody(event) } : {}),
      headers: { Accept: 'application/json', ...(authorization ? { Authorization: authorization } : {}) },
      timeout: 30_000, retry: 0,
    })
  } catch (error: unknown) {
    const failure = error as { statusCode?: number; data?: { message?: string } }
    throw createError({ statusCode: failure.statusCode ?? 502, message: failure.data?.message ?? 'Billing is unavailable. Please try again.' })
  }
})
