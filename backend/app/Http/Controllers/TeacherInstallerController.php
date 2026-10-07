<?php

namespace App\Http\Controllers;

use App\Support\TeacherInstaller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TeacherInstallerController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $installer = TeacherInstaller::find();
        abort_unless($installer, 404, 'The Teacher app installer has not been uploaded to this server yet.');

        return response()->download($installer->getPathname(), $installer->getFilename());
    }
}
