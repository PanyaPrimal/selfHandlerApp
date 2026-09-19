import { jsonRequest, request } from '../api/http'
export interface ChatGptLogin { verification_url: string; user_code: string; expires_at: number }
export interface ChatGptStatus {
  available: boolean; connected: boolean; email?: string | null; plan?: string | null; login?: ChatGptLogin | null
  limits?: { primary?: { usedPercent: number }; secondary?: { usedPercent: number } } | null
}
export interface ChatGptModel { id: string; name: string; default: boolean }
export async function chatGptStatus() { return (await request<{ data: ChatGptStatus }>('/mentor/chatgpt')).data }
export async function chatGptLogin() { return (await jsonRequest<{ data: ChatGptLogin }>('/mentor/chatgpt/login', 'POST', {})).data }
export async function chatGptLogout() { await jsonRequest('/mentor/chatgpt/logout', 'POST', {}) }
export async function chatGptModels() { return (await request<{ data: { models: ChatGptModel[] } }>('/mentor/chatgpt/models')).data.models }
