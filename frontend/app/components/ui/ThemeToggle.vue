<script setup lang="ts">
const theme = useState<'light' | 'dark'>('theme', () => 'light')
const preference = useCookie<'light' | 'dark'>('theme', { default: () => 'light', maxAge: 31536000 })
function toggle() { theme.value = theme.value === 'light' ? 'dark' : 'light'; preference.value = theme.value }
</script>
<template><button type="button" class="icon-button" :aria-label="theme === 'light' ? 'Switch to dark mode' : 'Switch to light mode'" @click="toggle"><UiAppIcon :name="theme === 'light' ? 'moon' : 'sun'" /></button></template>
