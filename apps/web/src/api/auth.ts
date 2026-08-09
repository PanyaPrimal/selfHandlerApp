import { jsonRequest, request } from './http'
import type { ItemResponse, LoginPayload, RegisterPayload, User } from './types'

export async function registerAccount(payload: RegisterPayload): Promise<User> {
  const response = await jsonRequest<ItemResponse<User>>('/auth/register', 'POST', payload, {
    handleUnauthorized: false,
  })

  return response.data
}

export async function loginAccount(payload: LoginPayload): Promise<User> {
  const response = await jsonRequest<ItemResponse<User>>('/auth/login', 'POST', payload, {
    handleUnauthorized: false,
  })

  return response.data
}

export async function getCurrentUser(): Promise<User> {
  const response = await request<ItemResponse<User>>('/auth/user', {}, { handleUnauthorized: false })
  return response.data
}

export async function logoutAccount(): Promise<void> {
  await request<void>('/auth/logout', { method: 'POST' }, { handleUnauthorized: false })
}
