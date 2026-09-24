<?php

return [
    /*
    | How long browsing history and desktop-application activity are kept before
    | `activity:prune` (run daily) deletes them. Tell schools and parents what this
    | is set to.
    */
    'activity_retention_days' => (int) env('TOH_ACTIVITY_RETENTION_DAYS', 90),
];
