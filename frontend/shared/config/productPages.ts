import type { Feature } from '../../app/types/features'

export interface ProductPage { feature: Feature; label: string; title: string; description: string; headline: string; intro: string; detail: string; benefits: string[][]; steps: string[][]; example: string[]; limit: string; faqs: string[][] }

export const productPages: Record<string, ProductPage> = {
  "/audio-to-text": {
    "feature": "transcription",
    "label": "Audio to text",
    "title": "Audio to Text Transcription Online | TeeScriber",
    "description": "Transcribe audio files, voice recordings and audio URLs. Review the text, edit your transcript, and download PDF, TXT or Word files with TeeScriber.",
    "headline": "Turn your audio into text you can work with.",
    "intro": "An interview, a lecture, a podcast, or a voice note. Bring your recording to TeeScriber and turn the spoken words into an editable transcript.",
    "detail": "Upload a file, record in your browser, or paste a direct audio link. Choose the spoken language and a folder, then review the credit quote before starting. Your original recording and transcript stay together so you can return to the project later.",
    "benefits": [
      [
        "Review before you share",
        "Read the transcript, correct names and specialist terms, and make edits before downloading your files."
      ],
      [
        "Choose your download",
        "Export plain text as TXT, a readable PDF, or an editable Word document. Speaker-labelled exports are available when the result supports speakers."
      ],
      [
        "Keep the recording under your control",
        "Delete the source when you no longer need it, or set a retention period for uploads and generated files in your account settings."
      ]
    ],
    "steps": [
      [
        "Add your audio",
        "Upload a recording, use the browser recorder, or paste a direct link that TeeScriber can access."
      ],
      [
        "Check the details",
        "Select the spoken language and a folder. Review the measured duration and the credit cost, then confirm."
      ],
      [
        "Review and download",
        "Open the finished transcript, make corrections, and choose a download format."
      ]
    ],
    "example": [
      "Interview notes",
      "Instead of replaying a whole interview to find a quotation, review the transcript and correct the passage you plan to use. Check important quotations against the original recording."
    ],
    "limit": "Transcript accuracy depends on the recording, language and available speech model. Speaker labels, timestamps and audio-synced editing are available only when the selected transcription option returns the necessary data.",
    "faqs": [
      [
        "Can I transcribe a voice recording on my phone?",
        "Yes. Sign in from your phone, allow microphone access, and keep the recording page open until you finish and save the audio. Browser support and microphone permissions can vary."
      ],
      [
        "Can I paste an audio link?",
        "Yes. Use a direct, accessible audio URL. A link to a page that contains a player may not be a direct audio file. Private links must be accessible to the service."
      ],
      [
        "Can I edit the transcript before downloading?",
        "Yes. Review and save your edits; TeeScriber regenerates the export files for the saved version."
      ],
      [
        "Is the transcription always accurate?",
        "No automated transcript is guaranteed to be exact. Review names, figures and important passages against your source audio."
      ]
    ]
  },
  "/video-to-text": {
    "feature": "transcription",
    "label": "Video to text",
    "title": "Video to Text Transcription Online | TeeScriber",
    "description": "Turn the speech in a video into an editable transcript. Review the text and download PDF, TXT or Word files for interviews, lessons and presentations.",
    "headline": "Make the words in your video easier to use.",
    "intro": "Turn the spoken audio in a video into a transcript for notes, quotations, editing, or a written version of your presentation.",
    "detail": "TeeScriber transcribes the speech in your file. It does not turn visual elements, slides, on-screen text, or silent scenes into a written description. Use the transcript as a starting point and check important details against the video.",
    "benefits": [
      [
        "Find the useful parts",
        "A written transcript gives you a way to review a recorded discussion without repeatedly playing the whole file."
      ],
      [
        "Take the text into your next draft",
        "Download Word or TXT to work on an article, lesson summary or production script in your preferred editor."
      ],
      [
        "Keep related material together",
        "Give the project a useful name and save it in a folder alongside translations and other finished media."
      ]
    ],
    "steps": [
      [
        "Choose the video file",
        "Sign in and upload a supported video containing the speech you want transcribed."
      ],
      [
        "Review the quote",
        "Select the spoken language. TeeScriber checks the duration and shows the required credits before submission."
      ],
      [
        "Edit the result",
        "Read through the transcript, make corrections, and export the text in the format you need."
      ]
    ],
    "example": [
      "A recorded presentation",
      "Use the transcript to prepare written notes or a draft article. Add details from charts and slides yourself, since those visual elements are not transcribed from speech."
    ],
    "limit": "Video-to-text creates a transcript of speech. It does not produce a dubbed video or automatically add subtitles to the original video; those are separate tools with their own quotes.",
    "faqs": [
      [
        "Does this extract the text from slides?",
        "No. This tool transcribes speech from the video audio. Review your slides separately and add any visual information your written version needs."
      ],
      [
        "Do I receive a video with subtitles?",
        "No. Your result is a transcript. Use the subtitle tool when you want captions added to a video."
      ],
      [
        "Can I download a Word document?",
        "Yes. Finished transcripts can be exported as Word, PDF or TXT."
      ],
      [
        "Do I need an account?",
        "Yes. Sign in to upload the file, confirm its quote, and keep the project and downloads in your workspace."
      ]
    ]
  },
  "/video-dubbing": {
    "feature": "video_dubbing",
    "label": "Video dubbing",
    "title": "AI Video Dubbing & Video Translation | TeeScriber",
    "description": "Dub the speech in your video into another language. Upload your video, choose a target language, review the credit cost, and download the finished result.",
    "headline": "Let your video speak another language.",
    "intro": "Give a lesson, tutorial or presentation a spoken version for another audience. Upload your video and choose the language you want listeners to hear.",
    "detail": "TeeScriber processes the spoken audio and prepares a downloadable video. Language availability and voice characteristics depend on the configured dubbing option. Review the result before publishing, especially names, numbers and specialist terms.",
    "benefits": [
      [
        "Start with your existing video",
        "Work from a recording you already have. Keep the project in a folder with the source and related material."
      ],
      [
        "Know the cost before you start",
        "Review the credit quote for the file and target language before you confirm processing."
      ],
      [
        "Return when the result is ready",
        "You can leave the result page after submission. Open the saved project later to preview or download the finished file."
      ]
    ],
    "steps": [
      [
        "Upload your video",
        "Choose a supported video file and give the project a clear name."
      ],
      [
        "Choose the new language",
        "Select an available target language, review the credit quote, and confirm the job."
      ],
      [
        "Check the dubbed result",
        "Preview the video, listen for meaning and pronunciation, and download the finished file."
      ]
    ],
    "example": [
      "A tutorial for another audience",
      "Prepare a spoken version of a product tutorial for a different language. Check technical vocabulary and any text still visible in the original video before sharing."
    ],
    "limit": "Dubbing changes speech; it does not automatically translate text inside slides, signs or graphics. Lip-sync and voice preservation depend on the available dubbing option and are not guaranteed for every project.",
    "faqs": [
      [
        "Will the text inside my video be translated?",
        "Not automatically. Review captions, diagrams and on-screen text separately when preparing the video for another audience."
      ],
      [
        "Can I choose any target language?",
        "Choose from the languages shown in the workspace. The available choices depend on the configured dubbing options."
      ],
      [
        "Can I leave the page while it processes?",
        "Yes. Once you have confirmed the job, it remains saved in your account. You can return to the project to check its status."
      ],
      [
        "Are translated subtitles the same as dubbing?",
        "No. Subtitles display translated text, while dubbing creates spoken audio in the target language. Each workflow shows its own credit quote."
      ]
    ]
  },
  "/audio-dubbing": {
    "feature": "audio_dubbing",
    "label": "Audio dubbing",
    "title": "AI Audio Dubbing & Audio Translation | TeeScriber",
    "description": "Dub a recording into another language. Upload audio, choose an available target language, review the credit quote, and listen to the finished result.",
    "headline": "Give your recording a version in another language.",
    "intro": "Prepare a spoken translation of an interview, lesson, announcement or other audio recording without starting with a video.",
    "detail": "Upload the audio, select a target language and review the quote. When processing finishes, listen to the result and download it from the project. Voice characteristics and language support depend on the selected dubbing configuration.",
    "benefits": [
      [
        "Work with audio directly",
        "Use an existing recording rather than packaging it as a video first."
      ],
      [
        "Review the spoken result",
        "Listen before you share. Check names, numbers, tone and technical language with someone who understands the target language when possible."
      ],
      [
        "Keep every project organised",
        "Save the recording under a clear project name and folder so you can find the finished audio again."
      ]
    ],
    "steps": [
      [
        "Add your recording",
        "Sign in and choose Audio only in the media workspace. Upload a supported audio file."
      ],
      [
        "Select a language",
        "Choose the available target language and review the credit cost before confirming."
      ],
      [
        "Listen and download",
        "Return to the saved project, preview the dubbed audio, and download the available audio file."
      ]
    ],
    "example": [
      "An audio lesson",
      "Create a spoken version of a short lesson for another language group. Review the translation and pronunciation before distributing it to learners."
    ],
    "limit": "Audio dubbing produces audio. It does not create a presenter, add video visuals or provide a certified translation. Review the finished result for the purpose you intend to use it for.",
    "faqs": [
      [
        "Do I need to upload a video?",
        "No. Choose Audio only and upload a supported audio file."
      ],
      [
        "Will the new audio sound exactly like the source?",
        "Voice characteristics depend on the available dubbing option and the recording. Do not assume an exact match; listen to the result before sharing it."
      ],
      [
        "Where is the finished recording saved?",
        "It remains in your account as a project in the folder you selected. Open it to preview and download the available audio file."
      ],
      [
        "Is it suitable for an important public announcement?",
        "Review the output with a fluent speaker before publishing. Automated translation and speech generation can make errors."
      ]
    ]
  },
  "/add-subtitles-to-video": {
    "feature": "subtitles",
    "label": "Video subtitles",
    "title": "Add Translated Subtitles to Video | TeeScriber",
    "description": "Add subtitles in another language while keeping your original video audio. Choose a caption style and download a captioned video and separate SRT file.",
    "headline": "Keep the voice. Add another way to follow along.",
    "intro": "Add translated subtitles to a video while keeping its original spoken audio. Choose the subtitle language and a preset style before processing.",
    "detail": "The subtitle workflow turns speech into timed text, translates it when needed, and renders the captions onto the video. You receive the captioned result and an SRT subtitle file when processing completes.",
    "benefits": [
      [
        "Keep the original audio",
        "A subtitled version lets viewers read the translated speech while still hearing the original speaker."
      ],
      [
        "Choose a readable preset",
        "Select from the subtitle styles available in the workspace. Keep captions readable against the content in your video."
      ],
      [
        "Download the separate captions",
        "Use the SRT file where a platform supports uploading a subtitle track, or use the rendered video for permanent captions."
      ]
    ],
    "steps": [
      [
        "Choose a video",
        "Sign in, upload the video, and select Add subtitles only."
      ],
      [
        "Set the language and style",
        "Choose the spoken language, target subtitle language and a preset. Review the quote before confirming."
      ],
      [
        "Download your captioned version",
        "Preview the result and download the rendered video and available SRT file."
      ]
    ],
    "example": [
      "An English video with Spanish subtitles",
      "Keep the English speech in the original recording and choose Spanish for the subtitle language. Check the finished captions for names, timing and readability before uploading the video elsewhere."
    ],
    "limit": "Captions rendered onto an MP4 are permanent. Use the separate SRT file on platforms that support selectable subtitles. Automatic speech recognition and translation can introduce mistakes.",
    "faqs": [
      [
        "Will my original audio change?",
        "The subtitles-only workflow keeps the original audio and adds captions to the video."
      ],
      [
        "Can I turn off captions in the rendered video?",
        "No. Rendered captions are part of the video image. Use the separate SRT file on a platform that supports selectable caption tracks."
      ],
      [
        "Can the subtitles be in a different language?",
        "Yes. Select an available target subtitle language before requesting the quote."
      ],
      [
        "Can I customise every caption style?",
        "The workspace offers a few preset styles. It does not provide a full motion-graphics or frame-by-frame caption editor."
      ]
    ]
  },
  "/text-translation": {
    "feature": "translation",
    "label": "Text translation",
    "title": "Translate Text & Transcripts Online | TeeScriber",
    "description": "Translate text or a saved transcript into an available language. Review the credit quote, edit the translated result and download PDF, TXT or Word files.",
    "headline": "Take your text into another language.",
    "intro": "Translate a passage you paste into the workspace or continue from a transcript you have already created in TeeScriber.",
    "detail": "Choose the source and target languages, review the credit quote, then confirm the translation. Review and edit the result before downloading. The workspace lists Nigerian languages alongside international language options where supported.",
    "benefits": [
      [
        "Start from text or a transcript",
        "Paste the passage you need or send text from a transcription result to the translation workspace."
      ],
      [
        "Review the meaning",
        "Read the translated text and correct terminology before sharing it. Important material deserves a fluent speaker’s review."
      ],
      [
        "Download the format you need",
        "Save the edited result and download the available PDF, TXT or Word export from the project."
      ]
    ],
    "steps": [
      [
        "Add the source text",
        "Paste your text or open the translation tool from a transcript."
      ],
      [
        "Choose the language",
        "Select the source and target languages and review the credit quote before confirming."
      ],
      [
        "Review and save",
        "Check the translated result, save your edits, and download your files."
      ]
    ],
    "example": [
      "Sharing an interview extract",
      "Translate a reviewed passage from a transcript so someone working in another language can read it. Retain the original wording alongside the translation for reference."
    ],
    "limit": "Text translation does not generate speech or a dubbed recording. It is not a certified translation service; use a qualified reviewer when legal, medical or other high-stakes accuracy is needed.",
    "faqs": [
      [
        "Can I translate a transcript without copying it?",
        "Yes. Open the transcript and use Translate this text to move the current text into the translation workspace."
      ],
      [
        "How is the credit quote calculated?",
        "Text activities are priced by the configured text unit. The workspace shows the required credits before you confirm."
      ],
      [
        "Can I edit the result?",
        "Yes. Save your corrections to regenerate the download files for the edited version."
      ],
      [
        "Does this create translated audio?",
        "No. Use the audio or video dubbing tools when you need spoken output in another language."
      ]
    ]
  }
}
