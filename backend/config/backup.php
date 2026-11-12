<?php

return [
    // auto uses mysqldump where the host allows it and PHP where it does; native or php forces one.
    'driver' => env('BACKUP_DRIVER', 'auto'),
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    'mysql' => env('BACKUP_MYSQL', 'mysql'),
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
    'key' => env('BACKUP_KEY'),
];
