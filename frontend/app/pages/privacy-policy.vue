<script setup lang="ts">
import { privacySections } from '~/config/publicPages'

definePageMeta({ layout: 'public' })
useSeoMeta({ title: 'Privacy Policy · TeeTranscribe', description: 'How TeeTranscribe handles account information, uploaded content, payments, and your retention and deletion choices.' })
</script>

<template>
  <div>
    <section class="public-intro"><p class="eyebrow">YOUR INFORMATION</p><h1>Privacy Policy</h1><p class="intro-copy">What we handle, why we need it, and the controls you have over your content.</p><p class="mt-5 text-sm text-slate-500">Last updated: <time datetime="2026-10-10">10 October 2026</time></p></section>
    <div class="grid gap-10 lg:grid-cols-[240px_1fr]">
      <nav aria-label="Privacy policy sections" class="self-start rounded-2xl border border-slate-200 p-5 lg:sticky lg:top-8"><p class="mb-3 text-sm font-semibold">On this page</p><a v-for="section in privacySections" :key="section.id" :href="`#${section.id}`" class="block py-2 text-sm text-slate-500 hover:text-indigo-600">{{ section.title }}</a></nav>
      <div class="space-y-9">
        <aside class="surface border-l-4 border-l-indigo-500 p-6"><h2 class="section-heading">Your files, your settings</h2><p class="body-copy mt-3">You can delete content on demand and set automatic retention periods by category. Keeping a source file and keeping its transcript are separate choices.</p><NuxtLink class="mt-4 inline-flex text-sm font-semibold text-indigo-600" to="/dashboard/settings">Manage privacy settings →</NuxtLink></aside>
        <section v-for="section in privacySections" :id="section.id" :key="section.id" class="scroll-mt-8"><h2 class="section-heading">{{ section.title }}</h2><p v-for="paragraph in section.paragraphs" :key="paragraph" class="body-copy mt-4">{{ paragraph }}</p></section>
        <NuxtLink class="button-secondary" to="/contact">Contact us about privacy <UiAppIcon name="arrow" :size="16" /></NuxtLink>
      </div>
    </div>
  </div>
</template>
