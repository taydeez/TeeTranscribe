<script setup lang="ts">
import type { SubtitleStyle, SubtitleStyleId } from '~/types/dubbing'
defineProps<{ styles: SubtitleStyle[]; subtitleOnly?: boolean }>()
const enabled = defineModel<boolean>('enabled', { required: true })
const style = defineModel<SubtitleStyleId>('style', { required: true })
</script>

<template>
  <div class="rounded-xl border border-slate-200 p-4">
    <label class="flex cursor-pointer items-start gap-3">
      <input v-if="!subtitleOnly" v-model="enabled" type="checkbox" class="mt-1 size-4 rounded accent-indigo-600">
      <span><span class="block text-sm font-semibold text-slate-800">{{ subtitleOnly ? 'Style your subtitles' : 'Add subtitles to my video' }}</span><span class="mt-1 block text-xs leading-relaxed text-slate-500">{{ subtitleOnly ? 'Permanent subtitles with your original audio. You’ll also get a separate SRT file.' : 'Permanent subtitles in the dubbed language. You’ll also get a separate SRT file.' }}</span></span>
    </label>
    <fieldset v-if="enabled || subtitleOnly" class="mt-4">
      <legend class="mb-3 text-xs font-medium text-slate-600">Choose a subtitle style</legend>
      <div class="grid gap-3 sm:grid-cols-3">
        <label v-for="option in styles" :key="option.id" class="cursor-pointer rounded-xl border p-3 transition" :class="style === option.id ? 'border-indigo-400 bg-indigo-50 ring-1 ring-indigo-400' : 'border-slate-200 hover:border-indigo-200'">
          <div class="mb-3 flex min-h-16 items-end justify-center rounded-lg bg-slate-800 px-2 pb-3">
            <span class="text-center text-xs" :class="option.id === 'boxed' ? 'rounded bg-black/70 px-2 py-1 text-white' : option.id === 'contrast' ? 'font-bold text-yellow-300 [text-shadow:1px_1px_2px_black]' : 'text-white [text-shadow:1px_1px_2px_black]'">Hello, welcome.</span>
          </div>
          <span class="flex items-center gap-2 text-xs font-medium text-slate-700"><input v-model="style" type="radio" name="subtitle-style" :value="option.id" class="accent-indigo-600">{{ option.name }}</span>
          <span class="mt-2 block text-xs leading-relaxed text-slate-500">{{ option.description }}</span>
        </label>
      </div>
      <p class="mt-3 text-xs text-slate-500">Adding subtitles may take longer, especially for large videos.</p>
    </fieldset>
  </div>
</template>
