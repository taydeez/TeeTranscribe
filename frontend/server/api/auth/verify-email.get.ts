import { $fetch as ofetch } from 'ofetch'

export default defineEventHandler(async (event) => {
  const query = getQuery(event)
  const { id, hash, expires, signature } = query
  if (typeof id !== 'string' || !/^\d+$/.test(id) || typeof hash !== 'string' || !/^[a-f0-9]{40}$/.test(hash)
    || typeof expires !== 'string' || !/^\d+$/.test(expires) || typeof signature !== 'string' || !/^[a-f0-9]{64}$/.test(signature)) {
    throw createError({ statusCode: 400, message: 'This verification link is incomplete or invalid.' })
  }
  const config = useRuntimeConfig(event)
  try {
    return await ofetch(`${config.apiBase.replace(/\/$/, '')}/auth/email/verify/${id}/${hash}`, {
      query: { expires, signature }, headers: { Accept: 'application/json' }, retry: 0,
    })
  } catch (error: unknown) {
    const failure = error as { statusCode?: number; data?: { message?: string } }
    throw createError({ statusCode: failure.statusCode ?? 502, message: failure.statusCode === 403
      ? 'This verification link is invalid or expired. Sign in to request a new link.'
      : failure.data?.message ?? 'Email verification is unavailable. Please try again.' })
  }
})
