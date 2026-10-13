export const logTypes = ['application', 'exception', 'job', 'provider', 'lifecycle', 'media']
export const logLevels = ['info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'debug']
export const logProviders = ['deepgram', 'intron', 'google', 'elevenlabs', 'heygen', 'openai', 'paystack', 'flutterwave']
export const logFilterKeys = ['type', 'level', 'provider', 'operation_type', 'operation_id', 'provider_request_id', 'job_id', 'search', 'from', 'to', 'sort'] as const
export type LogFilterKey = typeof logFilterKeys[number]
