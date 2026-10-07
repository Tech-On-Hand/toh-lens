<?php

namespace App\Support;

use SplFileInfo;

/**
 * The Teacher app installer that signed-in staff can download. It is not in git
 * or in public/: copy the build output into storage/app/private/downloads/ on the
 * server (see DEPLOYMENT.md). The newest "TOH Klas Teacher*" .exe or .msi wins.
 */
class TeacherInstaller
{
    public static function find(): ?SplFileInfo
    {
        $files = glob(config('toh.downloads_path').'/TOH Klas Teacher*.{exe,msi}', GLOB_BRACE) ?: [];
        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        return $files ? new SplFileInfo($files[0]) : null;
    }
}
