<script setup lang="ts">
import type { VideoOperation } from '~/types/dubbing'
const operation = defineModel<VideoOperation>({ required: true })
</script>

<template>
  <fieldset>
    <legend class="mb-3 text-sm font-medium text-slate-700">What would you like to do?</legend>
    <div class="grid gap-3 sm:grid-cols-2">
      <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition" :class="operation === 'dubbing' ? 'border-indigo-400 bg-indigo-50 ring-1 ring-indigo-400' : 'border-slate-200 hover:border-indigo-200'">
        <input v-model="operation" type="radio" name="video-operation" value="dubbing" class="mt-1 accent-indigo-600">
        <span><span class="block text-sm font-semibold text-slate-800">Dub video</span><span class="mt-1 block text-xs leading-relaxed text-slate-500">Translate the audio into a new language, with optional subtitles.</span></span>
      </label>
      <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition" :class="operation === 'subtitles' ? 'border-indigo-400 bg-indigo-50 ring-1 ring-indigo-400' : 'border-slate-200 hover:border-indigo-200'">
        <input v-model="operation" type="radio" name="video-operation" value="subtitles" class="mt-1 accent-indigo-600">
        <span><span class="block text-sm font-semibold text-slate-800">Add subtitles only</span><span class="mt-1 block text-xs leading-relaxed text-slate-500">Keep the original audio and add subtitles in your chosen language.</span></span>
      </label>
    </div>
  </fieldset>
</template>
