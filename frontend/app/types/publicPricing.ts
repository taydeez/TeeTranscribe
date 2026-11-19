import type { Feature } from './features'
export interface PublicPricingCatalog {
  packages: { id: string; name: string; credit_units: number; amount_minor: number; currency: 'USD' }[]
  rates: { activity: Feature; unit: 'minute' | '1000_characters'; credit_units: number | null }[]
  free_credit_units: number
}
