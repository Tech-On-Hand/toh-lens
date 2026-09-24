<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Organization;
use App\Models\School;
use App\Models\User;
use App\Support\ImpactReport;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImpactController extends ApiController
{
    private const MAX_DAYS = 366;

    /**
     * The aggregate "is the equipment being used" report for one school, or for every
     * school in an organization. Names no student. Dates are read in the server's
     * timezone and default to the last 30 days.
     */
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'school_id' => ['nullable', 'integer', 'exists:schools,id', 'required_without:organization_id'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id', 'required_without:school_id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (isset($data['organization_id'])) {
            abort_unless($user->isOrganizationAdministrator((int) $data['organization_id']), 403);
            $organization = Organization::query()->findOrFail($data['organization_id']);
            $scope = ['type' => 'organization', 'name' => $organization->name];
            $schoolIds = School::query()->where('organization_id', $organization->id)->pluck('id');
        } else {
            abort_unless($user->isSchoolAdministrator((int) $data['school_id']), 403);
            $school = School::query()->findOrFail($data['school_id']);
            $scope = ['type' => 'school', 'name' => $school->name];
            $schoolIds = collect([$school->id]);
        }

        $to = Carbon::parse($data['to'] ?? now()->toDateString())->endOfDay();
        $from = Carbon::parse($data['from'] ?? $to->copy()->subDays(29)->toDateString())->startOfDay();
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            return $this->error('RANGE_TOO_LONG', 'Choose a range of at most a year.', 422);
        }

        return $this->success([
            'scope' => $scope,
            'generated_at' => now()->toIso8601String(),
            ...(new ImpactReport($schoolIds, $from, $to))->build(),
        ]);
    }
}
