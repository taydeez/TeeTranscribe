import { productPages } from '#shared/config/productPages'

export default defineNuxtRouteMiddleware(async (to) => {
  if (to.path.startsWith('/dashboard') || to.path.startsWith('/taydeez') || to.path.startsWith('/auth') || to.path.startsWith('/payment')) return
  const features = useMarketingFeatures()
  await features.refresh()
  const product = productPages[to.path.replace(/\/$/, '')]
  if (product && features.flags.value?.[product.feature] === false) throw createError({ statusCode: 404, message: 'Page not found' })
  if (product && !features.flags.value) throw createError({ statusCode: 503, message: 'This page is temporarily unavailable. Please try again.' })
})
