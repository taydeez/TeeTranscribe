import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import type { TranscriptSegment } from '~/types/transcription'

export const useTranslationDraftStore = defineStore('translationDraft', () => {
  const auth = useAuthStore()
  const text = ref('')
  const sourceName = ref('')
  const transcriptionId = ref('')
  const segments = ref<TranscriptSegment[]>([])

  function clear() {
    text.value = ''; sourceName.value = ''; transcriptionId.value = ''; segments.value = []
  }

  function prepare(id: string, name: string, transcript: string, items: TranscriptSegment[] = []) {
    if (!auth.isAuthenticated) return
    transcriptionId.value = id; sourceName.value = name; text.value = transcript
    segments.value = items.map(item => ({ ...item }))
  }

  watch(() => auth.user?.id, clear, { flush: 'sync' })
  return { text, sourceName, transcriptionId, segments, prepare, clear }
})
