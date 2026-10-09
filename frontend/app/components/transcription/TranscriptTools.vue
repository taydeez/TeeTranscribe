<script setup lang="ts">
import type { FolderTranscription } from '~/types/transcription'
import type { TranscriptToolResult } from '~/types/transcriptTools'
import { formatCredits } from '~/utils/credits'
import TranscriptToolPreview from './TranscriptToolPreview.vue'

const props = defineProps<{ transcription: FolderTranscription; blocked: boolean }>()
const emit = defineEmits<{ apply: [result: TranscriptToolResult] }>()
const tools = useTranscriptTools(() => props.transcription, () => props.blocked)
const { operation, loading, configured, busy, error, record, working, quote, fresh, canApply } = tools
const reusable = computed(() => fresh.value && record.value?.status === 'complete')
function apply() { if (canApply.value && record.value?.result) emit('apply', record.value.result) }
</script>

<template>
  <section class="mt-7 rounded-2xl border border-indigo-100 bg-indigo-50/50 p-4 sm:p-5" :aria-busy="loading || busy || working" aria-labelledby="transcript-tools-heading">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div><h3 id="transcript-tools-heading" class="text-base font-semibold text-slate-900">Make more of your transcript</h3><p class="mt-1 text-sm leading-relaxed text-slate-500">Clean up punctuation and wording, or extract a summary and action items.</p></div>
    </div>
    <div class="mt-4 grid grid-cols-2 gap-2" role="group" aria-label="Transcript tools">
      <button type="button" :disabled="busy || loading" :aria-pressed="operation === 'cleanup'" class="min-h-11 rounded-xl border px-4 py-3 text-sm font-semibold disabled:opacity-50" :class="operation === 'cleanup' ? 'border-indigo-200 bg-white text-indigo-700 shadow-sm' : 'border-transparent text-slate-500 hover:bg-white/70'" @click="tools.select('cleanup')">Clean up text</button>
      <button type="button" :disabled="busy || loading" :aria-pressed="operation === 'summary'" class="min-h-11 rounded-xl border px-4 py-3 text-sm font-semibold disabled:opacity-50" :class="operation === 'summary' ? 'border-indigo-200 bg-white text-indigo-700 shadow-sm' : 'border-transparent text-slate-500 hover:bg-white/70'" @click="tools.select('summary')">Summarize</button>
    </div>
    <p v-if="loading" class="mt-4 text-sm text-slate-500" role="status">Loading saved results…</p>
    <p v-else-if="!configured && !error" class="mt-4 text-sm text-slate-500">Transcript tools are not available yet.</p>
    <p v-if="error" class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700" role="alert">{{ error }} <button v-if="!configured" type="button" class="underline" @click="tools.load">Retry</button></p>
    <template v-if="!loading && (configured || record)">
      <p v-if="blocked" class="mt-4 text-sm text-amber-700">Save your changes first. These tools use your saved transcript.</p>
      <p v-if="working" class="mt-4 flex items-center gap-2 text-sm text-indigo-700" role="status"><span class="size-4 rounded-full border-2 border-indigo-200 border-t-indigo-600 motion-safe:animate-spin" aria-hidden="true" />{{ operation === 'cleanup' ? 'Preparing your cleanup preview…' : 'Creating your summary…' }} You can leave this page and return later.</p>
      <p v-else-if="record?.status === 'failed'" class="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700" role="alert">{{ record.failureReason ?? 'This request could not be completed.' }}</p>
      <TranscriptToolPreview v-if="record?.status === 'complete' && record.result" :record="record" :fresh="fresh" :can-apply="canApply" :blocked="blocked" @apply="apply" />
      <p v-if="reusable" class="mt-3 text-xs text-slate-500">Your saved result is shown above. Viewing it again does not use credits.</p>
      <template v-if="configured && !working && !reusable">
        <div v-if="quote" class="mt-4 rounded-xl border border-indigo-200 bg-white p-4 text-sm">
          <p>{{ operation === 'cleanup' ? 'Cleaning up' : 'Summarizing' }} {{ quote.quantity.toLocaleString() }} characters costs <strong>{{ formatCredits(quote.credit_units) }} credits</strong>.</p>
          <p v-if="!quote.enough_credits" class="mt-2 text-rose-600">You need more credits. <NuxtLink to="/dashboard/billing" class="underline">Add credits</NuxtLink></p>
          <div class="mt-4 flex flex-wrap gap-3"><button type="button" class="button-primary w-full disabled:opacity-50 sm:w-auto" :disabled="busy || blocked || !quote.enough_credits" @click="tools.confirm">{{ busy ? 'Submitting…' : `Confirm · ${formatCredits(quote.credit_units)} credits` }}</button><button type="button" class="button-secondary w-full disabled:opacity-50 sm:w-auto" :disabled="busy" @click="tools.cancelQuote()">Cancel</button></div>
        </div>
        <button v-else type="button" class="button-secondary mt-4 w-full disabled:opacity-50 sm:w-auto" :disabled="busy || blocked" @click="tools.checkPrice">{{ busy ? 'Checking price…' : operation === 'cleanup' ? 'Check cleanup price' : 'Check summary price' }}</button>
      </template>
    </template>
  </section>
</template>
