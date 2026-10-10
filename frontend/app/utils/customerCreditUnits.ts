export function customerCreditUnits(value: string): number {
  const input = value.trim()
  if (!/^\d+(\.\d{1,2})?$/.test(input)) throw new Error('Enter credits with up to two decimal places.')
  const [whole = '0', fraction = ''] = input.split('.')
  const units = Number(whole) * 100 + Number(fraction.padEnd(2, '0'))
  if (!Number.isSafeInteger(units) || units < 1 || units > 100_000_000) throw new Error('Enter between 0.01 and 1,000,000 credits.')
  return units
}
