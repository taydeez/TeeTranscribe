<script setup lang="ts">
import { productPages } from '#shared/config/productPages'
definePageMeta({ layout: 'public' })
const page = productPages['/audio-dubbing']!
</script>
<template><MarketingProductLanding :page="page" /></template>
