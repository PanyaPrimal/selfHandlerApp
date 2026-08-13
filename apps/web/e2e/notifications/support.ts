import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const apiDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../api')
const e2eDatabase = path.join(apiDir, 'database', 'e2e.sqlite')

function phpLiteral(value: string): string {
  return value.replaceAll('\\', '\\\\').replaceAll("'", "\\'")
}
export function seedDeliveredNotification(email: string, title: string, sourceId: number): void {
  const command = [
    `$user = \\App\\Models\\User::where('email', '${phpLiteral(email)}')->firstOrFail();`,
    `\\App\\Models\\InAppNotification::create([`,
    `'user_id' => $user->id,`,
    `'source_type' => 'planned_occurrence',`,
    `'source_id' => ${sourceId},`,
    `'type' => 'routine_reminder',`,
    `'category' => 'routine',`,
    `'title' => 'Routine reminder',`,
    `'body' => '${phpLiteral(title)} is planned now.',`,
    `'action_url' => '/planner?date=2026-08-13',`,
    `'content' => ['title' => '${phpLiteral(title)}', 'date' => '2026-08-13'],`,
    `'scheduled_at' => now(),`,
    `'status' => 'sent',`,
    `'channels' => ['in_app'],`,
    `'escalation_count' => 0,`,
    `'max_escalations' => 2,`,
    `'sent_at' => now(),`,
    `]);`,
  ].join(' ')

  execFileSync('php', ['artisan', 'tinker', '--execute', command], {
    cwd: apiDir,
    stdio: 'ignore',
    env: {
      ...process.env,
      APP_ENV: 'testing',
      APP_KEY: 'base64:8mx6/PHn6hHX2o4bOMOlPxpdrJeWHdxklSX7Z92ro8Q=',
      DB_CONNECTION: 'sqlite',
      DB_DATABASE: e2eDatabase,
    },
  })
}
