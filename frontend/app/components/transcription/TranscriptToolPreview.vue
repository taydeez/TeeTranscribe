<script setup lang="ts">
import type { TranscriptTool } from '~/types/transcriptTools'

const props = defineProps<{ record: TranscriptTool; fresh: boolean; canApply: boolean; blocked: boolean }>()
const emit = defineEmits<{ apply: [] }>()
const copied = ref(false)
const copyError = ref('')
const content = computed(() => {
  const result = props.record.result
  if (props.record.operation === 'cleanup') return result?.text ?? ''
  return [result?.summary ?? '', ...(result?.keyPoints?.length ? ['\nKey points', ...result.keyPoints.map(point => `• ${point}`)] : []), ...(result?.actionItems?.length ? ['\nAction items', ...result.actionItems.map(item => `• ${item}`)] : [])].join('\n')
})
watch(() => props.record.id, () => { copied.value = false; copyError.value = '' })
async function copy() {
  copyError.value = ''
  try { await navigator.clipboard.writeText(content.value); copied.value = true }
  catch { copyError.value = 'Could not copy automatically. Select the text below to copy it.' }
}
</script>

<template>
  <div class="mt-5 rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h4 class="text-sm font-semibold">{{ record.operation === 'cleanup' ? 'Cleanup preview' : 'Summary' }}</h4>
      <button type="button" class="min-h-11 rounded-lg px-3 text-sm font-medium text-indigo-600 hover:bg-indigo-50" @click="copy">{{ copied ? 'Copied' : 'Copy text' }}</button>
    </div>
    <p v-if="!fresh" class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">This result is from an older version of this transcript. Generate a new result for the saved text.</p>
    <p v-if="copyError" class="mt-3 text-sm text-rose-600" role="alert">{{ copyError }}</p>
    <template v-if="record.operation === 'cleanup'">
      <div v-if="record.result?.segments?.length" class="mt-4 max-h-80 space-y-4 overflow-y-auto">
        <div v-for="(segment, index) in record.result.segments" :key="index">
          <p v-if="segment.speaker" class="text-xs font-semibold text-indigo-600">{{ segment.speaker }}</p>
          <p class="mt-1 whitespace-pre-wrap text-sm leading-relaxed text-slate-700">{{ segment.text }}</p>
        </div>
      </div>
      <p v-else class="mt-4 max-h-80 overflow-y-auto whitespace-pre-wrap text-sm leading-relaxed text-slate-700">{{ record.result?.text }}</p>
      <button type="button" class="button-secondary mt-5 w-full disabled:opacity-50 sm:w-auto" :disabled="!canApply" @click="emit('apply')">Use cleaned text</button>
      <p class="mt-2 text-xs leading-relaxed text-slate-500">{{ blocked ? 'Save or discard your current edits before applying this preview.' : 'Review the preview first. Applying it changes your editor draft; Save changes updates your transcript and downloads.' }}</p>
    </template>
    <template v-else>
      <div class="mt-4 max-h-96 space-y-5 overflow-y-auto text-sm leading-relaxed text-slate-700">
        <p class="whitespace-pre-wrap">{{ record.result?.summary }}</p>
        <div v-if="record.result?.keyPoints?.length"><h5 class="font-semibold text-slate-900">Key points</h5><ul class="mt-2 list-disc space-y-2 pl-5"><li v-for="(point, index) in record.result.keyPoints" :key="index">{{ point }}</li></ul></div>
        <div v-if="record.result?.actionItems?.length"><h5 class="font-semibold text-slate-900">Action items</h5><ul class="mt-2 list-disc space-y-2 pl-5"><li v-for="(item, index) in record.result.actionItems" :key="index">{{ item }}</li></ul></div>
      </div>
      <p class="mt-4 text-xs text-slate-500">Review generated notes for accuracy. This summary is saved alongside your transcript.</p>
    </template>
  </div>
</template>
