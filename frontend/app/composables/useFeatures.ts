import type { Feature, FeatureFlags } from '~/types/features'
import { hasDubbing } from '~/utils/features'

export function useFeatures() {
  const flags = useState<FeatureFlags | null>('feature-flags', () => null)
  const revision = useState('feature-flags-revision', () => 0)
  const error = useState('feature-flags-error', () => '')
  const enabled = (feature: Feature) => flags.value?.[feature] === true
  const dubbing = computed(() => hasDubbing(flags.value))
  async function refresh() {
    const current = ++revision.value
    try {
      const response = await $fetch<{ data: FeatureFlags }>('/api/features')
      if (current !== revision.value) return
      flags.value = response.data
      error.value = ''
    } catch {
      if (current !== revision.value) return
      flags.value = null
      error.value = 'Available features could not be loaded. Please try again.'
    }
  }
  return { flags, error, enabled, dubbing, refresh }
}
