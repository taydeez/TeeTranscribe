export type AccountSecurityResponse = { message: string }
export type PasswordResetRequest = { email: string; token: string; password: string; password_confirmation: string }
export type EmailVerificationLink = { id: string; hash: string; expires: string; signature: string }
export type PasswordChangeRequest = { current_password: string; password: string; password_confirmation: string }
