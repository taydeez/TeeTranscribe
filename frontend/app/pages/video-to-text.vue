<script setup lang="ts">
import { productPages } from '#shared/config/productPages'
definePageMeta({ layout: 'public' })
const page = productPages['/video-to-text']!
</script>
<template><MarketingProductLanding :page="page" /></template>
