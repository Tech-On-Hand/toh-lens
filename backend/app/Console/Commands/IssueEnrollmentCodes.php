<?php

namespace App\Console\Commands;

use App\Models\Classroom;
use App\Models\DeviceEnrollmentCode;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class IssueEnrollmentCodes extends Command
{
    private const MAX_COUNT = 200;
    private const MAX_MINUTES = 10080;

    protected $signature = 'klas:enrollment-codes
        {classroom : Classroom id}
        {count=1 : How many codes (one per computer)}
        {--as= : Email of the school administrator issuing them}
        {--minutes=1440 : How long each code stays valid (the web admin uses 30)}
        {--json : Print the codes as JSON}';

    protected $description = 'Issue one-time enrollment codes for a classroom, in bulk, for rolling out many computers';

    public function handle(): int
    {
        $classroom = Classroom::query()->with('school')->find($this->argument('classroom'));
        if (! $classroom) {
            $this->components->error('No classroom with that id.');

            return self::FAILURE;
        }

        $issuer = User::query()->where('email', strtolower(trim((string) $this->option('as'))))->first();
        if (! $issuer || ! $issuer->isSchoolAdministrator($classroom->school_id)) {
            $this->components->error('--as must be the email of an administrator of '.$classroom->school->name.'.');

            return self::FAILURE;
        }

        $count = (int) $this->argument('count');
        $minutes = (int) $this->option('minutes');
        if ($count < 1 || $count > self::MAX_COUNT || $minutes < 1 || $minutes > self::MAX_MINUTES) {
            $this->components->error('count must be 1-'.self::MAX_COUNT.' and --minutes 1-'.self::MAX_MINUTES.'.');

            return self::FAILURE;
        }

        $expires = now()->addMinutes($minutes);
        $codes = [];
        while (count($codes) < $count) {
            $code = strtoupper(Str::random(4).'-'.Str::random(4));
            $hash = hash('sha256', $code);
            if (in_array($code, $codes, true) || DeviceEnrollmentCode::query()->where('code_hash', $hash)->exists()) {
                continue;
            }
            DeviceEnrollmentCode::create([
                'school_id' => $classroom->school_id,
                'classroom_id' => $classroom->id,
                'created_by' => $issuer->id,
                'code_hash' => $hash,
                'expires_at' => $expires,
            ]);
            $codes[] = $code;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['classroom' => $classroom->name, 'expires_at' => $expires->toIso8601String(), 'codes' => $codes], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->components->info("{$count} code(s) for {$classroom->school->name} / {$classroom->name}, valid until {$expires->toDateTimeString()}. Each works once.");
        foreach ($codes as $code) {
            $this->line("  {$code}");
        }
        $this->components->warn('Codes are stored hashed and cannot be shown again.');

        return self::SUCCESS;
    }
}
