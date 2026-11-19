import { sitemapXml } from '../../shared/utils/siteSeo'

import { cachedMarketingFeatures } from '../utils/marketingFeatures'

export default defineEventHandler(async (event) => {
  setResponseHeader(event, 'Content-Type', 'application/xml; charset=utf-8')
  const features = await cachedMarketingFeatures(useRuntimeConfig(event).apiBase)
  return sitemapXml(features)
})
