import type { Feature, FeatureFlags } from '~/types/features'
import { hasDubbing } from '~/utils/features'

export function useMarketingFeatures() {
  const flags = useState<FeatureFlags | null>('marketing-features', () => null)
  const checked = useState('marketing-features-checked', () => 0)
  const unavailable = useState('marketing-features-unavailable', () => false)
  const enabled = (feature: Feature) => flags.value?.[feature] === true
  const dubbing = computed(() => hasDubbing(flags.value))
  async function refresh() {
    if (Date.now() - checked.value < 60_000) return
    try {
      flags.value = (await $fetch<{ data: FeatureFlags }>('/api/marketing/features')).data
      unavailable.value = false
      checked.value = Date.now()
    } catch {
      unavailable.value = true
    }
  }
  return { flags, enabled, dubbing, unavailable, refresh }
}
