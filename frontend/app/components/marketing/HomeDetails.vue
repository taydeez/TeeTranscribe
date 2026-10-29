<script setup lang="ts">
defineEmits<{ start: [activity: 'transcription' | 'dubbing'] }>()
const questions = [
  { question: 'Do I need an account?', answer: 'Yes. Sign in to transcribe audio, dub videos, or translate text. Your account keeps your projects, downloads, and credits together.' },
  { question: 'What do I get when I sign up?', answer: 'New accounts get 10 free credits to try the tools. Before you start a task, you’ll see how many credits it needs. Different activities and file lengths can cost different amounts.' },
  { question: 'Can I edit and download my transcript?', answer: 'Yes. Review the text, make changes, and download it as PDF, TXT, or Word. Speaker labels and timestamps are available with supported transcription options.' },
  { question: 'Can I add subtitles to a video?', answer: 'Yes. You can add subtitles in another language while keeping the original audio, or choose video dubbing to translate the spoken audio.' },
  { question: 'Can I delete my files?', answer: 'Yes. Delete source files and finished projects when you need to, or set retention times in your account settings.' },
]
</script>

<template>
  <section id="tools" class="home-section" aria-labelledby="tools-title">
    <div class="section-intro"><p class="eyebrow">FROM THE FIRST RECORDING TO THE FINAL DOWNLOAD</p><h2 id="tools-title">Less retyping. More reaching people.</h2><p>An interview to write up. A tutorial to share abroad. Start with the file you have.</p></div>
    <div class="tool-grid">
      <article class="tool-card">
        <span class="feature-icon"><UiAppIcon name="transcript" :size="24" /></span>
        <h3>Audio to text, ready to work with.</h3>
        <p>Upload an audio file, paste a link, or record in your browser. Get a transcript you can read, edit, and turn into something useful.</p>
        <ul><li><UiAppIcon name="check" :size="16" /> Interviews, meetings, podcasts, and voice notes</li><li><UiAppIcon name="check" :size="16" /> Speaker labels with supported options</li><li><UiAppIcon name="check" :size="16" /> PDF, TXT, and Word downloads</li></ul>
        <button class="tool-link" type="button" @click="$emit('start', 'transcription')">Start a transcription <UiAppIcon name="arrow" :size="17" /></button>
      </article>
      <article class="tool-card dubbing-card">
        <span class="feature-icon" data-accent="cyan"><UiAppIcon name="video" :size="24" /></span>
        <h3>Your video. A new language.</h3>
        <p>Give tutorials, presentations, and stories a voice in another language. Choose your target language and download your dubbed video when it’s ready.</p>
        <ul><li><UiAppIcon name="check" :size="16" /> Translate the speech in your video</li><li><UiAppIcon name="check" :size="16" /> Add subtitles for your audience</li><li><UiAppIcon name="check" :size="16" /> Keep the finished file in your project folder</li></ul>
        <button class="tool-link" type="button" @click="$emit('start', 'dubbing')">Start a video dub <UiAppIcon name="arrow" :size="17" /></button>
      </article>
    </div>
    <div class="supporting-tools"><span class="feature-icon"><UiAppIcon name="translate" /></span><p><strong>Keep going with your words.</strong> Translate a transcript, clean up the text, or create a summary without starting from scratch.</p></div>
  </section>
  <section id="how-it-works" class="home-section steps-section" aria-labelledby="steps-title">
    <div class="section-intro"><p class="eyebrow">THREE STEPS, ONE WORKSPACE</p><h2 id="steps-title">Bring a file. Leave with something useful.</h2></div>
    <ol class="steps-grid">
      <li><span class="step-number">01</span><h3>Create your account</h3><p>Sign up for 10 free credits, then sign in to your workspace. All tools require an account.</p></li>
      <li><span class="step-number">02</span><h3>Choose what you want to make</h3><p>Add your audio or video and choose a language. Check the credit cost before you confirm.</p></li>
      <li><span class="step-number">03</span><h3>Review and download</h3><p>Edit your transcript or download your dubbed video. Keep everything organised in folders.</p></li>
    </ol>
  </section>
  <section id="questions" class="home-section faq-section" aria-labelledby="questions-title">
    <div class="section-intro"><p class="eyebrow">A FEW THINGS TO KNOW</p><h2 id="questions-title">Before you press upload.</h2><NuxtLink to="/faq" class="tool-link">More questions? Visit our FAQ <UiAppIcon name="arrow" :size="16" /></NuxtLink></div>
    <div class="questions"><details v-for="item in questions" :key="item.question"><summary>{{ item.question }}<UiAppIcon name="plus" :size="18" /></summary><p>{{ item.answer }}</p></details></div>
  </section>
