import type { H3Event } from 'h3'
import { $fetch as ofetch } from 'ofetch'
import { clientIp } from './clientIp'
import { describeBackendFailure } from './backendFailure'

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
    const failure = describeBackendFailure(error, config.apiBase, path)
    if (failure.shouldLog) console.error('Backend request failed', failure.diagnostics)
    throw createError({
      statusCode: failure.status,
      message: failure.message,
    })
  }
}
