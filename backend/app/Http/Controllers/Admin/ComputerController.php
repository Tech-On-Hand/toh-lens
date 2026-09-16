<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Computer;
use App\Models\School;
use App\Models\SchoolClass;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ComputerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/computers/index', [
            'computers' => Computer::query()
                ->with(['school:id,name', 'schoolClass:id,name'])
                ->withCount('tokens')
                ->orderBy('name')
                ->get(),
            'schools' => School::query()->orderBy('name')->get(['id', 'name']),
            'classes' => SchoolClass::query()->orderBy('name')->get(['id', 'name', 'school_id']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        Computer::create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Computer created.']);

        return back();
    }

    public function update(Request $request, Computer $computer): RedirectResponse
    {
        $data = $this->validated($request);

        $computer->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Computer updated.']);

        return back();
    }

    public function destroy(Computer $computer): RedirectResponse
    {
        $computer->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Computer deleted.']);

        return back();
    }

    /**
     * Rotate this computer's API token: revoke whatever it had before and
     * issue a fresh one. The plaintext value is only ever available in this
     * one flashed response — it is not retrievable again afterwards.
     */
    public function issueToken(Computer $computer): RedirectResponse
    {
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
            'class_id' => ['nullable', 'exists:classes,id'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'in:teacher,student'],
        ]);
    }
}
