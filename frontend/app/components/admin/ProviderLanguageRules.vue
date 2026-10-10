<script setup lang="ts">
import type { AIProviderDefinition, AIModelDefinition, AILanguageRule } from '~/types/aiProvider'
const rules = defineModel<Record<string, AILanguageRule>>({ required: true })
const props = defineProps<{ catalog: Record<string, AIProviderDefinition>; models: Record<string, AIModelDefinition[]>; disabled: boolean; activity: string }>()
const language = ref('')
const provider = ref('')
const model = ref('')
const choices = computed(() => (props.models[provider.value] ?? []).filter(entry => entry.enabled))
watch(provider, () => { model.value = '' })
function add() {
  const code = language.value.trim().toLowerCase()
  if (!code || !provider.value || !model.value) return
  rules.value = { ...rules.value, [code]: { provider: provider.value, model: model.value } }
  language.value = ''
}
function remove(code: string) { rules.value = Object.fromEntries(Object.entries(rules.value).filter(([key]) => key !== code)) }
</script>
<template>
  <div class="surface space-y-4 p-6">
    <div><h2 class="font-semibold">Language rules</h2><p class="mt-2 text-xs leading-5 text-[var(--muted)]">{{ ['transcription', 'subtitles'].includes(activity) ? 'Match the spoken language.' : 'Match the output language.' }} Exact codes take priority over base codes, then the activity default applies.</p></div>
    <ul v-if="Object.keys(rules).length" class="divide-y divide-[var(--line)]"><li v-for="(value, code) in rules" :key="code" class="flex items-center gap-3 py-3 text-sm"><code class="min-w-20">{{ code }}</code><span class="min-w-0 flex-1 break-words">{{ catalog[typeof value === 'string' ? value : value.provider]?.name }} · {{ typeof value === 'string' ? 'Provider default model' : value.model }}</span><button type="button" class="icon-button hover:text-red-600" :disabled="disabled" :aria-label="'Remove rule for ' + code" @click="remove(code)"><UiAppIcon name="close" :size="16" /></button></li></ul>
    <p v-else class="text-sm text-[var(--muted)]">All languages use the activity default.</p>
    <div v-if="!disabled" class="flex flex-wrap items-end gap-3"><label class="min-w-32 flex-1 text-sm">Language code<input v-model="language" type="text" placeholder="e.g. yo or en-ng" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"></label><label class="min-w-40 flex-1 text-sm">Provider<select v-model="provider" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"><option value="" disabled>Select provider</option><option v-for="(item, key) in catalog" :key="key" :value="key">{{ item.name }}</option></select></label><label class="min-w-40 flex-1 text-sm">Model<select v-model="model" class="mt-2 w-full rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3"><option value="" disabled>Select model</option><option v-for="entry in choices" :key="entry.id" :value="entry.id">{{ entry.label }}</option></select></label><button type="button" class="button-secondary" :disabled="!language.trim() || !provider || !model" @click="add">Add rule</button></div>
  </div>
</template>
