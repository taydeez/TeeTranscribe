export type Feature = 'transcription' | 'translation' | 'audio_dubbing' | 'video_dubbing' | 'subtitles'
export type FeatureFlags = Record<Feature, boolean>
