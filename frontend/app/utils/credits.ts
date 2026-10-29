export function formatCredits(units: number): string {
  return new Intl.NumberFormat('en-NG', { maximumFractionDigits: 2 }).format(units / 100)
}

export function formatMoney(minor: number, currency: string): string {
  return new Intl.NumberFormat(currency === 'NGN' ? 'en-NG' : 'en-US', { style: 'currency', currency }).format(minor / 100)
}

export function suggestedCurrency(timeZone: string): 'NGN' | 'USD' {
  return timeZone === 'Africa/Lagos' ? 'NGN' : 'USD'
}
