<script setup lang="ts">
import type { TranslationLanguage, TranslationQuote } from '~/types/translation'
import { formatCredits } from '~/utils/credits'
import TranslationLanguageSelect from './TranslationLanguageSelect.vue'
defineProps<{ languages: TranslationLanguage[]; loading: boolean; busy: boolean; quote: TranslationQuote | null; sourceName: string }>()
const emit = defineEmits<{ price: []; submit: []; retry: [] }>()
const text = defineModel<string>('text', { required: true })
const name = defineModel<string>('name', { required: true })
const source = defineModel<string>('source', { required: true })
const target = defineModel<string>('target', { required: true })
const characters = computed(() => Array.from(text.value).length)
</script>
<template>
  <section class="surface p-6 sm:p-8">
    <div class="flex items-center gap-3"><span class="feature-icon" data-accent="violet"><UiAppIcon name="translate" /></span><h2 class="text-xl font-semibold">Create a translation</h2></div>
    <p v-if="sourceName" class="mt-4 text-sm text-slate-500">From {{ sourceName }}</p>
    <label for="translation-name" class="mt-6 block text-sm font-medium">Translation name <span class="font-normal text-slate-400">· Optional</span></label><input id="translation-name" v-model="name" :disabled="busy" maxlength="255" class="mt-2 w-full rounded-xl border border-slate-200 bg-white p-3 text-sm" placeholder="Name this translation">
    <div class="mt-5 flex items-center justify-between gap-3"><label for="translation-text" class="text-sm font-medium">Source text</label><span class="text-xs text-slate-400">{{ characters.toLocaleString() }} / 50,000</span></div>
    <textarea id="translation-text" v-model="text" :disabled="busy" maxlength="50000" rows="8" class="mt-2 w-full rounded-xl border border-slate-200 bg-slate-50 p-4 text-base leading-relaxed" placeholder="Paste text or transfer a transcript from your library…" />
    <NuxtLink class="mt-3 inline-flex items-center gap-2 text-sm text-indigo-600" to="/dashboard/transcriptions"><UiAppIcon name="folder" :size="16" />Find a transcript</NuxtLink>
    <p v-if="loading" class="mt-5 text-sm text-slate-500" role="status">Loading supported languages…</p>
    <div v-else-if="languages.length" class="mt-6 grid gap-5 sm:grid-cols-2"><TranslationLanguageSelect id="translation-source-language" v-model="source" label="Source language" :languages="languages" :disabled="busy" auto /><TranslationLanguageSelect id="translation-target-language" v-model="target" label="Output language" :languages="languages" :disabled="busy" /></div>
    <button v-else type="button" class="mt-5 text-sm text-indigo-600 underline" :disabled="busy" @click="emit('retry')">Retry loading languages</button>
    <div v-if="quote" class="mt-6 rounded-xl border border-indigo-100 bg-indigo-50 p-4 text-sm"><p>This translation contains {{ quote.quantity.toLocaleString() }} characters and costs <strong>{{ formatCredits(quote.credit_units) }} credits</strong>.</p><p v-if="!quote.enough_credits" class="mt-2 text-rose-600">You need more credits. <NuxtLink to="/dashboard/billing" class="underline">Add credits</NuxtLink></p><button type="button" class="button-primary mt-4 w-full disabled:opacity-50" :disabled="busy || !quote.enough_credits" @click="emit('submit')">{{ busy ? 'Submitting…' : `Confirm · ${formatCredits(quote.credit_units)} credits` }}</button></div>
    <button v-else type="button" class="button-primary mt-6 w-full disabled:opacity-50" :disabled="busy || !text.trim() || characters > 50000 || !languages.length || source === target" @click="emit('price')">{{ busy ? 'Checking price…' : 'Check translation price' }}</button>
  </section>
</template>
