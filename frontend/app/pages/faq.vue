<script setup lang="ts">
import { faqGroups } from '~/config/publicPages'

definePageMeta({ layout: 'public' })
useSeoMeta({ title: 'FAQ · TeeTranscribe', description: 'Answers about uploads, transcription, translations, downloads, credits, and file privacy.' })
</script>

<template>
  <div>
    <section class="public-intro"><p class="eyebrow">A LITTLE HELP</p><h1>Questions, answered.</h1><p class="intro-copy">From your first recording to your next download. Here’s how things work.</p></section>
    <div class="grid gap-10 lg:grid-cols-[220px_1fr]">
      <nav aria-label="FAQ topics" class="flex flex-wrap gap-3 self-start lg:sticky lg:top-8 lg:flex-col">
        <a v-for="(group, index) in faqGroups" :key="group.title" :href="`#topic-${index}`" class="rounded-lg border border-slate-200 px-4 py-3 text-sm font-medium hover:text-indigo-600">{{ group.title }}</a>
      </nav>
      <div class="space-y-10">
        <section v-for="(group, index) in faqGroups" :id="`topic-${index}`" :key="group.title" class="scroll-mt-8">
          <h2 class="section-heading mb-4">{{ group.title }}</h2>
          <div class="surface divide-y divide-slate-200 overflow-hidden">
            <details v-for="item in group.items" :key="item.question" class="group p-5 sm:p-6">
              <summary class="flex cursor-pointer list-none items-center justify-between gap-5 font-semibold"><span>{{ item.question }}</span><span class="text-xl font-normal text-indigo-600 group-open:rotate-45" aria-hidden="true">+</span></summary>
              <p class="body-copy mt-4 pr-5 text-sm">{{ item.answer }}</p>
            </details>
          </div>
        </section>
      </div>
    </div>
    <section class="surface mt-12 p-8"><h2 class="section-heading">Still need a hand?</h2><p class="body-copy mt-2">Tell us what you’re trying to do and where you got stuck.</p><NuxtLink class="button-secondary mt-5" to="/contact">Contact us <UiAppIcon name="arrow" :size="16" /></NuxtLink></section>
  </div>
</template>
