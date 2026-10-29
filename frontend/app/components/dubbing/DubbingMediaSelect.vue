<script setup lang="ts">
import type { DubbingMediaType } from '~/types/dubbing'
const mediaType = defineModel<DubbingMediaType>({ required: true })
</script>

<template>
  <fieldset>
    <legend class="mb-3 text-sm font-medium text-slate-700">What are you uploading?</legend>
    <div class="grid grid-cols-2 gap-3">
      <label v-for="option in (['video', 'audio'] as const)" :key="option" class="flex cursor-pointer items-center gap-3 rounded-xl border p-4 transition" :class="mediaType === option ? 'border-indigo-400 bg-indigo-50 ring-1 ring-indigo-400' : 'border-slate-200 hover:border-indigo-200'">
        <input v-model="mediaType" type="radio" name="dubbing-media-type" :value="option" class="accent-indigo-600">
        <span class="text-sm font-semibold capitalize text-slate-800">{{ option === 'audio' ? 'Audio only' : 'Video' }}</span>
      </label>
    </div>
  </fieldset>
</template>
