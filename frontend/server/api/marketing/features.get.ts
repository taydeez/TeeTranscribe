import { cachedMarketingFeatures } from '../../utils/marketingFeatures'

export default defineEventHandler(async (event) => {
  setHeader(event, 'Cache-Control', 'public, max-age=30, stale-while-revalidate=60')
  try {
    return { data: await cachedMarketingFeatures(useRuntimeConfig(event).apiBase) }
  } catch {
    throw createError({ statusCode: 503, message: 'Feature information is temporarily unavailable.' })
  }
})
