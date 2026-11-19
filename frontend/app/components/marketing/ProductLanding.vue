<script setup lang="ts">
import type { ProductPage } from '#shared/config/productPages'
const props = defineProps<{ page: ProductPage }>()
const auth = useAuthStore()
const destination = computed(() => {
  if (!auth.isAuthenticated) return '/?register=1'
  if (auth.isAdmin) return '/taydeez'
  if (props.page.feature === 'transcription') return '/dashboard#new-transcription'
  if (props.page.feature === 'translation') return '/dashboard/translations'
  return { path: '/dashboard/dubbing', query: { media: props.page.feature === 'audio_dubbing' ? 'audio' : 'video', operation: props.page.feature === 'subtitles' ? 'subtitles' : 'dubbing' } }
})
</script>
<template>
  <article>
    <section class="public-intro"><p class="eyebrow">{{ page.label }}</p><h1>{{ page.headline }}</h1><p class="intro-copy">{{ page.intro }}</p><div class="mt-7 flex flex-wrap gap-3"><NuxtLink class="button-primary" :to="destination">{{ auth.isAuthenticated ? 'Open the tool' : 'Create your account' }}<UiAppIcon name="arrow" :size="17" /></NuxtLink><NuxtLink class="button-secondary" to="/pricing">View pricing</NuxtLink></div><p class="mt-4 text-sm text-[var(--muted)]">An account is required. Review the credit quote before you start.</p></section>
    <p class="body-copy max-w-3xl">{{ page.detail }}</p>
    <section class="mt-12 grid gap-4 md:grid-cols-3" aria-label="What you can do"><article v-for="benefit in page.benefits" :key="benefit[0]" class="surface p-6"><h2 class="text-lg font-semibold">{{ benefit[0] }}</h2><p class="body-copy mt-3 text-sm">{{ benefit[1] }}</p></article></section>
    <section class="mt-14"><h2 class="section-heading">How it works</h2><ol class="mt-6 grid gap-6 md:grid-cols-3"><li v-for="(step, index) in page.steps" :key="step[0]" class="border-t border-[var(--line)] pt-5"><p class="eyebrow">Step {{ index + 1 }}</p><h3 class="mt-3 text-lg font-semibold">{{ step[0] }}</h3><p class="body-copy mt-3 text-sm">{{ step[1] }}</p></li></ol></section>
    <aside class="mt-12 rounded-2xl bg-indigo-50 p-6 text-slate-800 sm:p-8"><p class="eyebrow">For example</p><h2 class="mt-3 text-xl font-semibold">{{ page.example[0] }}</h2><p class="mt-4 max-w-3xl text-sm leading-7">{{ page.example[1] }}</p></aside>
    <section class="mt-12 max-w-3xl"><h2 class="section-heading">What to know before you start</h2><p class="body-copy mt-4">{{ page.limit }}</p><p class="body-copy mt-4">Your projects stay in folders. You can delete source files and results, or choose retention periods in your account settings. <NuxtLink to="/privacy-policy" class="underline underline-offset-4">Read about file privacy</NuxtLink>.</p></section>
    <section class="mt-12"><h2 class="section-heading">Common questions</h2><div class="mt-5 divide-y divide-[var(--line)]"><details v-for="faq in page.faqs" :key="faq[0]" class="py-5"><summary class="cursor-pointer text-base font-semibold">{{ faq[0] }}</summary><p class="body-copy mt-3 max-w-3xl text-sm">{{ faq[1] }}</p></details></div></section>
    <MarketingProductLinks />
    <section class="mt-10 flex flex-wrap items-center justify-between gap-5 rounded-2xl border border-[var(--line)] p-6"><div><h2 class="text-xl font-semibold">Bring your next project.</h2><p class="body-copy mt-2 text-sm">Check the quote, review the result, and keep your files together.</p></div><NuxtLink class="button-primary" :to="destination">Get started<UiAppIcon name="arrow" :size="17" /></NuxtLink></section>
  </article>
</template>
