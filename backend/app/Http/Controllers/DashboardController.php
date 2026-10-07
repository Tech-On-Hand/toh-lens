<?php

namespace App\Http\Controllers;

use App\Support\TeacherInstaller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $installer = $request->user()->isAnyAdministrator() ? null : TeacherInstaller::find();

        return Inertia::render('dashboard', [
            'teacherInstaller' => $installer ? [
                'name' => $installer->getFilename(),
                'size_mb' => round($installer->getSize() / 1048576, 1),
            ] : null,
        ]);
    }
}
