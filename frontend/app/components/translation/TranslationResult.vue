<script setup lang="ts">
import type { TranslationRecord } from '~/types/translation'
import type { TranscriptSegment, TranscriptionExportVariant } from '~/types/transcription'
const props = defineProps<{ record: TranslationRecord | null; busy: boolean; loading: boolean }>()
const emit = defineEmits<{ save: [text: string, segments: TranscriptSegment[] | null] }>()
const text = ref('')
const segments = ref<TranscriptSegment[]>([])
const variant = ref<TranscriptionExportVariant>('plain')
watch(() => `${props.record?.id}:${props.record?.exportRevision}:${props.record?.translatedText !== null}`, () => { text.value = props.record?.translatedText ?? ''; segments.value = (props.record?.segments ?? []).map(item => ({ ...item })) }, { immediate: true })
watch(() => props.record?.id, () => { variant.value = 'plain' })
const editedText = computed(() => segments.value.length ? segments.value.map(item => item.text.trim()).join('\n') : text.value.trim())
const dirty = computed(() => editedText.value !== props.record?.translatedText || JSON.stringify(segments.value.map(item => ({ text: item.text.trim(), speaker: item.speaker }))) !== JSON.stringify((props.record?.segments ?? []).map(item => ({ text: item.text.trim(), speaker: item.speaker }))))
const working = computed(() => Boolean(props.record && ['pending', 'processing'].includes(props.record.status)))
const hasSpeakers = computed(() => props.record?.segments.some(item => item.speaker?.trim()) ?? false)
const downloads = computed(() => (['pdf', 'txt', 'docx'] as const).map(format => ({ format, item: props.record?.exports.find(item => item.format === format && item.variant === (hasSpeakers.value ? variant.value : 'plain')) })))
</script>
<template>
  <section class="surface p-6 sm:p-8" aria-live="polite" :aria-busy="loading || working">
    <h2 class="text-xl font-semibold">{{ record?.name ?? 'Your translated text' }}</h2>
    <p v-if="loading" class="mt-5 text-sm text-slate-500">Loading translation…</p>
    <div v-else-if="!record" class="mt-5 grid min-h-64 place-items-center rounded-xl border border-dashed border-slate-200 p-6 text-center text-sm text-slate-500">Your translation will appear here. You can also open a saved translation below.</div>
    <template v-else>
      <p v-if="working" class="mt-4 flex items-center gap-3 text-sm text-indigo-600"><span class="size-4 rounded-full border-2 border-indigo-200 border-t-indigo-600 motion-safe:animate-spin" aria-hidden="true" />{{ record.translatedText === null ? 'Translating your text…' : 'Preparing your downloads…' }}</p>
      <p v-if="record.failureReason" class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700" role="alert">{{ record.failureReason }}</p>
      <details class="mt-5"><summary class="cursor-pointer text-sm font-medium text-slate-600">Original text</summary><p class="mt-3 max-h-60 overflow-y-auto whitespace-pre-wrap rounded-xl bg-slate-50 p-4 text-sm leading-relaxed">{{ record.sourceText }}</p></details>
      <template v-if="record.translatedText !== null">
        <div v-if="segments.length" class="mt-5 max-h-[32rem] space-y-4 overflow-y-auto pr-1"><div v-for="(segment, index) in segments" :key="index" class="rounded-xl border border-slate-200 p-3"><label :for="`translation-speaker-${index}`" class="text-xs text-slate-500">Speaker</label><input :id="`translation-speaker-${index}`" v-model="segment.speaker" :disabled="busy" maxlength="100" class="mb-2 block w-full border-b border-slate-200 py-2 text-sm font-semibold" placeholder="Unknown speaker"><label :for="`translation-segment-${index}`" class="sr-only">Translated segment {{ index + 1 }}</label><textarea :id="`translation-segment-${index}`" v-model="segment.text" :disabled="busy" rows="3" class="w-full resize-y rounded-lg bg-slate-50 p-2 text-base leading-relaxed" /></div></div>
        <template v-else><label for="translated-result" class="mt-5 block text-sm font-medium">Translated text</label><textarea id="translated-result" v-model="text" :disabled="busy" rows="10" class="mt-2 w-full rounded-xl border border-slate-200 bg-slate-50 p-4 text-base leading-relaxed" /></template>
        <button type="button" class="button-secondary mt-4 disabled:opacity-50" :disabled="busy || !editedText || (!dirty && record.status !== 'failed')" @click="emit('save', editedText, segments.length ? segments : null)">{{ busy ? 'Saving…' : !dirty && record.status === 'failed' ? 'Retry downloads' : 'Save changes' }}</button><p class="mt-2 text-xs text-slate-500">Editing regenerates your files without translating or charging again.</p>
      </template>
      <div v-if="record.translatedText !== null" class="mt-6 border-t border-slate-100 pt-5"><label v-if="hasSpeakers" for="translation-download-style" class="block text-sm font-medium">Download style<select id="translation-download-style" v-model="variant" class="mt-2 block w-full rounded-xl border border-slate-200 bg-white p-3 text-sm"><option value="plain">Plain text</option><option value="speakers">With speaker labels</option></select></label><div class="mt-4 grid grid-cols-3 gap-2"><template v-for="download in downloads" :key="download.format"><a v-if="download.item?.status === 'completed' && download.item.downloadUrl" :href="download.item.downloadUrl" target="_blank" rel="noopener" class="rounded-xl border border-indigo-100 bg-indigo-50 p-3 text-center text-sm font-semibold uppercase text-indigo-700">{{ download.format }} ↓</a><button v-else type="button" disabled class="flex items-center justify-center gap-2 rounded-xl border bg-slate-50 p-3 text-sm uppercase text-slate-400"><span v-if="working" class="size-3 rounded-full border-2 border-indigo-200 border-t-indigo-600 motion-safe:animate-spin" aria-hidden="true" />{{ download.format }}</button></template></div></div>
    </template>
  </section>
</template>
