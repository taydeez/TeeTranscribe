export interface DashboardDay {
  date: string
  revenue_ngn_minor: number
  revenue_usd_minor: number
  credits_bought_units: number
  credits_used_units: number
  sign_ins: number
  new_accounts: number
}
export type DashboardEnvironment = 'live' | 'test' | 'all'
export interface DashboardReport {
  from: string
  to: string
  timezone: string
  environment: DashboardEnvironment
  generated_at: string
  summary: {
    revenue: { currency: string; amount_minor: number; purchases: number }[]
    credits_bought_units: number
    credits_used_units: number
    sign_ins: number
    new_accounts: number
    unique_signing_users: number
    feature_requests: number
  }
  daily: DashboardDay[]
  features: { feature: string; requests: number }[]
  locations: { country: string; country_code: string | null; sign_ins: number }[]
}
export interface DashboardChartSeries { key: Exclude<keyof DashboardDay, 'date'>; label: string; color: string; divisor?: number }
export interface DashboardDistribution { label: string; value: number; color: string }
