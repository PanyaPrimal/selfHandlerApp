import { describe, expect, it, vi } from 'vitest'
import { createCredentialVault, MobileVaultUnavailableError } from './credential-vault'

describe('mobile credential vault', () => {
  it('delegates token persistence only to the native plugin', async () => {
    const plugin = {
      read: vi.fn().mockResolvedValue({ token: 'vaulted-token' }),
      write: vi.fn().mockResolvedValue(undefined),
      clear: vi.fn().mockResolvedValue(undefined),
    }
    const local = vi.spyOn(Storage.prototype, 'setItem')
    const session = vi.spyOn(window.sessionStorage, 'setItem')
    const vault = createCredentialVault(plugin, () => true)

    await expect(vault.read()).resolves.toBe('vaulted-token')
    await vault.write('new-token')
    await vault.clear()

    expect(plugin.write).toHaveBeenCalledWith({ token: 'new-token' })
    expect(plugin.clear).toHaveBeenCalledOnce()
    expect(local).not.toHaveBeenCalled()
    expect(session).not.toHaveBeenCalled()
  })

  it('has no browser or missing-plugin persistence fallback', async () => {
    const plugin = {
      read: vi.fn(),
      write: vi.fn(),
      clear: vi.fn(),
    }
    const vault = createCredentialVault(plugin, () => false)

    await expect(vault.read()).rejects.toBeInstanceOf(MobileVaultUnavailableError)
    await expect(vault.write('secret')).rejects.toBeInstanceOf(MobileVaultUnavailableError)
    await expect(vault.clear()).rejects.toBeInstanceOf(MobileVaultUnavailableError)
    expect(plugin.read).not.toHaveBeenCalled()
  })
})
