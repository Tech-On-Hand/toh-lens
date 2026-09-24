<?php

namespace App\Console\Commands;

use App\Models\Classroom;
use App\Models\Computer;
use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class CheckReadiness extends Command
{
    /** The scheduler stamps this every minute (routes/console.php). */
    public const SCHEDULER_KEY = 'klas.scheduler.last_run';

    protected $signature = 'klas:check';

    protected $description = 'Check that this server is set up properly for a pilot (exits non-zero if anything is broken)';

    /** @return list<array{0: string, 1: string, 2: string}> [status, what, detail] */
    public function checks(): array
    {
        return array_merge(
            $this->environment(),
            $this->database(),
            $this->services(),
            $this->scheduler(),
            $this->data(),
        );
    }

    public function handle(): int
    {
        $failed = false;
        foreach ($this->checks() as [$status, $what, $detail]) {
            $failed = $failed || $status === 'FAIL';
            $colour = ['PASS' => 'green', 'WARN' => 'yellow', 'FAIL' => 'red'][$status];
            $this->line("  <fg={$colour}>{$status}</>  {$what}".($detail !== '' ? " — {$detail}" : ''));
        }
        $this->newLine();
        $failed
            ? $this->components->error('Fix the FAIL items before a pilot. WARN items are worth reading.')
            : $this->components->info('Nothing is broken. Read any WARN items.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function environment(): array
    {
        $url = (string) config('app.url');
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        return [
            config('app.key') ? ['PASS', 'Application key', ''] : ['FAIL', 'Application key', 'APP_KEY is empty: run php artisan key:generate'],
            app()->environment('production') ? ['PASS', 'Environment', 'production'] : ['WARN', 'Environment', 'APP_ENV is "'.app()->environment().'", not production'],
            config('app.debug') ? ['WARN', 'Debug mode', 'APP_DEBUG is on: errors show stack traces to anyone'] : ['PASS', 'Debug mode', 'off'],
            match (true) {
                $local => ['WARN', 'Server address', "APP_URL is {$url}: computers in other rooms cannot reach localhost"],
                str_starts_with($url, 'https://') => ['PASS', 'Server address', $url],
                default => ['WARN', 'Server address', "{$url} is not https, so device tokens and student data cross the network unencrypted"],
            },
            is_writable(storage_path('logs')) ? ['PASS', 'Log folder', 'writable'] : ['FAIL', 'Log folder', 'storage/logs is not writable'],
        ];
    }

    private function database(): array
    {
        try {
            DB::select('select 1');
        } catch (\Throwable $e) {
            return [['FAIL', 'Database', 'cannot connect: '.$e->getMessage()]];
        }

        $migrator = app('migrator');
        $files = array_keys($migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths())));
        $pending = count(array_diff($files, $migrator->getRepository()->getRan()));

        return [
            ['PASS', 'Database', 'connected'],
            $pending === 0 ? ['PASS', 'Migrations', 'up to date'] : ['FAIL', 'Migrations', "{$pending} pending: run php artisan migrate"],
        ];
    }

    private function services(): array
    {
        $checks = [];

        if (in_array('redis', [config('queue.default'), config('cache.default'), config('session.driver')], true)) {
            try {
                Redis::connection()->ping();
                $checks[] = ['PASS', 'Redis', 'reachable'];
            } catch (\Throwable $e) {
                $checks[] = ['FAIL', 'Redis', 'configured but unreachable: '.$e->getMessage()];
            }
        }

        $checks[] = config('queue.default') === 'sync'
            ? ['WARN', 'Queue', 'QUEUE_CONNECTION is sync: live updates will slow every request; use redis and run a queue worker']
            : ['PASS', 'Queue', config('queue.default').' (make sure a worker is running: php artisan queue:work)'];

        if (config('broadcasting.default') === 'reverb') {
            $options = config('broadcasting.connections.reverb.options', []);
            $host = $options['host'] ?? '127.0.0.1';
            $port = $options['port'] ?? 8080;
            $socket = @fsockopen($host === '0.0.0.0' ? '127.0.0.1' : $host, (int) $port, $code, $message, 1);
            if ($socket) {
                fclose($socket);
                $checks[] = ['PASS', 'Live updates (Reverb)', "listening on {$host}:{$port}"];
            } else {
                $checks[] = ['WARN', 'Live updates (Reverb)', "nothing is listening on {$host}:{$port}: the Teacher app will not update live (php artisan reverb:start)"];
            }
            if (app()->environment('production') && in_array(config('broadcasting.connections.reverb.key'), ['local-key', ''], true)) {
                $checks[] = ['FAIL', 'Reverb keys', 'still the example values: set REVERB_APP_KEY / REVERB_APP_SECRET'];
            }
        } else {
            $checks[] = ['WARN', 'Live updates', 'BROADCAST_CONNECTION is not reverb: the Teacher app will not update live'];
        }

        $checks[] = in_array(config('mail.default'), ['log', 'array'], true)
            ? ['WARN', 'Mail', 'MAIL_MAILER is '.config('mail.default').': staff invitation emails are only written to the log']
            : ['PASS', 'Mail', config('mail.default')];

        return $checks;
    }

    private function scheduler(): array
    {
        $last = Cache::get(self::SCHEDULER_KEY);
        if ($last && now()->timestamp - (int) $last <= 180) {
            return [['PASS', 'Scheduler', 'ran '.max(0, now()->timestamp - (int) $last).' s ago']];
        }

        return [['FAIL', 'Scheduler', $last ? 'last ran '.\Carbon\Carbon::createFromTimestamp($last)->diffForHumans() : 'has never run'
            .': add a cron entry running "php artisan schedule:run" every minute. Without it computers are never marked offline and screen sessions never expire']];
    }

    private function data(): array
    {
        try {
            $schools = School::query()->count();
            $classrooms = Classroom::query()->count();
            $devices = Computer::query()->whereNull('revoked_at')->count();
        } catch (\Throwable) {
            return [];
        }

        return [
            $schools > 0 ? ['PASS', 'Schools', "{$schools} school(s), {$classrooms} classroom(s), {$devices} enrolled computer(s)"] : ['WARN', 'Schools', 'none yet: run php artisan klas:bootstrap'],
            ['PASS', 'Activity retention', config('toh.activity_retention_days').' days (tell schools and parents)'],
        ];
    }
}
