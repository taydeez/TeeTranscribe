import { $fetch as ofetch } from 'ofetch'
import type { PublicPricingCatalog } from '../../../app/types/publicPricing'

const pricing = defineCachedFunction(async (apiBase: string) => {
  return await ofetch<{ data: PublicPricingCatalog }>(`${apiBase.replace(/\/$/, '')}/public/pricing`, { timeout: 3000, retry: 0 })
}, { name: 'public-pricing', maxAge: 60, staleMaxAge: 300, swr: true, getKey: apiBase => apiBase })

export default defineEventHandler(async (event) => {
  try {
    return await pricing(useRuntimeConfig(event).apiBase)
  } catch {
    throw createError({ statusCode: 503, message: 'Current prices are temporarily unavailable. Please try again.' })
  }
})
