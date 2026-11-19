<script setup lang="ts">
import { productPages } from '#shared/config/productPages'
const features = useMarketingFeatures()
const pages = computed(() => Object.entries(productPages).filter(([, page]) => features.enabled(page.feature)))
</script>
<template>
  <nav v-if="pages.length" class="my-8 flex flex-wrap gap-3" aria-label="Explore tools"><NuxtLink v-for="[path, page] in pages" :key="path" :to="path" class="rounded-full border border-[var(--line)] bg-[var(--surface)] px-4 py-2 text-sm font-medium transition hover:border-indigo-400 hover:text-indigo-600">{{ page.label }}<span class="ml-2" aria-hidden="true">↗</span></NuxtLink></nav>
</template>
