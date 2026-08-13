import { reactive, readonly } from 'vue'
import {
  getCurrentUser,
  loginAccount,
  logoutAccount,
  registerAccount,
} from '../api/auth'
import { ApiError, resetCsrfProtection } from '../api/http'
import type { LoginPayload, RegisterPayload, User } from '../api/types'
import { syncThemeFromProfile } from '../theme'
import { syncLocaleFromProfile } from '../i18n'
import { mobileCredentialVault } from '../mobile/credential-vault'
import { isAndroidNative } from '../mobile/platform'

export type SessionStatus = 'checking' | 'authenticated' | 'guest' | 'unavailable'

interface SessionState {
  status: SessionStatus
  user: User | null
  generation: number
}

const state = reactive<SessionState>({
  status: 'checking',
  user: null,
  generation: 0,
})
const publicState = readonly(state)

let restored = false
let restoration: Promise<void> | null = null

function replaceUser(user: User | null, status: SessionStatus): void {
  const previousUserId = state.user?.id ?? null
  const nextUserId = user?.id ?? null

  state.user = user
  state.status = status

  if (user) {
    syncThemeFromProfile(user.preferences.theme)
    syncLocaleFromProfile(user.preferences.locale)
  }

  if (previousUserId !== nextUserId) {
    state.generation += 1
  }
}

function becomeGuest(): void {
  replaceUser(null, 'guest')
  resetCsrfProtection()
}

function becomeUnavailable(): void {
  replaceUser(null, 'unavailable')
}

export function useAuthSession(): Readonly<SessionState> {
  return publicState
}

export function updateAuthenticatedUser(user: User): void {
  replaceUser(user, 'authenticated')
}

export function restoreSession(force = false): Promise<void> {
  if (!force && restored) {
    return Promise.resolve()
  }

  if (restoration) {
    return restoration
  }

  state.status = 'checking'

  const request = (async () => {
    try {
      const user = await getCurrentUser()
      replaceUser(user, 'authenticated')
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        becomeGuest()
      } else {
        becomeUnavailable()
      }
    } finally {
      restored = true
    }
  })()

  restoration = request

  return request.finally(() => {
    if (restoration === request) {
      restoration = null
    }
  })
}

export async function register(payload: RegisterPayload): Promise<User> {
  const user = await registerAccount(payload)
  restored = true
  replaceUser(user, 'authenticated')
  return user
}

export async function login(payload: LoginPayload): Promise<User> {
  const user = await loginAccount(payload)
  restored = true
  replaceUser(user, 'authenticated')
  return user
}

export async function logout(): Promise<void> {
  try {
    await logoutAccount()
  } catch (error) {
    if (!(error instanceof ApiError) || error.status !== 401) {
      throw error
    }
  }

  restored = true
  becomeGuest()
}

export async function expireSession(): Promise<void> {
  if (isAndroidNative()) {
    try { await mobileCredentialVault.clear() } catch { /* already unavailable or cleared */ }
  }
  restored = true
  becomeGuest()
}
