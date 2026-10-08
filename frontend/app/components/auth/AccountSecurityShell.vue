<script setup lang="ts">
defineProps<{ title: string; description: string }>()
</script>

<template>
  <main class="grid min-h-screen place-items-center bg-slate-50 px-4 py-12">
    <section class="surface w-full max-w-md p-6 sm:p-9">
      <NuxtLink to="/" class="mb-8 flex items-center gap-2 text-lg font-bold text-indigo-600"><UiAppIcon name="audio" />TeeTranscribe</NuxtLink>
      <h1 class="text-2xl font-bold tracking-tight">{{ title }}</h1>
      <p class="mt-3 text-sm leading-6 text-slate-500">{{ description }}</p>
      <div class="mt-6"><slot /></div>
    </section>
  </main>
</template>
