export const publicPages = [
  { label: 'About', to: '/about' },
  { label: 'FAQ', to: '/faq' },
  { label: 'Contact', to: '/contact' },
  { label: 'Privacy Policy', to: '/privacy-policy' },
]

// Replace these addresses with monitored inboxes before launch.
export const contactEmails = {
  support: 'support@example.com',
  privacy: 'privacy@example.com',
  business: 'hello@example.com',
}

export const faqGroups = [
  {
    title: 'Getting started',
    items: [
      { question: 'What can I do with TeeTranscribe?', answer: 'Turn recordings into editable transcripts, translate text, and dub audio or video into another language. Keep your projects together in folders and download the results when they are ready.' },
      { question: 'How do I start a transcription?', answer: 'Sign in, open your workspace, and upload an audio file, record directly in the app, or paste an accessible audio URL. Choose the spoken language and a folder, review the credit quote, and submit.' },
      { question: 'Can I use TeeTranscribe on my phone?', answer: 'Yes. The workspace adapts to smaller screens, and you can record using your browser when microphone access is available. Keep the recording page open until you finish and save your audio.' },
      { question: 'Which languages are available?', answer: 'The language choices depend on the activity and the configuration available in your workspace. Nigerian languages, including Yoruba, Igbo, Hausa, and Pidgin, are a focus alongside international languages. Check the language selector for the current options; accuracy varies with the language and recording.' },
    ],
  },
  {
    title: 'Files and results',
    items: [
      { question: 'What happens if a large upload is interrupted?', answer: 'File uploads use resumable multipart uploads. If an upload is interrupted, check Saved uploads and select the original file when prompted to continue. Keep the original file until the upload finishes. Pasted URLs do not use this upload flow.' },
      { question: 'Can I edit my transcript?', answer: 'Yes. Open a completed transcript and save your changes in the editor. Its download files are regenerated, so the download buttons may show a processing indicator before the updated files are ready.' },
      { question: 'Can I keep speaker labels and timestamps?', answer: 'When the transcription service returns speaker and timing data, the transcript can show them and offer supported export options. These features are not available for every language or transcription service.' },
      { question: 'Which formats can I download?', answer: 'Transcripts support PDF, TXT, and Word downloads. Speaker-aware downloads and subtitles depend on the data available for the project. The project view shows the files you can download and whether they are still being generated.' },
      { question: 'Why is my project pending or processing?', answer: 'Pending means the project is waiting to start. Processing means work is underway, which can include generating and uploading download files. Larger recordings and busy services can take longer. If a project stays unchanged unusually long, contact support with its project ID.' },
    ],
  },
  {
    title: 'Credits and privacy',
    items: [
      { question: 'How much does an activity cost?', answer: 'Review the credit quote shown before you submit. Costs can vary by activity, media duration, or text length. Check Credits & billing for the current purchase options and your balance.' },
      { question: 'What are reserved credits?', answer: 'Reserved credits are set aside for an accepted activity and are unavailable for another purchase while it runs. They are consumed when the billable work succeeds. Eligible unused reservations are returned if the activity fails; your credit history shows the outcome.' },
      { question: 'Where can I find my payment invoice?', answer: 'After a payment is verified and the invoice has been generated, you can download it from payment history in Credits & billing. An invoice notification is also sent to your account email.' },
      { question: 'Can I delete my files automatically?', answer: 'Yes. In Settings, choose a retention period in hours for each supported file category, or choose to keep it. Cleanup runs periodically, so deletion may happen after the selected period rather than at the exact hour.' },
      { question: 'Can I delete a whole folder?', answer: 'Yes. Deleting a folder also requests deletion of the projects and files inside it. Download anything you want to keep before confirming. You can also delete individual source files, saved text, and generated downloads.' },
    ],
  },
]

export const privacySections = [
  { id: 'information', title: 'Information we handle', paragraphs: [
    'When you create an account, we store your name, email address, and account credentials. If you sign in with Google, we receive the account information needed to identify you and complete sign-in.',
    'Your workspace holds the content you submit: audio, video, recordings, pasted text, file URLs, project names, folders, transcripts, translations, and generated downloads. We also store activity status, media duration, credit usage, and your retention preferences.',
    'We record payment references, amounts, currencies, verification results, and invoices. Payment gateways handle the card or bank details you enter during checkout.',
  ] },
  { id: 'use', title: 'How we use it', paragraphs: [
    'We use your information to run your account, process the activities you request, save results, generate downloads, and manage credits and payments. We send service emails such as verification codes, password resets, project outcomes, credit updates, and invoices.',
    'Technical records help us investigate failures and measure processing time. These can include project identifiers, file size and duration, provider messages, job errors, and request timing. Account and sign-in records may include an approximate country derived from your IP address for security and usage reporting.',
  ] },
  { id: 'services', title: 'Services involved in processing', paragraphs: [
    'The AI service configured for an activity receives the content needed to perform it. Depending on the activity, this can include your media, text, selected languages, and processing options. Cloud storage holds uploaded media and generated files; payment and email services support checkout and account notifications.',
    'These services may process information outside your country and have their own data-handling and retention terms. Deleting content from your workspace does not itself guarantee deletion from a third-party service. Contact us if you need details about the services used for a particular project.',
  ] },
  { id: 'retention', title: 'Retention and deletion', paragraphs: [
    'You control retention for supported categories in Settings, including uploaded audio and video, in-app recordings, dubbed files, PDF, TXT, Word and subtitle downloads, saved transcripts, and translations. Set a period in hours or choose to keep a category until you delete it.',
    'Automated cleanup runs periodically. A file that reaches its retention limit may remain until a cleanup job processes it. Manual deletion also runs through background jobs and may take time to finish.',
    'Deleting a source file is separate from deleting its transcript or downloads. Deleting a folder requests deletion of its contents. Save any copies you need before confirming deletion. Payment records, invoices, and operational records are managed separately from project file retention.',
  ] },
  { id: 'browser', title: 'Browser storage', paragraphs: [
    'We use browser storage to maintain your signed-in session and support features such as resuming uploads. A theme cookie remembers your display preference. Clearing browser data can sign you out or remove locally saved upload information.',
  ] },
  { id: 'choices', title: 'Your choices and questions', paragraphs: [
    'You can update your name and password, manage retention, and request deletion of individual projects or files in your workspace. Only upload content you have permission to share and process, particularly recordings involving other people.',
    'For account deletion, questions about your information, or a privacy request, contact the privacy address on our Contact page. We may need to verify your identity before acting on an account request.',
  ] },
  { id: 'updates', title: 'Updates to this policy', paragraphs: [
    'We may update this policy as the product and its processing services change. The date on this page identifies the latest revision.',
  ] },
]
