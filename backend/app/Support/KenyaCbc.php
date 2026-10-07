<?php

namespace App\Support;

/**
 * The grades of Kenya's Competency Based Curriculum (2-6-3-3-3), grouped by
 * level, for seeding a school's classes in one click (Classes page).
 */
class KenyaCbc
{
    /** Levels shown ticked when the seed panel opens: the two primary levels. */
    public const DEFAULT_LEVELS = ['lower_primary', 'upper_primary'];

    /** Most streams (e.g. colours) one seeding may add to every grade. */
    public const MAX_STREAMS = 10;

    /**
     * @return array<string, array{label: string, grades: list<string>}>
     */
    public static function levels(): array
    {
        return [
            'pre_primary' => ['label' => 'Pre-Primary', 'grades' => ['PP1', 'PP2']],
            'lower_primary' => ['label' => 'Lower Primary', 'grades' => ['Grade 1', 'Grade 2', 'Grade 3']],
            'upper_primary' => ['label' => 'Upper Primary', 'grades' => ['Grade 4', 'Grade 5', 'Grade 6']],
            'junior_school' => ['label' => 'Junior School', 'grades' => ['Grade 7', 'Grade 8', 'Grade 9']],
            'senior_school' => ['label' => 'Senior School', 'grades' => ['Grade 10', 'Grade 11', 'Grade 12']],
        ];
    }

    /**
     * Splits what an administrator typed ("Blue, Green" or one per line) into
     * distinct, trimmed stream names, ignoring case when removing duplicates.
     *
     * @return list<string>
     */
    public static function parseStreams(?string $input): array
    {
        $streams = [];
        foreach (preg_split('/[,\n;]+/', (string) $input) ?: [] as $stream) {
            $stream = trim(preg_replace('/\s+/', ' ', $stream));
            if ($stream !== '') {
                $streams[mb_strtolower($stream)] ??= $stream;
            }
        }

        return array_values($streams);
    }
}
