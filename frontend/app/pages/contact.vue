<script setup lang="ts">
import { contactEmails } from '~/config/publicPages'

definePageMeta({ layout: 'public' })
useSeoMeta({ title: 'Contact · TeeTranscribe', description: 'Get help with your workspace, ask a privacy question, or talk to us about your organisation.' })
</script>

<template>
  <div>
    <section class="public-intro"><p class="eyebrow">LET’S TALK</p><h1>What can we<br><span class="text-indigo-600">help you with?</span></h1><p class="intro-copy">A question, a snag, or an idea for making TeeTranscribe better? We’d like to hear it.</p></section>
    <p class="mb-6 rounded-xl border border-slate-200 px-5 py-4 text-sm text-slate-500">Contact details are being set up. The addresses below are placeholders and are not monitored.</p>
    <div class="grid gap-4 md:grid-cols-3">
      <article class="surface p-7"><p class="eyebrow">01 / SUPPORT</p><h2 class="section-heading">Need a hand?</h2><p class="body-copy mt-3 text-sm">Help with uploads, project results, your account, or a payment.</p><p class="mt-6 break-all font-semibold text-indigo-600">{{ contactEmails.support }}</p><span class="status-badge mt-3">Placeholder email</span></article>
      <article class="surface p-7"><p class="eyebrow">02 / PRIVACY</p><h2 class="section-heading">Your information</h2><p class="body-copy mt-3 text-sm">Questions about file retention, account deletion, or how your data is handled.</p><p class="mt-6 break-all font-semibold text-indigo-600">{{ contactEmails.privacy }}</p><span class="status-badge mt-3">Placeholder email</span></article>
      <article class="surface p-7"><p class="eyebrow">03 / BUSINESS</p><h2 class="section-heading">Work with us</h2><p class="body-copy mt-3 text-sm">Tell us about your organisation, your languages, and what you need to create.</p><p class="mt-6 break-all font-semibold text-indigo-600">{{ contactEmails.business }}</p><span class="status-badge mt-3">Placeholder email</span></article>
    </div>
    <section class="mt-12 grid gap-6 border-t border-slate-200 pt-10 md:grid-cols-2">
      <div><h2 class="section-heading">Help us find the problem.</h2><p class="body-copy mt-3">For a project or payment issue, include your account email, the project ID or payment reference, and a short description of what happened. A screenshot of the error can help.</p><p class="body-copy mt-3 text-sm">Don’t send passwords, verification codes, or full card details.</p></div>
      <div class="surface p-7"><h2 class="section-heading">You might find the answer here.</h2><p class="body-copy mt-3">Our FAQ covers uploads, credits, download files, and privacy settings.</p><NuxtLink class="button-secondary mt-5" to="/faq">Browse the FAQ <UiAppIcon name="arrow" :size="16" /></NuxtLink></div>
    </section>
  </div>
</template>
