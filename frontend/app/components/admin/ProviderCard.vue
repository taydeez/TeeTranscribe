<script setup lang="ts">
import type { AIProviderSettings, AIProviderDefinition, AIModelDefinition } from '~/types/aiProvider'
const settings = defineModel<AIProviderSettings>({ required: true })
defineProps<{ definition: AIProviderDefinition; models: AIModelDefinition[]; disabled: boolean }>()
const languages = ref(settings.value.languages.join(', '))
watch(languages, value => { settings.value.languages = [...new Set(value.split(',').map(code => code.trim().toLowerCase()).filter(Boolean))] }, { flush: 'sync' })
watch(() => settings.value, value => { languages.value = value.languages.join(', ') })
</script>
<template>
  <fieldset class="surface min-w-0 space-y-4 p-6" :disabled="disabled">
    <legend class="sr-only">{{ definition.name }} settings</legend>
    <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="font-semibold">{{ definition.name }}</h2><span class="rounded-full px-3 py-1 text-xs font-medium" :class="definition.configured ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'">{{ definition.configured ? 'Configured' : 'Missing credentials' }}</span></div>
    <label class="flex items-center gap-3 text-sm"><input v-model="settings.enabled" type="checkbox" class="accent-indigo-600">Enabled for this activity</label>
    <label class="block text-sm font-medium">Default model<select v-model="settings.model" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"><option v-for="model in models" :key="model.id" :value="model.id">{{ model.label }}{{ model.enabled ? '' : ' (disabled)' }}</option></select></label>
    <slot />
    <label class="block text-sm font-medium">Restrict languages<input v-model="languages" type="text" placeholder="e.g. en, fr, es — empty allows supported languages" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"></label>
    <p v-if="definition.languages.length" class="text-xs leading-5 text-[var(--muted)]">Supported codes: {{ definition.languages.join(', ') }}</p>
    <ul class="flex flex-wrap gap-2"><li v-for="capability in definition.capabilities" :key="capability" class="rounded-lg bg-[var(--page)] px-2 py-1 text-xs text-[var(--muted)]">{{ capability }}</li></ul>
  </fieldset>
</template>
