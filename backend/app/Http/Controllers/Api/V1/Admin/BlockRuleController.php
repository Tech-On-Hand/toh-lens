<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Events\DevicePolicyChanged;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\BlockRule;
use App\Models\Classroom;
use App\Models\User;
use App\Support\Audit;
use App\Support\Domain;
use App\Support\PolicyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BlockRuleController extends ApiController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'school_id' => ['required', 'exists:schools,id'],
            'classroom_id' => ['nullable', 'exists:classrooms,id'],
            'domain' => ['required', 'string', 'max:2048'],
        ]);

        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator((int) $data['school_id']), 403);

        $classroom = isset($data['classroom_id']) ? Classroom::findOrFail($data['classroom_id']) : null;
        abort_if($classroom && $classroom->school_id !== (int) $data['school_id'], 422);

        $domain = Domain::normalize($data['domain'])
            ?? throw ValidationException::withMessages(['domain' => "\"{$data['domain']}\" is not a valid domain name."]);

        $rule = BlockRule::firstOrCreate(
            ['school_id' => $data['school_id'], 'classroom_id' => $classroom?->id, 'domain' => $domain],
            ['created_by' => $user->id],
        );

        if ($rule->wasRecentlyCreated) {
            Audit::record('block_rule.added', $user, $classroom, null, ['domain' => $domain, 'scope' => $classroom ? 'classroom' : 'school'], (int) $data['school_id']);
            DevicePolicyChanged::dispatch(PolicyResolver::deviceUuids((int) $data['school_id'], $classroom?->id));
        }

        return $this->success(['id' => $rule->id, 'domain' => $rule->domain, 'scope' => $classroom ? 'classroom' : 'school'], $rule->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, BlockRule $rule): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator($rule->school_id), 403);

        $rule->delete();
        Audit::record('block_rule.removed', $user, $rule->classroom_id ? Classroom::find($rule->classroom_id) : null, null, [
            'domain' => $rule->domain,
            'scope' => $rule->classroom_id ? 'classroom' : 'school',
        ], $rule->school_id);
        DevicePolicyChanged::dispatch(PolicyResolver::deviceUuids($rule->school_id, $rule->classroom_id));

        return $this->success(['removed' => true]);
    }
}
