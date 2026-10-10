import type { H3Event } from 'h3'
import { $fetch as ofetch } from 'ofetch'
import { clientIp } from './clientIp'

export async function forwardToBackend(event: H3Event, path: string, method: 'POST' | 'PATCH' | 'PUT' | 'DELETE' = 'POST') {
  const config = useRuntimeConfig(event)
  const body = await readBody(event)
  const authorization = getHeader(event, 'authorization')
  const signupIp = path === 'auth/register' ? clientIp(event) : undefined
  const adminSignIn = path === 'auth/admin/login' || path === 'auth/admin/verify'

  try {
    return await ofetch(`${config.apiBase.replace(/\/$/, '')}/${path}`, {
      method,
      body,
      headers: { Accept: 'application/json', ...(authorization ? { Authorization: authorization } : {}), ...(signupIp ? { 'X-Forwarded-For': signupIp } : {}) },
      timeout: adminSignIn ? 30_000 : 130_000,
      retry: 0,
    })
  } catch (error: unknown) {
    const failure = error as { statusCode?: number; cause?: { name?: string }; data?: { message?: string } }
    const timedOut = failure.cause?.name === 'TimeoutError' || failure.cause?.name === 'AbortError'
    const status = failure.statusCode ?? (timedOut ? 504 : 502)
    throw createError({
      statusCode: status,
      message: adminSignIn && status >= 500
        ? 'Administrator sign-in is temporarily unavailable. Please try again shortly.'
        : status >= 500
        ? 'The transcription server is unavailable. Please try again shortly.'
        : failure.data?.message ?? 'Your request could not be processed.',
    })
  }
}
