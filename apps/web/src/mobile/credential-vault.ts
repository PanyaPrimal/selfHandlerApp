import { registerPlugin } from '@capacitor/core'
import { isAndroidNative, nativePlugin } from './platform'

interface MobileCredentialVaultPlugin {
  read(): Promise<{ token: string | null }>
  write(options: { token: string }): Promise<void>
  clear(): Promise<void>
}

export interface MobileCredentialVault {
  read(): Promise<string | null>
  write(token: string): Promise<void>
  clear(): Promise<void>
}

export class MobileVaultUnavailableError extends Error {
  constructor() {
    super('The Android credential vault is unavailable.')
    this.name = 'MobileVaultUnavailableError'
  }
}

export function createCredentialVault(
  plugin: MobileCredentialVaultPlugin,
  nativePlatform: () => boolean,
): MobileCredentialVault {
  function requireNative(): void {
    if (!nativePlatform()) {
      throw new MobileVaultUnavailableError()
    }
  }

  return {
    async read(): Promise<string | null> {
      requireNative()
      const result = await plugin.read()
      return typeof result.token === 'string' && result.token.length > 0 ? result.token : null
    },
    async write(token: string): Promise<void> {
      requireNative()
      if (!token) {
        throw new TypeError('A non-empty token is required.')
      }
      await plugin.write({ token })
    },
    async clear(): Promise<void> {
      requireNative()
      await plugin.clear()
    },
  }
}

const registeredPlugin = registerPlugin<MobileCredentialVaultPlugin>('MobileCredentialVault')
const plugin: MobileCredentialVaultPlugin = {
  read: () => nativePlugin('MobileCredentialVault', registeredPlugin).read(),
  write: (options) => nativePlugin('MobileCredentialVault', registeredPlugin).write(options),
  clear: () => nativePlugin('MobileCredentialVault', registeredPlugin).clear(),
}

export const mobileCredentialVault = createCredentialVault(plugin, isAndroidNative)
