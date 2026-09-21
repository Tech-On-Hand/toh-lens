// Runs the Student Agent's real bridge/sync code against a real Laravel server.
// The backend uses a throwaway SQLite database in a temp directory, so your
// local MySQL data is never touched, and nothing is written to Windows
// Credential Manager (the agent code is driven with an explicit token).
//
//   node e2e/full-stack.mjs
import { execFileSync, spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import net from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const repo = join(import.meta.dirname, '..');
const backend = join(repo, 'backend');
const work = mkdtempSync(join(tmpdir(), 'toh-klas-fullstack-'));
const database = join(work, 'e2e.sqlite');
writeFileSync(database, '');

const env = {
  ...process.env,
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: database,
  DB_URL: '',
  BROADCAST_CONNECTION: 'null',
  QUEUE_CONNECTION: 'sync',
  CACHE_STORE: 'array',
  SESSION_DRIVER: 'array',
};

const artisan = (...args) => spawnSync('php', ['artisan', ...args], { cwd: backend, env, encoding: 'utf8' });

async function freePort() {
  const server = net.createServer();
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const { port } = server.address();
  await new Promise((resolve) => server.close(resolve));
  return port;
}

let server;
let exitCode = 1;
try {
  console.log('- migrating a throwaway database');
  const migrated = artisan('migrate', '--force');
  if (migrated.status !== 0) throw new Error(`migrate failed:\n${migrated.stdout}${migrated.stderr}`);

  console.log('- seeding a school, classroom, device, student and teacher');
  const seed = String.raw`
    $school = \App\Models\School::create(['name' => 'E2E School']);
    $classroom = \App\Models\Classroom::create(['school_id' => $school->id, 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'E2E Lab']);
    $computer = \App\Models\Computer::create(['device_uuid' => (string) \Illuminate\Support\Str::uuid(), 'school_id' => $school->id, 'classroom_id' => $classroom->id, 'name' => 'E2E-PC', 'role' => 'student']);
    \App\Models\Student::create(['school_id' => $school->id, 'admission_number' => 'E2E1', 'full_name' => 'E2E Student']);
    $teacher = \App\Models\User::create(['name' => 'E2E Teacher', 'email' => 'e2e-teacher@example.test', 'password' => 'e2e-password-123']);
    $teacher->schools()->attach($school->id, ['role' => 'teacher']);
    $classroom->users()->attach($teacher->id, ['role' => 'primary_teacher']);
    echo json_encode(['computer' => $computer->id, 'classroom' => $classroom->id, 'token' => $computer->createToken('device', ['device'])->plainTextToken]);
  `;
  const seeded = artisan('tinker', `--execute=${seed}`);
  const fixture = JSON.parse(/\{"computer".*\}/.exec(seeded.stdout)?.[0] ?? 'null');
  if (!fixture) throw new Error(`seeding failed:\n${seeded.stdout}${seeded.stderr}`);

  const port = await freePort();
  console.log(`- starting the backend on 127.0.0.1:${port}`);
  server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], { cwd: backend, env, stdio: 'ignore' });
  const base = `http://127.0.0.1:${port}`;
  for (let attempt = 0; attempt < 50; attempt += 1) {
    if (await fetch(`${base}/api/ping`).then((r) => r.ok).catch(() => false)) break;
    if (attempt === 49) throw new Error('the backend never became ready');
    await new Promise((resolve) => setTimeout(resolve, 200));
  }

  console.log('- running the agent against it\n');
  const result = spawnSync('cargo', ['test', '--lib', 'the_agent_and_backend_agree_end_to_end', '--', '--ignored', '--nocapture'], {
    cwd: join(repo, 'kiosk', 'src-tauri'),
    stdio: 'inherit',
    env: {
      ...process.env,
      TOH_E2E_BASE_URL: base,
      TOH_E2E_DEVICE_TOKEN: fixture.token,
      TOH_E2E_COMPUTER_ID: String(fixture.computer),
      TOH_E2E_CLASSROOM_ID: String(fixture.classroom),
      TOH_E2E_ADMISSION: 'E2E1',
      TOH_E2E_TEACHER_EMAIL: 'e2e-teacher@example.test',
      TOH_E2E_TEACHER_PASSWORD: 'e2e-password-123',
    },
  });
  exitCode = result.status ?? 1;
  console.log(exitCode === 0 ? '\nfull-stack e2e ok: real agent code + real Laravel API' : '\nfull-stack e2e FAILED');
} finally {
  try { if (server?.pid) execFileSync('taskkill', ['/PID', String(server.pid), '/T', '/F'], { stdio: 'ignore' }); } catch {}
  await new Promise((resolve) => setTimeout(resolve, 500));
  try { rmSync(work, { recursive: true, force: true }); } catch {}
}
process.exit(exitCode);
