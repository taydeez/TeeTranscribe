<template>
  <span class="brand-logo" role="img" aria-label="TeeScriber">
    <img class="brand-logo-wordmark" src="/logo.png" alt="" width="384" height="128" decoding="async" draggable="false">
    <img class="brand-logo-icon" src="/favicon.png" alt="" width="64" height="64" decoding="async" draggable="false">
  </span>
</template>

<style scoped>
.brand-logo { display: block; flex-shrink: 0; width: clamp(140px, 17vw, 188px); aspect-ratio: 3; background: #fff; border-radius: 6px; overflow: hidden; }
.brand-logo-wordmark { display: block; width: 100%; height: 100%; object-fit: contain; }
.brand-logo-icon { display: none; }
@media (max-width: 380px) {
  .brand-logo { width: 42px; aspect-ratio: 1; }
  .brand-logo-wordmark { display: none; }
  .brand-logo-icon { display: block; width: 100%; height: 100%; object-fit: contain; }
}
</style>
