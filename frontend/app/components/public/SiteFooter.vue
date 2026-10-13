<script setup lang="ts">
import { publicPages } from '~/config/publicPages'
</script>

<template>
  <footer class="flex flex-wrap items-center justify-between gap-6 border-t border-slate-200 py-8 text-sm text-slate-500">
    <div><NuxtLink class="brand mb-4 w-fit" to="/"><UiBrandLogo /></NuxtLink><p class="font-semibold text-slate-700">Your voice. More possibilities.</p><p class="mt-2 text-xs">TeeTranscribe © {{ new Date().getFullYear() }}</p></div>
    <nav class="flex flex-wrap gap-x-6 gap-y-3" aria-label="Footer">
      <NuxtLink v-for="page in publicPages" :key="page.to" :to="page.to" class="transition-colors hover:text-indigo-600" active-class="font-semibold text-indigo-600">{{ page.label }}</NuxtLink>
    </nav>
  </footer>
</template>
