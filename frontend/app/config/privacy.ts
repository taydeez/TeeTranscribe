import type { PrivacyCategory, RetentionPolicy } from '~/types/privacy'

export const PRIVACY_CATEGORIES: { key: PrivacyCategory; label: string; group: string }[] = [
  { key: 'source_audio', label: 'Uploaded audio', group: 'Source files' },
  { key: 'source_video', label: 'Uploaded video', group: 'Source files' },
  { key: 'recordings', label: 'In-app recordings', group: 'Source files' },
  { key: 'dubbed_audio', label: 'Dubbed audio', group: 'Generated files' },
  { key: 'dubbed_video', label: 'Dubbed videos', group: 'Generated files' },
  { key: 'pdf', label: 'PDF downloads', group: 'Generated files' },
  { key: 'txt', label: 'TXT downloads', group: 'Generated files' },
  { key: 'docx', label: 'Word downloads', group: 'Generated files' },
  { key: 'subtitles', label: 'Subtitle files', group: 'Generated files' },
  { key: 'transcripts', label: 'Saved transcripts and notes', group: 'Saved text' },
  { key: 'translations', label: 'Saved translations', group: 'Saved text' },
]
export const defaultRetention = () => Object.fromEntries(PRIVACY_CATEGORIES.map(item => [item.key, null])) as RetentionPolicy
export const privacyCategoryLabel = (category: string | null) => PRIVACY_CATEGORIES.find(item => item.key === category)?.label ?? 'All project data'
