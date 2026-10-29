export type BillingCurrency = 'NGN' | 'USD'
export type BillingActivity = 'transcription' | 'translation' | 'video_dubbing' | 'audio_dubbing' | 'subtitles' | 'cleanup' | 'summary'
export interface BillingPackage { code: string; name: string; credits: string; price_ngn: string; enabled: boolean }
export interface BillingConfiguration {
  packages: BillingPackage[]
  free_credits: string
  fx: { ngn_per_usd: string | null; updated_at: string | null; max_age_hours: number }
  quote_minutes: number
  checkout_minutes: number
  processing_timeout_hours: number
  task_deadlines: Record<BillingActivity, Record<string, { base_minutes: number; duration_multiplier: number; max_minutes: number }>>
  concurrency: { overall: number; transcription: number; translation: number; dubbing: number; transcript_tools: number }
  minimum_checkout: Record<BillingCurrency, string>
  enabled_currencies: BillingCurrency[]
  gateways: Record<'paystack' | 'flutterwave', { enabled: boolean; currencies: BillingCurrency[] }>
  activities: Record<BillingActivity, { mode: 'model' | 'activity'; credits: string | null }>
}
export interface BillingChange { id: string; actorName: string | null; reason: string; createdAt: string; before: BillingConfiguration; after: BillingConfiguration }
export interface BillingSettingsRecord { configuration: BillingConfiguration; version: number; updatedAt: string | null; credit_value_ngn: string; activity_units: Record<BillingActivity, string>; history: BillingChange[] }
export interface BillingReport {
  from: string; until_exclusive: string
  payments: { currency: BillingCurrency; purchases: number; revenue_minor: number; known_fees_minor: number; credit_units: number }[]
  usage: { activity: string; provider: string; model: string; status: string; jobs: number; credit_units: number; provider_currency: BillingCurrency | null; costed_jobs: number; estimated_provider_cost_micros: number | null }[]
  ledger: { kind: string; entries: number; credit_units: number }[]
}
