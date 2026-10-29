import type { FolderTranscription, TranscriptSegment } from '~/types/transcription'
import type { TranscriptToolResult } from '~/types/transcriptTools'

export function useTranscriptDraft(transcription: () => FolderTranscription | null) {
  const view = shallowRef<'plain' | 'speakers'>('plain')
  const draft = shallowRef('')
  const segments = ref<TranscriptSegment[]>([])
  const segmentText = computed(() => segments.value.map(segment => segment.text.trim()).join('\n'))
  const text = computed(() => view.value === 'speakers' ? segmentText.value : draft.value.trim())
  const aligned = computed(() => segments.value.length > 0 && text.value === segmentText.value)
  const sourceSegments = computed(() => aligned.value ? segments.value : [])

  function reset(record: FolderTranscription | null) {
    segments.value = (record?.segments ?? []).map(segment => ({ ...segment }))
    draft.value = segments.value.length ? segmentText.value : record?.transcript ?? ''
    if (!segments.value.length) view.value = 'plain'
  }
  function selectView(value: 'plain' | 'speakers') {
    if (value === view.value || (value === 'speakers' && !aligned.value)) return
    if (value === 'plain') draft.value = text.value
    view.value = value
  }
  function applyCleanup(result: TranscriptToolResult) {
    if (!result.text?.trim()) return
    if (aligned.value && result.segments?.length === segments.value.length) {
      segments.value = segments.value.map((segment, index) => ({ ...segment, text: result.segments![index]!.text }))
    } else {
      view.value = 'plain'
    }
    draft.value = result.text
  }
  watch(() => transcription()?.id, () => { view.value = 'plain'; reset(transcription()) }, { immediate: true })
  return { view, draft, segments, text, aligned, sourceSegments, selectView, reset, applyCleanup }
}