</template>

<style scoped>
.home-section { padding-bottom:80px; scroll-margin-top:24px; }
.section-intro { margin-bottom:32px; }
.section-intro h2 { font-size:clamp(26px,3vw,36px); line-height:1.3; letter-spacing:-.04em; font-weight:700; }
.section-intro > p:last-child { margin-top:12px; font-size:15px; line-height:1.8; color:var(--muted); }
.tool-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:24px; }
.tool-card { padding:36px; border:1px solid var(--line); border-radius:20px; background:var(--surface); transition:transform .2s,box-shadow .2s; }
.tool-card:hover { transform:translateY(-3px); box-shadow:0 10px 32px #0f172a08; }
.tool-card h3 { margin-top:24px; font-size:24px; font-weight:700; letter-spacing:-.035em; line-height:1.35; }
.tool-card > p { margin-top:16px; color:var(--muted); line-height:1.85; font-size:14px; }
.tool-card ul { display:grid; gap:12px; margin-top:24px; }
.tool-card li { display:flex; align-items:flex-start; gap:10px; font-size:13px; line-height:1.7; }
.tool-card li svg { flex-shrink:0; margin-top:3px; color:#6366f1; }
.dubbing-card li svg { color:#0891b2; }
.tool-link { display:inline-flex; gap:8px; align-items:center; min-height:44px; margin-top:20px; color:#6366f1; font-size:13px; font-weight:600; }
.tool-link:hover { text-decoration:underline; text-underline-offset:4px; }
.supporting-tools { display:flex; align-items:center; gap:16px; margin-top:20px; border:1px solid var(--line); border-radius:16px; padding:20px 24px; }
.supporting-tools > span { flex-shrink:0; }
.supporting-tools p { font-size:13px; line-height:1.8; color:var(--muted); }
.supporting-tools strong { color:var(--text); }
.steps-section { padding-top:48px; border-top:1px solid var(--line); }
.steps-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:40px; }
.step-number { display:inline-grid; place-items:center; width:40px; height:40px; border-radius:50%; background:#6366f10d; color:#6366f1; font-size:12px; font-weight:600; }
.steps-grid h3 { margin-top:20px; font-size:17px; font-weight:700; letter-spacing:-.02em; }
.steps-grid p { margin-top:12px; color:var(--muted); font-size:14px; line-height:1.85; }
.faq-section { display:grid; grid-template-columns:1fr 1.3fr; gap:64px; }
.questions details { border-bottom:1px solid var(--line); }
.questions details:first-child { border-top:1px solid var(--line); }
.questions summary { display:flex; justify-content:space-between; align-items:center; gap:16px; cursor:pointer; padding:20px 0; font-size:14px; font-weight:600; list-style:none; }
.questions summary::-webkit-details-marker { display:none; }
.questions summary svg { flex-shrink:0; color:var(--muted); transition:transform .2s; }
.questions details[open] summary svg { transform:rotate(45deg); }
.questions details > p { padding:0 28px 20px 0; font-size:14px; line-height:1.85; color:var(--muted); }
@media(max-width:800px) { .home-section { padding-bottom:56px; } .faq-section { grid-template-columns:1fr; gap:0; } .tool-card { padding:24px; } .steps-grid { gap:24px; } }
@media(max-width:640px) { .tool-grid,.steps-grid { grid-template-columns:1fr; } .steps-grid { gap:32px; } .supporting-tools { align-items:flex-start; padding:20px; } .steps-section { padding-top:32px; } }
</style>
