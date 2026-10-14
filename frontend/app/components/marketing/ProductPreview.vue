<template>
  <div class="preview-stage" aria-label="Illustrative examples of an audio transcript and a dubbed video">
    <div class="preview-glow" aria-hidden="true" />
    <article class="transcript-preview surface">
      <header class="preview-header"><span><UiAppIcon name="audio" :size="18" /> Interview recording</span><span class="example-label">Example</span></header>
      <div class="waveform" aria-hidden="true"><span v-for="(height, index) in [16, 28, 20, 36, 48, 26, 18, 38, 54, 42, 24, 32, 48, 28, 18, 36, 52, 30, 20, 42, 28, 16, 34, 46, 22, 16]" :key="index" :style="{ height: `${height}px` }" /></div>
      <div class="transcript-body">
        <div class="segment"><p class="segment-label"><span>00:04</span> Speaker 1</p><p>What made you want to tell this story?</p></div>
        <div class="segment"><p class="segment-label"><span>00:12</span> Speaker 2</p><p>I wanted more people to hear it in their own language.</p></div>
      </div>
      <footer class="preview-footer"><span><UiAppIcon name="transcript" :size="15" /> Editable transcript</span><span>PDF · TXT · DOCX</span></footer>
    </article>
    <article class="dubbing-preview surface">
      <div class="video-illustration" aria-hidden="true"><div class="video-orbit" /><UiAppIcon name="video" :size="34" /><span>Hola. Aquí empieza nuestra historia.</span></div>
      <div class="dubbing-body"><div><p class="dubbing-title">Same story. Another language.</p><p class="language-pair">English <UiAppIcon name="arrow" :size="14" /> Spanish</p></div><span class="dub-icon"><UiAppIcon name="volume" :size="20" /></span></div>
    </article>
    <p class="preview-caption">A preview of what you can make. Your files stay in your workspace.</p>
  </div>
</template>

<style scoped>
.preview-stage { position:relative; padding:16px 0 0 20px; }
.preview-glow { position:absolute; inset:-40px 0; z-index:-1; border-radius:50%; background:radial-gradient(ellipse,#6366f116,transparent 70%); pointer-events:none; }
.transcript-preview { position:relative; margin-right:28px; border-radius:20px; overflow:hidden; box-shadow:0 20px 60px #0f172a0c; }
.preview-header { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:20px; border-bottom:1px solid var(--line); }
.preview-header > span:first-child { display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; }
.preview-header svg { color:#6366f1; }
.example-label { font-size:10px; color:var(--muted); border:1px solid var(--line); border-radius:5px; padding:3px 6px; }
.waveform { display:flex; gap:5px; align-items:center; justify-content:center; height:90px; margin:20px 20px 0; border-radius:12px; background:#6366f10a; overflow:hidden; }
.waveform span { width:5px; flex-shrink:0; border-radius:5px; background:linear-gradient(#06b6d4,#6366f1); }
.transcript-body { padding:24px; display:grid; gap:24px; }
.segment-label { display:flex; align-items:center; gap:14px; font-size:11px; color:#6366f1; font-weight:600; margin-bottom:10px; }
.segment-label span { color:var(--muted); font-family:var(--font-code); font-weight:400; }
.segment > p:last-child { font-size:14px; line-height:1.75; }
.preview-footer { display:flex; flex-wrap:wrap; gap:12px; justify-content:space-between; padding:16px 20px; font-size:10px; color:var(--muted); border-top:1px solid var(--line); }
.preview-footer > span:first-child { display:flex; gap:6px; align-items:center; }
.dubbing-preview { position:relative; margin:20px 0 0 60px; overflow:hidden; border-radius:16px; box-shadow:0 12px 40px #0f172a09; }
.video-illustration { position:relative; isolation:isolate; display:flex; flex-direction:column; gap:15px; align-items:center; justify-content:center; overflow:hidden; height:142px; color:white; background:linear-gradient(125deg,#172554,#4338ca 65%,#0e7490); }
.video-illustration > svg { z-index:1; }
.video-orbit { position:absolute; width:230px; height:230px; border:1px solid #ffffff20; border-radius:50%; box-shadow:0 0 0 35px #ffffff06,0 0 0 70px #ffffff04; }
.video-illustration > span { position:relative; max-width:90%; padding:5px 10px; border-radius:4px; background:#0f172abd; font-size:11px; text-align:center; }
.dubbing-body { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:16px 20px; }
.dubbing-title { font-size:12px; font-weight:600; }
.language-pair { display:flex; align-items:center; gap:8px; font-size:11px; color:var(--muted); margin-top:8px; }
.dub-icon { display:grid; place-items:center; width:36px; height:36px; background:#06b6d410; color:#0891b2; border-radius:10px; flex-shrink:0; }
.preview-caption { text-align:center; color:var(--muted); font-size:10px; line-height:1.7; margin-top:20px; }
@media(max-width:800px) { .preview-stage { max-width:540px; width:100%; margin:auto; padding-left:0; } }
@media(max-width:380px) { .transcript-preview { margin-right:0; } .dubbing-preview { margin-left:24px; } .waveform { gap:4px; } }
</style>
