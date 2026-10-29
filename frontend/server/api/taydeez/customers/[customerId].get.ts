import { $fetch as ofetch } from 'ofetch'
export default defineEventHandler(async (event) => {
  setHeader(event, 'Cache-Control', 'private, no-store')
  const id = getRouterParam(event, 'customerId')
  if (!id || !/^\d+$/.test(id)) throw createError({ statusCode: 404, message: 'Customer not found.' })
  try {
    return await ofetch(`${useRuntimeConfig(event).apiBase.replace(/\/$/, '')}/admin/customers/${id}`, {
      headers: { Accept: 'application/json', Authorization: getHeader(event, 'authorization') ?? '' }, retry: 0,
    })
  } catch (error: unknown) {
    const failure = error as { statusCode?: number; data?: { message?: string } }
    throw createError({ statusCode: failure.statusCode ?? 502, message: (failure.statusCode ?? 502) >= 500 ? 'The server is unavailable.' : failure.data?.message ?? 'Customer not found.' })
  }
})
