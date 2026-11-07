import type { FeatureFlags } from '~/types/features'

export function hasDubbing(features: FeatureFlags | null): boolean {
  return !!features && (features.audio_dubbing || features.video_dubbing || features.subtitles)
}

export function featureRouteAvailable(path: string, features: FeatureFlags | null, query: Record<string, unknown> = {}): boolean {
  const saved = (id: unknown) => typeof id === 'string' && /^[0-9A-HJKMNP-TV-Z]{26}$/i.test(id)
  if (/^\/dashboard\/translations(?:\/|$)/.test(path)) return features?.translation === true || saved(query.translation)
  if (/^\/dashboard\/dubbing(?:\/|$)/.test(path)) return hasDubbing(features) || saved(query.dubbing)
  return true
}

export function dubbingSelection(features: FeatureFlags | null, media: 'audio' | 'video', operation: 'dubbing' | 'subtitles') {
  if (!hasDubbing(features) || !features) return null
  if (media === 'audio' && features.audio_dubbing) return { media: 'audio' as const, operation: 'dubbing' as const }
  if (features.video_dubbing || features.subtitles) {
    return { media: 'video' as const, operation: operation === 'subtitles' && features.subtitles || !features.video_dubbing ? 'subtitles' as const : 'dubbing' as const }
  }
  return { media: 'audio' as const, operation: 'dubbing' as const }
}
