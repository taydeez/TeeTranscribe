<script setup lang="ts">
import { productPages } from '#shared/config/productPages'
definePageMeta({ layout: 'public' })
const page = productPages['/add-subtitles-to-video']!
</script>
<template><MarketingProductLanding :page="page" /></template>
