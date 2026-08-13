import { CapacitorHttp } from '@capacitor/core'
import { Device } from '@capacitor/device'
import { jsonRequest, request } from './http'
import { ApiError } from './http'
import type {
  ItemResponse,
  LoginPayload,
  MobileCurrentSessionResponse,
  MobileSessionResponse,
  RegisterPayload,
  User,
} from './types'
import { mobileCredentialVault } from '../mobile/credential-vault'
import {
  configuredMobileApiOrigin,
  isAndroidNative,
  mobileApiBaseUrl,
  nativePlugin,
} from '../mobile/platform'

export async function registerAccount(payload: RegisterPayload): Promise<User> {
  const response = await jsonRequest<ItemResponse<User>>('/auth/register', 'POST', payload, {
    handleUnauthorized: false,
  })

  return response.data
}

export async function loginAccount(payload: LoginPayload): Promise<User> {
  if (isAndroidNative()) {
    const info = await nativePlugin('Device', Device).getInfo()
    const deviceName = (info.model ?? 'Android device')
      .replace(/[\u0000-\u001f\u007f]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .slice(0, 64) || 'Android device'
    const response = await jsonRequest<MobileSessionResponse>('/mobile/session', 'POST', {
      ...payload,
      device_name: deviceName,
    }, {
      handleUnauthorized: false,
      mobileAuthenticated: false,
    })

    try {
      await mobileCredentialVault.write(response.data.token)
    } catch (error) {
      try {
        await nativePlugin('CapacitorHttp', CapacitorHttp).request({
          url: `${mobileApiBaseUrl(configuredMobileApiOrigin())}/mobile/session`,
          method: 'DELETE',
          headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${response.data.token}`,
          },
        })
      } catch {
        // Best effort: the server token still expires absolutely after 30 days.
      }
      throw error
    }

    return response.data.user
  }

  const response = await jsonRequest<ItemResponse<User>>('/auth/login', 'POST', payload, {
    handleUnauthorized: false,
  })

  return response.data
}

export async function getCurrentUser(): Promise<User> {
  if (isAndroidNative()) {
    if (await mobileCredentialVault.read() === null) {
      throw new ApiError('Unauthenticated.', 401)
    }

    try {
      const response = await request<MobileCurrentSessionResponse>(
        '/mobile/session',
        {},
        { handleUnauthorized: false },
      )
      return response.data.user
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        await mobileCredentialVault.clear()
      }
      throw error
    }
  }

  const response = await request<ItemResponse<User>>('/auth/user', {}, { handleUnauthorized: false })
  return response.data
}

export async function logoutAccount(): Promise<void> {
  if (isAndroidNative()) {
    try {
      await request<void>('/mobile/session', { method: 'DELETE' }, { handleUnauthorized: false })
      await mobileCredentialVault.clear()
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        await mobileCredentialVault.clear()
      }
      throw error
    }
    return
  }

  await request<void>('/auth/logout', { method: 'POST' }, { handleUnauthorized: false })
}
