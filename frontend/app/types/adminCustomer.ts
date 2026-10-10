export type AdminCustomer = {
  id: number
  name: string
  email: string
  emailVerified: boolean
  createdAt: string | null
}

export type CustomerSort = 'newest' | 'oldest' | 'name_asc' | 'name_desc'
export type AdminCustomerList = {
  data: AdminCustomer[]
  meta: { currentPage: number; lastPage: number; perPage: number; total: number }
}
export type CustomerAccessInput = { action: 'suspend' | 'block' | 'restore'; days?: number; reason: string }
export type CustomerCreditInput = { action: 'add' | 'remove'; credits: string; reason: string }
export type CustomerAdminAction = {
  id: string; action: string; reason: string; adminName: string | null; createdAt: string
  metadata: { credit_units?: number; suspended_until?: string | null }
}
export type CustomerDetail = AdminCustomer & {
  status: 'active' | 'suspended' | 'blocked'
  suspendedUntil: string | null
  restrictionReason: string | null
  signupIp: string | null
  signupLocation: { country: string; countryCode: string; region: string; city: string; source: string; resolvedAt: string } | null
  actions: CustomerAdminAction[]
}
export type CustomerDetailResponse = {
  data: CustomerDetail
  balance: { available_units: number; reserved_units: number; units_per_credit: number }
}
