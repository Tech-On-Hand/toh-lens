<?php

namespace App\Support;

class AppNames
{
    /** A readable name for a process (`WINWORD.EXE` becomes "Word"), or the process itself when unknown. */
    public static function friendly(string $process): string
    {
        $known = [
            'winword.exe' => 'Word', 'excel.exe' => 'Excel', 'powerpnt.exe' => 'PowerPoint', 'onenote.exe' => 'OneNote',
            'outlook.exe' => 'Outlook', 'chrome.exe' => 'Chrome', 'msedge.exe' => 'Edge', 'firefox.exe' => 'Firefox',
            'notepad.exe' => 'Notepad', 'calc.exe' => 'Calculator', 'mspaint.exe' => 'Paint', 'explorer.exe' => 'File Explorer',
            'code.exe' => 'VS Code', 'teams.exe' => 'Teams', 'zoom.exe' => 'Zoom', 'acrobat.exe' => 'Acrobat',
            'acrord32.exe' => 'Acrobat Reader', 'wordpad.exe' => 'WordPad', 'cmd.exe' => 'Command Prompt',
            'powershell.exe' => 'PowerShell', 'taskmgr.exe' => 'Task Manager', 'scratch.exe' => 'Scratch',
        ];

        return $known[strtolower($process)] ?? $process;
    }
}
