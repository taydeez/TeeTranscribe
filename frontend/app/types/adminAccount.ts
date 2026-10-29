export type AdminAccount = { id: number; name: string; username: string | null; email: string; roleId: number; roles: string[]; mustChangePassword: boolean; createdAt: string }
export type AdminAccountInput = { name: string; username: string; email: string; password: string; role_id: number }
export type AdminRole = { id: number; name: string; permissions: string[] }
export type AdminPage<T> = { data: T[]; meta: { currentPage: number; lastPage: number; total: number; perPage: number } }
