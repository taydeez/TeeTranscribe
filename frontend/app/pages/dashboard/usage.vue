<script setup lang="ts">
definePageMeta({ layout: 'dashboard', title: 'Usage' })
</script>
<template>
  <div class="max-w-6xl space-y-6"><section class="page-intro"><p class="eyebrow">Account</p><h1>Your usage</h1><p>Track processing, credit reservations, and completed transcription charges.</p></section><BillingCreditHistory type="usage" /><NuxtLink class="button-secondary" to="/dashboard/billing">View balance and add credits <UiAppIcon name="arrow" :size="16" /></NuxtLink></div>
</template>
