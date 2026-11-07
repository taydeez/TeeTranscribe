import { $fetch as ofetch } from 'ofetch'
import type { FeatureFlags } from '../../app/types/features'

export default defineEventHandler(async (event) => {
  setHeader(event, 'Cache-Control', 'no-store')
  const config = useRuntimeConfig(event)
  try {
    return await ofetch<{ data: FeatureFlags }>(`${config.apiBase.replace(/\/$/, '')}/features`, {
      headers: { Accept: 'application/json' }, timeout: 10_000, retry: 0,
    })
  } catch {
    throw createError({ statusCode: 503, message: 'Available features could not be loaded.' })
  }
})
