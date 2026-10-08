import type { FolderTranscription, TranscriptSegment, TranscriptionExportVariant } from '~/types/transcription'

export function hasTranscriptChanges(record: FolderTranscription | null, text: string, segments: TranscriptSegment[] | null) {
  if (!record) return false
  if (text !== (record.transcript ?? '').trim()) return true
  const editable = (items: TranscriptSegment[]) => items.map(item => ({ text: item.text.trim(), speaker: item.speaker }))
  return segments !== null && JSON.stringify(editable(segments)) !== JSON.stringify(editable(record.segments ?? []))
}

export function exportDownloads(record: FolderTranscription, variant: TranscriptionExportVariant = 'plain') {
  return (['pdf', 'txt', 'docx'] as const).map(format => {
    const item = record.exports.find(entry => entry.format === format && (entry.variant ?? 'plain') === variant)
    return {
      format, item,
      ready: item?.status === 'completed' && Boolean(item.downloadUrl),
      working: item?.status === 'pending' || (!item && record.status === 'processing'),
    }
  })
}
