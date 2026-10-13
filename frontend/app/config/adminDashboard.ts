import type { DashboardChartSeries } from '~/types/adminDashboard'
export const dashboardRanges = [{ days: 7, label: '7 days' }, { days: 30, label: '30 days' }, { days: 90, label: '90 days' }]
export const dashboardEnvironments = [{ value: 'live', label: 'Live payments' }, { value: 'test', label: 'Test payments' }, { value: 'all', label: 'All payments' }]
export const creditChartSeries: DashboardChartSeries[] = [
  { key: 'credits_bought_units', label: 'Bought', color: '#4f46e5', divisor: 100 },
  { key: 'credits_used_units', label: 'Used', color: '#0d9488', divisor: 100 },
]
export const signInChartSeries: DashboardChartSeries[] = [{ key: 'sign_ins', label: 'Successful sign-ins', color: '#4f46e5' }]
export const accountChartSeries: DashboardChartSeries[] = [{ key: 'new_accounts', label: 'New accounts', color: '#0d9488' }]
export const dashboardFeatureLabels: Record<string, string> = { transcription: 'Transcription', translation: 'Translation', dubbing: 'Dubbing', subtitles: 'Subtitles', summary: 'Summaries', cleanup: 'Transcript cleanup', audio_dubbing: 'Audio dubbing', video_dubbing: 'Video dubbing' }
export const dashboardColors = ['#4f46e5', '#0d9488', '#c2410c', '#a21caf', '#0369a1', '#475569']
