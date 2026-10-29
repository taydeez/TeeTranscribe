export default defineNuxtPlugin(() => {
  const preference = useCookie<'light' | 'dark'>('theme', { default: () => 'light' })
  const theme = useState<'light' | 'dark'>('theme', () => preference.value === 'dark' ? 'dark' : 'light')
  useHead({ htmlAttrs: { 'data-theme': () => theme.value } })
})
