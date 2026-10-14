<script setup lang="ts">
const auth = useAuthStore()
const route = useRoute()
const authOpen = ref(false)
const authMode = ref<'login' | 'register'>('login')
const workspace = computed(() => auth.isAdmin ? '/taydeez' : '/dashboard')

function openAuth(mode: 'login' | 'register') { authMode.value = mode; authOpen.value = true }
async function start(activity: 'transcription' | 'dubbing') {
  if (!auth.isAuthenticated) { openAuth('register'); return }
  if (!auth.isEmailVerified) { await navigateTo('/auth/verify-email'); return }
  await navigateTo(auth.isAdmin ? '/taydeez' : activity === 'dubbing' ? '/dashboard/dubbing' : '/dashboard#new-transcription')
}
async function signOut() { await auth.logout() }

onMounted(async () => {
  if (typeof route.query.admin_verify === 'string') {
    await navigateTo({ path: '/taydeez/login', query: { verify: '1', email: route.query.admin_verify } })
    return
  }
  if (route.query.login === '1') openAuth('login')
  const hashToken = new URLSearchParams(window.location.hash.slice(1)).get('token')
  if (hashToken) {
    history.replaceState(null, '', window.location.pathname)
    try { await auth.establishSession(hashToken); await navigateTo(auth.isEmailVerified ? workspace.value : '/auth/verify-email') }
    catch { authOpen.value = true }
    return
  }
  await auth.initialize()
})
</script>

<template>
  <div class="marketing">
    <header class="marketing-header">
      <NuxtLink class="brand" to="/"><UiBrandLogo /></NuxtLink>
      <nav class="hidden items-center gap-7 text-sm font-medium text-slate-600 lg:flex" aria-label="Homepage">
        <a href="#tools">What you can do</a><a href="#how-it-works">How it works</a><a href="#questions">Questions</a>
      </nav>
      <div class="flex items-center gap-2">
        <UiThemeToggle />
        <template v-if="auth.isAuthenticated">
          <NuxtLink class="button-primary" :to="workspace">Open workspace</NuxtLink>
          <button class="icon-button" type="button" aria-label="Sign out" @click="signOut"><UiAppIcon name="logout" /></button>
        </template>
        <template v-else>
          <button class="button-secondary" type="button" @click="openAuth('login')">Log in</button>
          <button class="button-primary hidden sm:inline-flex" type="button" @click="openAuth('register')">Get 10 free credits</button>
        </template>
      </div>
    </header>
    <main id="main-content">
      <section class="marketing-hero" aria-labelledby="hero-title">
        <div>
          <p class="hero-label"><span /> AUDIO TRANSCRIPTION &amp; VIDEO DUBBING</p>
          <h1 id="hero-title">Transcribe audio.<br>Dub your videos.<br><span>Reach further.</span></h1>
          <p class="hero-copy">Turn conversations into text you can use. Give your videos a voice in another language. Bring it all together in one workspace.</p>
          <div class="mt-8 flex flex-wrap gap-3">
            <button class="button-primary" type="button" @click="start('transcription')">Transcribe audio <UiAppIcon name="arrow" :size="18" /></button>
            <button class="button-secondary" type="button" @click="start('dubbing')"><UiAppIcon name="video" :size="18" /> Dub a video</button>
          </div>
          <p class="signup-note"><UiAppIcon name="check" :size="16" /><span>New accounts get <strong>10 free credits.</strong> Sign in to get started.</span></p>
        </div>
        <MarketingProductPreview />
      </section>
      <MarketingHomeDetails @start="start" />
      <section class="signup-panel" aria-labelledby="signup-title">
        <div><p class="eyebrow">START WITH SOMETHING YOU’VE ALREADY RECORDED</p><h2 id="signup-title">Your next conversation has somewhere to go.</h2><p>Try audio transcription or video dubbing with 10 free credits when you create an account.</p></div>
        <NuxtLink v-if="auth.isAuthenticated" class="button-primary shrink-0" :to="workspace">Go to workspace <UiAppIcon name="arrow" :size="18" /></NuxtLink>
        <button v-else class="button-primary shrink-0" type="button" @click="openAuth('register')">Create your free account <UiAppIcon name="arrow" :size="18" /></button>
      </section>
    </main>
    <AuthModal v-model:open="authOpen" :initial-mode="authMode" />
    <PublicSiteFooter />
  </div>
</template>

<style scoped>
.marketing { max-width:1280px; padding:0 40px; margin:auto; }
.marketing-header { min-height:96px; display:flex; align-items:center; justify-content:space-between; gap:16px; border-bottom:1px solid var(--line); }
.marketing-header nav a { padding:12px 0; transition:color .2s; }
.marketing-header nav a:hover { color:#6366f1; }
.marketing-hero { display:grid; grid-template-columns:1.05fr 1fr; gap:64px; align-items:center; padding:96px 0 88px; }
.hero-label { display:flex; align-items:center; gap:9px; font-size:11px; font-weight:600; letter-spacing:.1em; color:var(--muted); margin-bottom:24px; }
.hero-label span { width:7px; height:7px; border-radius:50%; background:#6366f1; flex-shrink:0; }
.marketing-hero h1 { font-size:clamp(40px,4.7vw,62px); line-height:1.12; letter-spacing:-.055em; font-weight:800; }
.marketing-hero h1 span { color:#6366f1; }
.hero-copy { max-width:475px; font-size:17px; line-height:1.8; color:var(--muted); margin-top:24px; }
.signup-note { display:flex; align-items:flex-start; gap:7px; margin-top:20px; font-size:12px; line-height:1.8; color:var(--muted); }
.signup-note svg { margin-top:3px; color:#6366f1; flex-shrink:0; }
.signup-panel { display:flex; align-items:center; justify-content:space-between; gap:32px; margin:0 0 72px; padding:40px; border:1px solid var(--line); border-radius:24px; background:linear-gradient(120deg,#6366f110,#06b6d408); }
.signup-panel h2 { max-width:600px; font-size:clamp(24px,3vw,32px); font-weight:700; line-height:1.3; letter-spacing:-.04em; }
.signup-panel p:last-child { max-width:580px; margin-top:16px; color:var(--muted); font-size:14px; line-height:1.8; }
@media(max-width:1000px) { .marketing-hero { gap:32px; } }
@media(max-width:800px) { .marketing-hero { grid-template-columns:1fr; gap:48px; padding:56px 0; } .marketing{padding:0 24px;} .signup-panel { align-items:flex-start; flex-direction:column; padding:28px; } }
@media(max-width:520px) { .marketing{padding:0 16px;} .marketing-header { min-height:80px; gap:8px; } .marketing-header .button-primary{padding:10px 12px;} .hero-copy { font-size:16px; } }
</style>
