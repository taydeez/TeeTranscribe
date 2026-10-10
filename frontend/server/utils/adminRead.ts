import type { H3Event } from 'h3'
import { $fetch as ofetch } from 'ofetch'
export async function adminRead(event: H3Event, path: string) {
  setHeader(event, 'Cache-Control', 'private, no-store')
  try {
    return await ofetch(`${useRuntimeConfig(event).apiBase.replace(/\/$/, '')}/admin/${path}`, {
      headers: { Accept: 'application/json', Authorization: getHeader(event, 'authorization') ?? '' },
      query: getQuery(event), retry: 0, timeout: 15_000,
    })
  } catch (error: unknown) {
    const failure = error as { statusCode?: number; data?: { message?: string } }
    throw createError({ statusCode: failure.statusCode ?? 502, message: (failure.statusCode ?? 502) >= 500 ? 'The server is unavailable.' : failure.data?.message ?? 'Your request could not be processed.' })
  }
}
