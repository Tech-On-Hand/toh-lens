<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SchoolController extends Controller
{
    public function index(): Response
    {
        $schools = School::query()
            ->withCount(['classes', 'students', 'computers'])
            ->orderBy('name')
            ->get();

        return Inertia::render('admin/schools/index', [
            'schools' => $schools,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        School::create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'School created.']);

        return back();
    }

    public function destroy(School $school): RedirectResponse
    {
        $school->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'School deleted.']);

        return back();
    }
}
