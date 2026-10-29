export const concurrencyLimits = [
  { key: 'overall', label: 'All activities combined' },
  { key: 'transcription', label: 'Transcription' },
  { key: 'translation', label: 'Translation' },
  { key: 'dubbing', label: 'Dubbing & subtitles combined' },
  { key: 'transcript_tools', label: 'Summaries & cleanup combined' },
] as const
