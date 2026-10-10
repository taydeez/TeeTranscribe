export interface AIProviderSettings { enabled: boolean; model: string; languages: string[] }
export interface AIModelDefinition { id: string; label: string; enabled: boolean; languages: string[]; capabilities: { speakers: boolean; timestamps: boolean }; pricing: { unit: 'minute' | 'character' | '1000_characters'; credits: string | null; provider_cost: string | null; provider_currency: 'USD' | 'NGN' } }
export type AILanguageRule = string | { provider: string; model: string }
export interface AIProviderConfiguration { default_provider: string; providers: Record<string, AIProviderSettings>; language_rules: Record<string, AILanguageRule>; models: Record<string, AIModelDefinition[]> }
export interface AIProviderDefinition { name: string; model: string; models: string[]; model_catalog: AIModelDefinition[]; custom_models: boolean; allowed_model_ids: string[]; configured: boolean; languages: string[]; capabilities: string[] }
export interface AIProviderChange { id: string; actorName: string | null; reason: string; createdAt: string; before: AIProviderConfiguration; after: AIProviderConfiguration }
export interface AIActivityConfiguration { activity: string; name?: string; version: number; configuration: AIProviderConfiguration; catalog: Record<string, AIProviderDefinition>; updatedAt: string | null; history: AIProviderChange[] }
