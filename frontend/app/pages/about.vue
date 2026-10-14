<script setup lang="ts">
definePageMeta({ layout: 'public' })
</script>

<template>
  <div>
    <section class="public-intro">
      <p class="eyebrow">ABOUT TEESCRIBER</p>
      <h1>Good conversations<br><span class="text-indigo-600">deserve to go further.</span></h1>
      <p class="intro-copy">An interview becomes an article. A voice note becomes a written record. A video finds an audience in another language. We’re building a simpler way to get there.</p>
    </section>
    <section class="grid gap-8 border-y border-slate-200 py-10 md:grid-cols-2">
      <h2 class="section-heading max-w-sm">Less time typing.<br>More time with the ideas.</h2>
      <div class="body-copy space-y-4"><p>TeeScriber brings transcription, translation, and dubbing into one workspace. Upload a file or record an idea, work with the result, and keep the pieces together in folders.</p><p>It’s for the journalist reviewing an interview, the student revisiting a lecture, the creator reaching a new audience, and anyone who would rather listen than type every word.</p></div>
    </section>
    <section class="py-12">
      <p class="eyebrow">ROOTED HERE. USEFUL EVERYWHERE.</p>
      <h2 class="section-heading">Language should open doors.</h2>
      <p class="body-copy mt-4 max-w-3xl">We’re putting Nigerian languages and ways of speaking in focus, including Yoruba, Igbo, Hausa, Pidgin, and Nigerian English, while supporting work across international languages. Availability and accuracy vary, so you can review and edit AI-generated results before sharing them.</p>
    </section>
    <section class="grid gap-4 md:grid-cols-3" aria-label="What matters to us">
      <article class="feature-card"><span class="feature-icon"><UiAppIcon name="transcript" /></span><h2>Useful results</h2><p>Editable text and practical downloads that fit into the work you already do.</p></article>
      <article class="feature-card" data-accent="violet"><span class="feature-icon"><UiAppIcon name="translate" /></span><h2>Room for your language</h2><p>A growing set of language options, with attention to voices too often overlooked.</p></article>
      <article class="feature-card" data-accent="cyan"><span class="feature-icon"><UiAppIcon name="folder" /></span><h2>Control over your files</h2><p>Keep projects organised, delete what you no longer need, and choose retention periods.</p></article>
    </section>
    <section class="surface mt-12 flex flex-wrap items-center justify-between gap-6 p-8"><div><h2 class="section-heading">Have something worth keeping?</h2><p class="body-copy mt-2">Start with your next recording.</p></div><NuxtLink class="button-primary" to="/?login=1">Open your workspace <UiAppIcon name="arrow" :size="18" /></NuxtLink></section>
  </div>
</template>
