import { describe, expect, it, vi } from 'vitest'
import {
  canRestoreSelection,
  contentDispositionFilename,
  createBackupSelection,
  saveDownloadedFile,
  validatedBackupSelection,
} from '../portability/files'
import type { PortabilityValidation } from '../api/types'

const eligibleValidation: PortabilityValidation = {
  valid: true,
  eligible: true,
  schema_version: 1,
  archive_sha256: 'a'.repeat(64),
  backup_id: '11111111-1111-4111-8111-111111111111',
  created_at: '2026-08-14T10:30:00Z',
  counts: { records_by_table: { routines: 2 }, total_records: 2, attachments: 1, total_bytes: 48 },
  exclusions: ['account_credentials'],
  issues: [],
  restore_token: 'signed-token',
  expires_at: '2026-08-14T10:40:00Z',
}

describe('portability file contracts', () => {
  it('accepts safe RFC 5987 download names and rejects path-like server names', () => {
    expect(contentDispositionFilename(
      "attachment; filename*=UTF-8''selfhandler-analytics-sleep%20duration.csv",
      'report.csv',
    )).toBe('selfhandler-analytics-sleep duration.csv')
    expect(contentDispositionFilename('attachment; filename="../private.zip"', 'backup.zip')).toBe('backup.zip')
    expect(contentDispositionFilename('attachment; filename="report\r\n.csv"', 'report.csv')).toBe('report.csv')
  })

  it('uses and releases a temporary object URL for browser downloads', () => {
    const anchor = { href: '', download: '', click: vi.fn(), remove: vi.fn() }
    const deps = {
      createObjectUrl: vi.fn(() => 'blob:download'),
      revokeObjectUrl: vi.fn(),
      createAnchor: vi.fn(() => anchor),
    }

    saveDownloadedFile({ blob: new Blob(['csv']), filename: 'analytics.csv' }, deps)

    expect(anchor).toMatchObject({ href: 'blob:download', download: 'analytics.csv' })
    expect(anchor.click).toHaveBeenCalledOnce()
    expect(anchor.remove).toHaveBeenCalledOnce()
    expect(deps.revokeObjectUrl).toHaveBeenCalledWith('blob:download')
  })

  it('clears every stale validation and result when the selected file changes', () => {
    const first = new File(['first'], 'first.zip', { type: 'application/zip', lastModified: 1 })
    const second = new File(['second'], 'second.zip', { type: 'application/zip', lastModified: 2 })
    const accepted = validatedBackupSelection(createBackupSelection(first), eligibleValidation)
    accepted.confirmation = 'RESTORE'
    accepted.result = { archive_sha256: 'a'.repeat(64), records_by_table: {}, total_records: 0, attachments: 0 }

    const changed = createBackupSelection(second)

    expect(changed.fingerprint).not.toBe(accepted.fingerprint)
    expect(changed.validation).toBeNull()
    expect(changed.confirmation).toBe('')
    expect(changed.result).toBeNull()
    expect(changed.error).toBeNull()
  })

  it('allows restore only for the current eligible token and exact confirmation', () => {
    const file = new File(['backup'], 'backup.zip', { type: 'application/zip', lastModified: 1 })
    const selection = validatedBackupSelection(createBackupSelection(file), eligibleValidation)

    selection.confirmation = 'restore'
    expect(canRestoreSelection(selection)).toBe(false)
    selection.confirmation = 'RESTORE'
    expect(canRestoreSelection(selection)).toBe(true)
    selection.fingerprint = 'changed-file'
    expect(canRestoreSelection(selection)).toBe(false)
  })
})
