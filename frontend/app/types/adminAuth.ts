export type AdminLoginRequest = { email: string; password: string }
export type AdminVerifyRequest = { email: string; code: string }
export type AdminLoginResponse = { requires_two_factor: boolean; email: string }
export type AdminVerifyResponse = { token: string }
