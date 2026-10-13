import type { BillingActivity, BillingCurrency } from '~/types/adminBilling'
export const billingCurrencies: BillingCurrency[] = ['NGN', 'USD']
export const billingActivities: { key: BillingActivity; label: string; unit: string }[] = [
  { key: 'transcription', label: 'Transcription', unit: 'minute' },
  { key: 'translation', label: 'Translation', unit: '1,000 input characters' },
  { key: 'video_dubbing', label: 'Video dubbing', unit: 'minute' },
  { key: 'audio_dubbing', label: 'Audio dubbing', unit: 'minute' },
  { key: 'subtitles', label: 'Subtitles', unit: 'minute' },
  { key: 'cleanup', label: 'Transcript cleanup', unit: '1,000 input characters' },
  { key: 'summary', label: 'Summaries', unit: '1,000 input characters' },
]
