import { featureRouteAvailable } from '~/utils/features'

export default defineNuxtRouteMiddleware(async (to) => {
  if (to.path.startsWith('/taydeez') || (!to.path.startsWith('/dashboard') && to.path !== '/')) return
  const features = useFeatures()
  await features.refresh()
  if (!featureRouteAvailable(to.path, features.flags.value, to.query)) return navigateTo('/dashboard')
})
