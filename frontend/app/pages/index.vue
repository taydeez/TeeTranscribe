<script setup lang="ts">
const auth = useAuthStore()
const route = useRoute()
const authOpen = ref(false)
const authMode = ref<'login' | 'register'>('login')

function openAuth(mode: 'login' | 'register') { authMode.value = mode; authOpen.value = true }
async function signOut() { await auth.logout() }

onMounted(async () => {
  if (route.query.login === '1') openAuth('login')
  const hashToken = new URLSearchParams(window.location.hash.slice(1)).get('token')
  if (hashToken) {
    history.replaceState(null, '', window.location.pathname)
    try { await auth.establishSession(hashToken); await navigateTo('/dashboard') }
    catch { authOpen.value = true }
    return
  }
  await auth.initialize()
})
</script>
<template>
  <div class="marketing">
    <header class="marketing-header"><NuxtLink class="brand" to="/"><span class="brand-mark"><UiAppIcon name="audio" /></span>TeeTranscribe</NuxtLink><div class="flex items-center gap-2"><UiThemeToggle /><template v-if="auth.isAuthenticated"><NuxtLink class="button-primary" to="/dashboard">Open workspace</NuxtLink><button class="icon-button" type="button" aria-label="Sign out" @click="signOut"><UiAppIcon name="logout" /></button></template><template v-else><button class="button-secondary hidden sm:inline-flex" type="button" @click="openAuth('login')">Log in</button><button class="button-primary" type="button" @click="openAuth('register')">Get started</button></template></div></header>
    <main>
      <section class="marketing-hero">
        <div><p class="eyebrow mb-6 flex items-center gap-2"><span class="size-2 rounded-full bg-emerald-500" />A workspace for every word</p><h1>Speak once.<br><span>Reach everyone.</span></h1><p class="hero-copy">Turn audio and video into clear, editable text. A workspace built for transcription today, with translation and dubbing on the way.</p><div class="mt-8 flex flex-wrap gap-3"><NuxtLink v-if="auth.isAuthenticated" class="button-primary" to="/dashboard#new-transcription">Transcribe a recording <UiAppIcon name="arrow" :size="18" /></NuxtLink><button v-else class="button-primary" type="button" @click="openAuth('register')">Start transcribing <UiAppIcon name="arrow" :size="18" /></button><a class="button-secondary" href="#how-it-works">See how it works</a></div><p class="mt-5 text-xs text-slate-500">For interviews, meetings, voice notes, and the ideas in between.</p></div>
        <div class="product-preview surface" aria-label="Illustrative transcript preview">
          <div class="flex items-center justify-between border-b border-slate-200 p-5"><span class="flex items-center gap-2 text-sm font-semibold"><UiAppIcon name="transcript" class="text-indigo-600" />Interview transcript</span><span class="status-badge">Example</span></div>
          <div class="p-6"><div class="mb-6 flex items-center gap-3 rounded-xl bg-slate-50 p-4"><span class="feature-icon" data-accent="cyan"><UiAppIcon name="audio" /></span><div><p class="text-sm font-medium">A conversation worth keeping</p><p class="mt-1 text-xs text-slate-500">Audio → editable transcript</p></div></div><div class="preview-segment"><span class="timestamp text-xs text-slate-500">00:00:04</span><span class="ml-3 rounded-full bg-indigo-50 px-2.5 py-1 text-xs text-indigo-600">Speaker 1</span><p>Every good idea starts with a conversation.</p></div><div class="preview-segment mt-6"><span class="timestamp text-xs text-slate-500">00:00:12</span><span class="ml-3 rounded-full bg-cyan-50 px-2.5 py-1 text-xs text-cyan-700">Speaker 2</span><p>Let's make sure this one reaches the right people.</p></div></div>
          <div class="flex items-center justify-between border-t border-slate-200 p-5 text-xs text-slate-500"><span>Transcribe. Edit. Share.</span><span class="flex gap-2"><span class="rounded border border-slate-200 px-2 py-1">PDF</span><span class="rounded border border-slate-200 px-2 py-1">TXT</span></span></div>
        </div>
      </section>
      <section id="how-it-works" class="marketing-features"><div class="mb-8"><p class="eyebrow">Less busywork. More understanding.</p><h2 class="text-3xl font-bold tracking-tight">Your content, in more forms.</h2></div><div class="grid gap-4 md:grid-cols-3"><article class="feature-card"><span class="feature-icon"><UiAppIcon name="transcript" /></span><h2>Transcribe</h2><p>Upload or record, choose your language, and turn speech into text you can edit and export.</p><span class="feature-link">Available now <UiAppIcon name="check" :size="16" /></span></article><article class="feature-card" data-accent="violet"><span class="feature-icon"><UiAppIcon name="translate" /></span><h2>Translate</h2><p>Bring transcripts into another language while keeping their meaning and context.</p><span class="status-badge mt-5">Coming soon</span></article><article class="feature-card" data-accent="cyan"><span class="feature-icon"><UiAppIcon name="audio" /></span><h2>Dub</h2><p>Prepare audio and video for new audiences with natural voices in another language.</p><span class="status-badge mt-5">Coming soon</span></article></div></section>
      <section class="pb-16"><TranscriptionForm heading="Try your next recording" eyebrow="TRANSCRIBE" /></section>
    </main>
    <AuthModal v-model:open="authOpen" :initial-mode="authMode" />
    <footer class="flex flex-wrap justify-between gap-4 border-t border-slate-200 py-6 text-xs text-slate-500"><span>Your voice. More possibilities.</span><span>TeeTranscribe © {{ new Date().getFullYear() }}</span></footer>
  </div>
</template>
<style scoped>
.marketing { max-width: 1280px; padding: 0 40px; margin: auto; }
.marketing-header { min-height: 88px; display: flex; align-items: center; justify-content: space-between; gap:16px; border-bottom:1px solid var(--line); }
.marketing-hero { display:grid; grid-template-columns:1fr 1fr; gap:64px; align-items:center; padding:88px 0; }
.marketing-hero h1 { font-size:clamp(44px,5vw,64px); line-height:1.1; letter-spacing:-.05em; font-weight:800; }
.marketing-hero h1 span { color:#4f46e5; }
.hero-copy { max-width:480px; font-size:16px; line-height:1.8; color:var(--muted); margin-top:24px; }
.product-preview { overflow:hidden; box-shadow:0 8px 32px #0f172a08; }
.preview-segment p { margin-top:16px; font-size:16px; line-height:1.65; }
.marketing-features { padding:0 0 64px; }
@media(max-width:900px) { .marketing-hero { grid-template-columns:1fr; gap:40px; padding:48px 0; } .marketing{padding:0 24px;} }
@media(max-width:520px) { .marketing{padding:0 16px;} .brand{font-size:16px;} .marketing-header .button-primary{padding:10px 12px;} }
</style>
