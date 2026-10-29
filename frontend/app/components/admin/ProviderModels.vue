<script setup lang="ts">
import type { AIModelDefinition, AIProviderDefinition } from '~/types/aiProvider'
const models = defineModel<AIModelDefinition[]>({ required: true })
const props = defineProps<{ definition: AIProviderDefinition; activity: string; disabled: boolean }>()
const newId = ref('')
const choices = computed(() => props.definition.allowed_model_ids.filter(id => !models.value.some(model => model.id === id)))
const canAdd = computed(() => newId.value.trim() !== '' && !models.value.some(model => model.id === newId.value.trim()) && models.value.length < 30)
function add() {
  if (!canAdd.value || props.disabled) return
  const id = newId.value.trim()
  models.value = [...models.value, { id, label: id, enabled: true, languages: [], capabilities: { speakers: false, timestamps: props.activity === 'subtitles' },
    pricing: { unit: ['translation', 'cleanup', 'summary'].includes(props.activity) ? '1000_characters' : 'minute', credits: null, provider_cost: null, provider_currency: 'USD' } }]
  newId.value = ''
}
</script>
<template>
  <div class="space-y-3 border-t border-[var(--line)] pt-4">
    <h3 class="text-sm font-semibold">Model catalog</h3>
    <p class="text-xs leading-5 text-[var(--muted)]">Changes apply when you save settings. Empty credit prices make a model unavailable for paid requests. Language codes describe supported languages; an empty list adds no restriction.</p>
    <p v-if="!definition.custom_models" class="text-xs leading-5 text-[var(--muted)]">This integration accepts only the listed model choices. Providers without model selection use their provider default.</p>
    <AdminProviderModelEditor v-for="(entry, index) in models" :key="index" v-model="models[index]!" :activity="activity" :disabled="disabled" />
    <div v-if="!disabled && (definition.custom_models || choices.length)" class="flex flex-wrap items-end gap-2">
      <label class="min-w-40 flex-1 text-xs font-medium">Add model
        <input v-if="definition.custom_models" v-model="newId" type="text" maxlength="120" placeholder="Exact API model ID" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3" @keydown.enter.prevent="add">
        <select v-else v-model="newId" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"><option value="" disabled>Choose model</option><option v-for="id in choices" :key="id" :value="id">{{ id }}</option></select>
      </label>
      <button type="button" class="button-secondary" :disabled="!canAdd" @click="add">Add model</button>
    </div>
    <p v-if="definition.custom_models" class="text-xs leading-5 text-[var(--muted)]">Use a model compatible with the existing provider API. Adding its ID does not add support for a different API or response format.</p>
  </div>
</template>
