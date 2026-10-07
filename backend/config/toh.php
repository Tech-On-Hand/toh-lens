<?php

return [
    /*
    | How long browsing history and desktop-application activity are kept before
    | `activity:prune` (run daily) deletes them. Tell schools and parents what this
    | is set to.
    */
    'activity_retention_days' => (int) env('TOH_ACTIVITY_RETENTION_DAYS', 90),

    /*
    | Where the Teacher app installer is kept for signed-in staff to download.
    | Not served from public/: see "Teacher app download" in DEPLOYMENT.md.
    */
    'downloads_path' => storage_path('app/private/downloads'),
];
