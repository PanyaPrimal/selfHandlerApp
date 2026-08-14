import type { PortabilityRestoreResult, PortabilityValidation } from '../api/types'

export interface DownloadedFile {
  blob: Blob
  filename: string
}

export interface BackupSelection {
  file: File | null
  fingerprint: string | null
  validatedFingerprint: string | null
  validation: PortabilityValidation | null
  confirmation: string
  result: PortabilityRestoreResult | null
  error: string | null
}

interface DownloadDependencies {
  createObjectUrl(blob: Blob): string
  revokeObjectUrl(url: string): void
  createAnchor(): Pick<HTMLAnchorElement, 'href' | 'download' | 'click' | 'remove'>
}

const filenamePattern = /^[^\u0000-\u001f\u007f\\/:*?"<>|]{1,180}$/u

export function contentDispositionFilename(header: string | null, fallback: string): string {
  if (!header) return fallback

  const encoded = header.match(/filename\*\s*=\s*UTF-8''([^;]+)/i)?.[1]
  const quoted = header.match(/filename\s*=\s*"([^"]*)"/i)?.[1]
  const plain = header.match(/filename\s*=\s*([^;\s]+)/i)?.[1]
  let candidate = encoded ?? quoted ?? plain

  if (!candidate) return fallback
  try {
    candidate = encoded ? decodeURIComponent(candidate) : candidate
  } catch {
    return fallback
  }

  return filenamePattern.test(candidate) && candidate !== '.' && candidate !== '..'
    ? candidate
    : fallback
}

export function saveDownloadedFile(
  download: DownloadedFile,
  deps: DownloadDependencies = {
    createObjectUrl: (blob) => URL.createObjectURL(blob),
    revokeObjectUrl: (url) => URL.revokeObjectURL(url),
    createAnchor: () => document.createElement('a'),
  },
): void {
  const url = deps.createObjectUrl(download.blob)
  const anchor = deps.createAnchor()

  try {
    anchor.href = url
    anchor.download = download.filename
    anchor.click()
  } finally {
    anchor.remove()
    deps.revokeObjectUrl(url)
  }
}

export function backupFingerprint(file: File): string {
  return [file.name, file.size, file.lastModified, file.type].join(':')
}

export function createBackupSelection(file: File | null): BackupSelection {
  return {
    file,
    fingerprint: file ? backupFingerprint(file) : null,
    validatedFingerprint: null,
    validation: null,
    confirmation: '',
    result: null,
    error: null,
  }
}

export function validatedBackupSelection(
  selection: BackupSelection,
  validation: PortabilityValidation,
): BackupSelection {
  return {
    ...selection,
    validatedFingerprint: selection.fingerprint,
    validation,
    confirmation: '',
    result: null,
    error: null,
  }
}

export function canRestoreSelection(selection: BackupSelection): boolean {
  return selection.file !== null
    && selection.fingerprint !== null
    && selection.fingerprint === selection.validatedFingerprint
    && selection.validation?.valid === true
    && selection.validation.eligible === true
    && Boolean(selection.validation.restore_token)
    && selection.confirmation === 'RESTORE'
    && selection.result === null
}
