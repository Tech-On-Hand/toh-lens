<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\DevicePolicyChanged;
use App\Events\FocusSessionChanged;
use App\Models\BlockRule;
use App\Models\Classroom;
use App\Models\FocusSession;
use App\Models\User;
use App\Support\Audit;
use App\Support\Domain;
use App\Support\PolicyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherPolicyController extends ApiController
{
    private const MAX_ALLOWED_DOMAINS = 100;
    private const MAX_CLASSROOM_BLOCK_RULES = 500;

    public function show(Request $request, Classroom $classroom): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canViewClassroom($classroom), 403);

        $rules = BlockRule::query()
            ->where('school_id', $classroom->school_id)
            ->where(fn ($query) => $query->whereNull('classroom_id')->orWhere('classroom_id', $classroom->id))
            ->orderBy('domain')->get()
            ->map(fn (BlockRule $rule) => [
                'id' => $rule->id,
                'domain' => $rule->domain,
                'scope' => $rule->classroom_id ? 'classroom' : 'school',
            ]);

        return $this->success([
            'block_rules' => $rules,
            'focus' => PolicyResolver::forClassroom($classroom->school_id, $classroom->id)['focus'],
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function startFocus(Request $request, Classroom $classroom): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canControlClassroom($classroom), 403);

        $data = $request->validate([
            'allowed_domains' => ['required', 'array', 'min:1', 'max:'.self::MAX_ALLOWED_DOMAINS],
            'allowed_domains.*' => ['required', 'string', 'max:2048'],
            'duration_minutes' => ['required', 'integer', 'between:1,240'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        $domains = $this->normalizeAll($data['allowed_domains'], 'allowed_domains');

        $session = DB::transaction(function () use ($classroom, $user, $data, $domains) {
            // Serialize concurrent starts for this classroom.
            Classroom::query()->whereKey($classroom->id)->lockForUpdate()->first();

            if (FocusSession::query()->active()->where('classroom_id', $classroom->id)->exists()) {
                return null;
            }

            return FocusSession::create([
                'uuid' => (string) Str::uuid(),
                'school_id' => $classroom->school_id,
                'classroom_id' => $classroom->id,
                'started_by' => $user->id,
                'name' => $data['name'] ?? null,
                'allowed_domains' => $domains,
                'started_at' => now(),
                'expires_at' => now()->addMinutes($data['duration_minutes']),
            ]);
        });

        if (! $session) {
            return $this->error('FOCUS_ALREADY_ACTIVE', 'A focus session is already running in this classroom.', 409);
        }

        Audit::record('focus.started', $user, $classroom, null, [
            'focus_id' => $session->uuid,
            'allowed_domains' => $domains,
            'duration_minutes' => $data['duration_minutes'],
        ]);
        FocusSessionChanged::dispatch($session, 'started');
        DevicePolicyChanged::dispatch(PolicyResolver::deviceUuids($classroom->school_id, $classroom->id));

        return $this->success($session->toSummary(), 201);
    }

    public function endFocus(Request $request, Classroom $classroom, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canControlClassroom($classroom), 403);

        $session = FocusSession::query()->where('uuid', $uuid)->where('classroom_id', $classroom->id)->firstOrFail();

        if (! FocusSession::query()->active()->whereKey($session->id)->exists()) {
            return $this->error('FOCUS_NOT_ACTIVE', 'That focus session has already ended.', 409);
        }

        $session->update(['ended_at' => now(), 'ended_by' => $user->id, 'end_reason' => 'ended']);

        Audit::record('focus.ended', $user, $classroom, null, ['focus_id' => $session->uuid]);
        FocusSessionChanged::dispatch($session, 'ended');
        DevicePolicyChanged::dispatch(PolicyResolver::deviceUuids($classroom->school_id, $classroom->id));

        return $this->success($session->toSummary());
    }

    public function addBlockRule(Request $request, Classroom $classroom): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canControlClassroom($classroom), 403);

        $data = $request->validate(['domain' => ['required', 'string', 'max:2048']]);
        [$domain] = $this->normalizeAll([$data['domain']], 'domain');

        $count = BlockRule::query()->where('classroom_id', $classroom->id)->count();
        $rule = BlockRule::query()->where('school_id', $classroom->school_id)->where('classroom_id', $classroom->id)->where('domain', $domain)->first();

        if (! $rule) {
            if ($count >= self::MAX_CLASSROOM_BLOCK_RULES) {
                return $this->error('TOO_MANY_RULES', 'This classroom has reached its limit of blocked sites.', 422);
            }

            $rule = BlockRule::create(['school_id' => $classroom->school_id, 'classroom_id' => $classroom->id, 'domain' => $domain, 'created_by' => $user->id]);
            Audit::record('block_rule.added', $user, $classroom, null, ['domain' => $domain]);
            DevicePolicyChanged::dispatch(PolicyResolver::deviceUuids($classroom->school_id, $classroom->id));
        }

        return $this->success(['id' => $rule->id, 'domain' => $rule->domain, 'scope' => 'classroom'], $rule->wasRecentlyCreated ? 201 : 200);
    }

    public function removeBlockRule(Request $request, Classroom $classroom, BlockRule $rule): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canControlClassroom($classroom), 403);

        if ($rule->school_id === $classroom->school_id && $rule->classroom_id === null) {
            return $this->error('SCHOOL_RULE', 'This site is blocked for the whole school. Ask a school administrator to change it.', 403);
        }
        abort_unless($rule->classroom_id === $classroom->id, 404);

        $rule->delete();
        Audit::record('block_rule.removed', $user, $classroom, null, ['domain' => $rule->domain]);
        DevicePolicyChanged::dispatch(PolicyResolver::deviceUuids($classroom->school_id, $classroom->id));

        return $this->success(['removed' => true]);
    }

    /**
     * @param  list<string>  $inputs
     * @return list<string>
     */
    private function normalizeAll(array $inputs, string $field): array
    {
        $domains = [];
        $errors = [];

        foreach (array_values($inputs) as $index => $input) {
            $domain = Domain::normalize($input);
            $domain === null
                ? $errors[$field === 'domain' ? 'domain' : "{$field}.{$index}"] = "\"{$input}\" is not a valid domain name."
                : $domains[] = $domain;
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return array_values(array_unique($domains));
    }
}
