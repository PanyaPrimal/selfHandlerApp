import { jsonRequest, multipartRequest, request } from '../api/http'

export interface MentorSettings {
  enabled: boolean
  memory: string
  monthly_token_limit: number
  active_connection_id: number | null
  budget_month: string
  price_date: string
  usage: { tokens: number; reserved: number; estimated_usd: string; unpriced_requests: number }
}
export interface MentorTurn {
  id: number
  operation_id: string
  status: 'pending' | 'processing' | 'completed' | 'failed'
  question: string
  answer: string | null
  model: string
  created_at: string
  estimated_usd: string | null
  error_code: string | null
  usage: { input: number; output: number; reasoning: number; cached: number; cache_write: number; reserved: number }
  sources: Array<{ dataset: string; ids: number[]; matched: number; has_more: boolean }>
  actions: Array<{ kind: string; label: string; payload: Record<string, unknown>; status: 'pending' | 'applied' }>
}
export async function mentorSettings(): Promise<MentorSettings> { return (await request<{ data: MentorSettings }>('/mentor/settings')).data }
export async function saveMentorSettings(input: Pick<MentorSettings, 'enabled' | 'memory' | 'monthly_token_limit'>): Promise<MentorSettings> {
  return (await jsonRequest<{ data: MentorSettings }>('/mentor/settings', 'PUT', input)).data
}
export async function mentorHistory(): Promise<MentorTurn[]> { return (await request<{ data: MentorTurn[] }>('/mentor/turns')).data }
export async function askMentor(question: string, operation_id: string): Promise<MentorTurn> {
  return (await jsonRequest<{ data: MentorTurn }>('/mentor/turns', 'POST', { question, operation_id })).data
}
export async function confirmMentorAction(turn: number, action: number): Promise<MentorTurn> {
  return (await jsonRequest<{ data: MentorTurn }>(`/mentor/turns/${turn}/actions/${action}`, 'POST', { confirm: true })).data
}
export async function transcribeVoice(audio: Blob, operation: string): Promise<string> {
  const form = new FormData()
  form.append('audio', audio, `voice.${audio.type.includes('mp4') ? 'mp4' : audio.type.includes('ogg') ? 'ogg' : 'webm'}`)
  form.append('operation_id', operation)
  return (await multipartRequest<{ text: string }>('/mentor/transcribe', form)).text
}
