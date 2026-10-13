<template>
  <span class="brand-logo" role="img" aria-label="TeeScriber">
    <img class="brand-logo-wordmark" src="/logo.png" alt="" width="1254" height="1254" decoding="async" draggable="false">
    <img class="brand-logo-icon" src="/favicon.png" alt="" width="1254" height="1254" decoding="async" draggable="false">
  </span>
</template>

<style scoped>
.brand-logo { position: relative; display: block; flex-shrink: 0; width: clamp(132px, 17vw, 188px); aspect-ratio: 3.5; overflow: hidden; }
.brand-logo-wordmark { position: absolute; top: 50%; left: 50%; width: 110.5%; max-width: none; height: auto; transform: translate(-50%, -50%); }
.brand-logo-icon { display: none; }
:global(:root[data-theme="dark"]) .brand-logo { background: white; border-radius: 8px; }
@media (max-width: 380px) {
  .brand-logo { width: 42px; aspect-ratio: 1; }
  .brand-logo-wordmark { display: none; }
  .brand-logo-icon { position: absolute; top: 50%; left: 50%; display: block; width: 136%; max-width: none; height: auto; transform: translate(-50%, -50%); }
  :global(:root[data-theme="dark"]) .brand-logo { background: transparent; }
}
</style>
