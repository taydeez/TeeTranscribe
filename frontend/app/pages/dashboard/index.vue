<script setup lang="ts">
definePageMeta({ layout: 'dashboard', title: 'Home' })
const auth = useAuthStore()
const actions = [
  { title: 'Transcribe', icon: 'transcript' as const, copy: 'Turn audio and video into editable text.', label: 'Start transcription', to: '#new-transcription', accent: 'indigo', available: true },
  { title: 'Translate', icon: 'translate' as const, copy: 'Bring your words to another language.', label: 'Explore translation', to: '/dashboard/translations', accent: 'violet', available: false },
  { title: 'Dub', icon: 'audio' as const, copy: 'Give your content a voice in another language.', label: 'Explore dubbing', to: '/dashboard/dubbing', accent: 'cyan', available: false },
]
</script>
<template>
  <section class="page-intro"><p class="eyebrow">Your creative workspace</p><h1>Welcome back{{ auth.user?.name ? ', ' + auth.user.name.split(' ')[0] : '' }}.</h1><p>From a recording to something worth sharing. Start here.</p></section>
  <section class="grid gap-4 md:grid-cols-3" aria-label="Create with TeeTranscribe">
    <NuxtLink v-for="action in actions" :key="action.title" :to="action.to" class="feature-card" :data-accent="action.accent"><div class="flex items-center justify-between"><span class="feature-icon"><UiAppIcon :name="action.icon" :size="22" /></span><span v-if="!action.available" class="status-badge">Coming soon</span></div><h2>{{ action.title }}</h2><p>{{ action.copy }}</p><span class="feature-link">{{ action.label }}<UiAppIcon name="arrow" :size="16" /></span></NuxtLink>
  </section>
  <section id="new-transcription" class="mt-8 scroll-mt-24 grid items-start gap-6 xl:grid-cols-[1fr_280px]">
    <TranscriptionForm heading="New transcription" eyebrow="TRANSCRIBE" dashboard />
    <aside class="surface p-6"><span class="feature-icon" data-accent="cyan"><UiAppIcon name="mic" /></span><h2 class="mt-4 text-lg font-semibold">A little clarity goes a long way.</h2><ol class="mt-5 space-y-5 text-sm text-slate-500"><li><strong class="mb-1 block text-slate-800">1. Add your recording</strong>Upload a file, record your voice, or paste a link.</li><li><strong class="mb-1 block text-slate-800">2. Choose a language</strong>Select the language spoken in your recording.</li><li><strong class="mb-1 block text-slate-800">3. Make it yours</strong>Edit the transcript and download PDF or TXT.</li></ol><NuxtLink class="button-secondary mt-6 w-full" to="/dashboard/transcriptions"><UiAppIcon name="folder" :size="17" />View transcriptions</NuxtLink></aside>
  </section>
</template>
