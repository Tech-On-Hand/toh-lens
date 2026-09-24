<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Computer;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ComputerController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $schoolIds = $user->administeredSchoolIds();

        $computers = Computer::query()
            ->whereIn('school_id', $schoolIds)
            ->with([
                'school:id,name',
                'schoolClass:id,name',
                // Only the most recently issued token, to read its last_used_at
                // — a live signal that this computer has actually reached the
                // backend, independent of whether any session synced.
                'tokens' => fn ($query) => $query->latest('id')->limit(1),
            ])
            ->withCount('tokens')
            ->withMax('loginSessions', 'created_at')
            ->orderBy('name')
            ->get();

        return Inertia::render('admin/computers/index', [
            'computers' => $computers->map(fn (Computer $computer) => [
                'id' => $computer->id,
                'name' => $computer->name,
                'role' => $computer->role,
                'school_id' => $computer->school_id,
                'class_id' => $computer->class_id,
                'school' => $computer->school,
                'school_class' => $computer->schoolClass,
                'tokens_count' => $computer->tokens_count,
                'last_session_synced_at' => $computer->login_sessions_max_created_at,
                'token_last_used_at' => $computer->tokens->first()?->last_used_at,
            ]),
            'schools' => School::query()->whereIn('id', $schoolIds)->orderBy('name')->get(['id', 'name']),
            'classes' => SchoolClass::query()->whereIn('school_id', $schoolIds)->orderBy('name')->get(['id', 'name', 'school_id']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator((int) $request->input('school_id')), 403);

        $data = $this->validated($request);

        Computer::create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Computer created.']);

        return back();
    }

    public function update(Request $request, Computer $computer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator($computer->school_id), 403);
        // school_id isn't editable from this form; a mismatch would mean
        // moving the computer to a school this admin doesn't run.
        abort_unless((int) $request->input('school_id') === $computer->school_id, 403);

        $data = $this->validated($request);

        $computer->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Computer updated.']);

        return back();
    }

    public function destroy(Request $request, Computer $computer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator($computer->school_id), 403);

        $computer->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Computer deleted.']);

        return back();
    }

    /**
     * Rotate this computer's API token: revoke whatever it had before and
     * issue a fresh one. The plaintext value is only ever available in this
     * one flashed response — it is not retrievable again afterwards.
     */
    public function issueToken(Request $request, Computer $computer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator($computer->school_id), 403);

        $computer->tokens()->delete();

        $token = $computer->createToken('kiosk-token')->plainTextToken;

        Inertia::flash('issuedToken', $token);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'New token issued — copy it now, it will not be shown again.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'school_id' => ['required', 'exists:schools,id'],
            'class_id' => [
                'nullable',
                Rule::exists('classes', 'id')->where('school_id', $request->input('school_id')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'in:teacher,student'],
        ]);
    }
}
