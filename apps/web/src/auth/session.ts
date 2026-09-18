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
import { activateWorkspace, forgetWorkspaceSession, rememberedWorkspaceUser, rememberWorkspaceUser, synchronizeWorkspace, workspaceState } from '../offline/workspace'

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
  activateWorkspace(user?.id ?? null)

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
  void rememberWorkspaceUser(user).catch(() => undefined)
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
      try { await rememberWorkspaceUser(user) } catch { /* online session can operate without persistence */ }
      void synchronizeWorkspace()
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        await forgetWorkspaceSession().catch(() => undefined)
        becomeGuest()
      } else if (error instanceof ApiError && error.status === 0) {
        const remembered = await rememberedWorkspaceUser().catch(() => undefined)
        if (remembered) { replaceUser(remembered, 'authenticated'); workspaceState.online = false }
        else becomeUnavailable()
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
  try { await rememberWorkspaceUser(user) } catch { /* persistence error is reported by offline workspace */ }
  return user
}

export async function login(payload: LoginPayload): Promise<User> {
  const user = await loginAccount(payload)
  restored = true
  replaceUser(user, 'authenticated')
  try { await rememberWorkspaceUser(user) } catch { /* persistence error is reported by offline workspace */ }
  void synchronizeWorkspace()
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
  await forgetWorkspaceSession().catch(() => undefined)
  becomeGuest()
}

export async function expireSession(): Promise<void> {
  if (isAndroidNative()) {
    try { await mobileCredentialVault.clear() } catch { /* already unavailable or cleared */ }
  }
  restored = true
  await forgetWorkspaceSession().catch(() => undefined)
  becomeGuest()
}
