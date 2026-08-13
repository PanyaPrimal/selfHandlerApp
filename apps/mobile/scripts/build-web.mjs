import { spawnSync } from 'node:child_process'
import { configuredApiOrigin } from './mobile-config.mjs'

const origin = configuredApiOrigin()
const npmCli = process.env.npm_execpath
if (!npmCli) throw new Error('Run this build through npm so the package manager can be located safely.')
const result = spawnSync(process.execPath, [npmCli, '--prefix', '../web', 'run', 'build'], {
  cwd: import.meta.dirname + '/..',
  env: { ...process.env, VITE_MOBILE_API_ORIGIN: origin },
  stdio: 'inherit',
})

if (result.error) throw result.error
process.exitCode = result.status ?? 1
