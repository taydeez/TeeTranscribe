<script setup lang="ts">
import type { PrivacyCategory } from '~/types/privacy'
defineProps<{ category: PrivacyCategory; label: string; disabled: boolean }>()
const hours = defineModel<number | null>({ required: true })
function mode(event: Event) { hours.value = (event.target as HTMLSelectElement).value === 'keep' ? null : 24 }
function duration(event: Event) { hours.value = Number((event.target as HTMLInputElement).value) }
</script>
<template>
  <div class="grid items-start gap-2 rounded-xl border border-slate-200 p-4 sm:grid-cols-[1fr_1.2fr] sm:gap-5">
    <label :for="`retention-${category}`" class="pt-2 text-sm font-medium">{{ label }}</label>
    <div><select :id="`retention-${category}`" :value="hours === null ? 'keep' : 'custom'" :disabled="disabled" class="min-h-11 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm" @change="mode"><option value="keep">Keep until I delete it</option><option value="custom">Delete automatically after…</option></select><label v-if="hours !== null" :for="`retention-hours-${category}`" class="mt-3 flex items-center gap-3 text-sm"><input :id="`retention-hours-${category}`" :value="hours" :disabled="disabled" type="number" min="1" max="87600" step="1" required class="min-h-11 w-28 rounded-lg border border-slate-200 bg-white px-3 py-2" @input="duration"><span>hours</span><span class="sr-only">Retention for {{ label }}</span></label></div>
  </div>
</template>
