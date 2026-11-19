import { $fetch as ofetch } from 'ofetch'
import type { FeatureFlags } from '../../app/types/features'

export const cachedMarketingFeatures = defineCachedFunction(async (apiBase: string) => {
  const response = await ofetch<{ data: FeatureFlags }>(`${apiBase.replace(/\/$/, '')}/features`, { timeout: 2000, retry: 0 })
  return response.data
}, { name: 'marketing-features', maxAge: 60, staleMaxAge: 86400, swr: true, getKey: apiBase => apiBase })
