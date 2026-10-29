<script setup lang="ts">
import type { TranslationLanguage } from '~/types/translation'
defineProps<{ languages: TranslationLanguage[]; id: string; label: string; auto?: boolean; disabled?: boolean }>()
const value = defineModel<string>({ required: true })
</script>
<template>
  <label :for="id" class="block text-sm font-medium text-slate-700">{{ label }}
    <select :id="id" v-model="value" :disabled="disabled" class="mt-2 min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm disabled:opacity-60">
      <option v-if="auto" value="">Auto-detect</option>
      <optgroup v-if="languages.some(item => item.nigerian)" label="Nigerian languages"><option v-for="language in languages.filter(item => item.nigerian)" :key="language.code" :value="language.code">{{ language.name }}</option></optgroup>
      <optgroup label="All other languages"><option v-for="language in languages.filter(item => !item.nigerian)" :key="language.code" :value="language.code">{{ language.name }}</option></optgroup>
    </select>
  </label>
</template>
