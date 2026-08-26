<?php

return [
    /*
     | Where dumps are written. Kept off the default disk so a backup is never
     | served by the web server by accident.
     */
    'path' => storage_path('app/backups'),

    /*
     | Full paths, because the tools are not always on PATH — and on a client's
     | Windows server they will not be.
     */
    'mysqldump' => env('BACKUP_MYSQLDUMP', '/opt/homebrew/bin/mysqldump'),
    'mysql' => env('BACKUP_MYSQL', '/opt/homebrew/bin/mysql'),

    /*
     | How long a dump takes on a year of data is unknown until a real client's
     | database exists, so this is generous rather than tuned.
     */
    'timeout_seconds' => 600,
];
