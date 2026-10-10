<script setup lang="ts">
import type { AIModelDefinition } from '~/types/aiProvider'
const model = defineModel<AIModelDefinition>({ required: true })
defineProps<{ activity: string; disabled: boolean }>()
const languages = ref(model.value.languages.join(', '))
watch(languages, value => { model.value.languages = [...new Set(value.split(',').map(code => code.trim().toLowerCase()).filter(Boolean))] }, { flush: 'sync' })
watch(() => model.value, value => { languages.value = value.languages.join(', ') })
</script>
<template>
  <details class="rounded-xl border border-[var(--line)] bg-[var(--page)] p-4">
    <summary class="cursor-pointer text-sm font-medium">{{ model.label }} <span class="font-normal text-[var(--muted)]">· {{ model.id }} · {{ model.enabled ? 'Enabled' : 'Disabled' }}</span></summary>
    <fieldset class="mt-4 space-y-4" :disabled="disabled">
      <legend class="sr-only">{{ model.id }} model settings</legend>
      <label class="block text-xs font-medium">Display name<input v-model="model.label" required maxlength="120" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"></label>
      <label class="flex items-center gap-2 text-sm"><input v-model="model.enabled" type="checkbox" class="accent-indigo-600">Available for new requests</label>
      <label class="block text-xs font-medium">Supported language codes<input v-model="languages" placeholder="e.g. en, fr, es" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"></label>
      <div v-if="['transcription', 'subtitles'].includes(activity)" class="space-y-2">
        <p class="text-xs font-medium">Declared output capabilities</p>
        <label v-if="activity === 'transcription'" class="flex items-center gap-2 text-sm"><input v-model="model.capabilities.speakers" type="checkbox" class="accent-indigo-600">Speaker labels</label>
        <label class="flex items-center gap-2 text-sm"><input v-model="model.capabilities.timestamps" type="checkbox" class="accent-indigo-600" :disabled="disabled || activity === 'subtitles'">Timestamps</label>
        <p class="text-xs leading-5 text-[var(--muted)]">Describe what the model returns. These flags do not turn on provider features.</p>
      </div>
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="text-xs font-medium">Billing unit<select v-model="model.pricing.unit" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"><template v-if="['translation', 'cleanup', 'summary'].includes(activity)"><option value="1000_characters">1,000 characters</option><option value="character">Character</option></template><option v-else value="minute">Minute</option></select></label>
        <label class="text-xs font-medium">Credits per unit<input :value="model.pricing.credits ?? ''" type="number" min="0.01" step="0.01" placeholder="Not priced" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3" @input="model.pricing.credits = ($event.target as HTMLInputElement).value || null"></label>
        <label class="text-xs font-medium">Provider cost per unit<input :value="model.pricing.provider_cost ?? ''" type="number" min="0" step="0.000001" placeholder="Optional" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3" @input="model.pricing.provider_cost = ($event.target as HTMLInputElement).value || null"></label>
        <label class="text-xs font-medium">Provider cost currency<select v-model="model.pricing.provider_currency" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"><option value="USD">USD</option><option value="NGN">NGN</option></select></label>
      </div>
    </fieldset>
  </details>
</template>
