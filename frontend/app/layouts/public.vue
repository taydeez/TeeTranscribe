<script setup lang="ts">
import { publicPages } from '~/config/publicPages'

const auth = useAuthStore()
const workspaceUrl = computed(() => auth.isAuthenticated ? (auth.isAdmin ? '/taydeez' : '/dashboard') : '/?login=1')
onMounted(() => { void auth.initialize() })
</script>

<template>
  <div class="public-site">
    <a href="#page-content" class="sr-only focus:not-sr-only focus:block focus:p-4">Skip to content</a>
    <header class="public-header">
      <NuxtLink class="brand" to="/"><span class="brand-mark"><UiAppIcon name="audio" /></span>TeeTranscribe</NuxtLink>
      <div class="flex items-center gap-2"><UiThemeToggle /><NuxtLink class="button-primary" :to="workspaceUrl">Open workspace</NuxtLink></div>
    </header>
    <nav class="flex flex-wrap gap-x-7 gap-y-3 border-b border-slate-200 py-4 text-sm text-slate-500" aria-label="Information pages">
      <NuxtLink v-for="page in publicPages" :key="page.to" :to="page.to" class="hover:text-indigo-600" active-class="font-semibold text-indigo-600">{{ page.label }}</NuxtLink>
    </nav>
    <main id="page-content" tabindex="-1" class="public-content"><slot /></main>
    <PublicSiteFooter />
  </div>
</template>

<style scoped>
.public-site { max-width: 1200px; margin: auto; padding: 0 40px; }
.public-header { min-height: 88px; display: flex; align-items: center; justify-content: space-between; gap: 16px; border-bottom: 1px solid var(--line); }
.public-content { padding: 64px 0 80px; min-height: 65vh; }
.public-content :deep(.public-intro) { max-width: 760px; margin-bottom: 40px; }
.public-content :deep(h1) { font-size: clamp(36px, 5vw, 56px); line-height: 1.12; letter-spacing: -.045em; font-weight: 800; }
.public-content :deep(.intro-copy) { color: var(--muted); font-size: 18px; line-height: 1.8; margin-top: 20px; }
.public-content :deep(.body-copy) { color: var(--muted); line-height: 1.85; }
.public-content :deep(.section-heading) { font-size: 22px; line-height: 1.4; font-weight: 700; letter-spacing: -.025em; }
@media (max-width: 640px) {
  .public-site { padding: 0 16px; }
  .public-header { min-height: 76px; gap: 8px; }
  .brand { font-size: 16px; gap: 7px; }
  .public-header .button-primary { font-size: 12px; padding: 10px; }
  .public-content { padding: 40px 0 56px; }
}
</style>
