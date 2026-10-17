<?php

return [
    'mysql_binary' => env(
        'MYSQL_CLIENT_BINARY',
        PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\mysql\\bin\\mysql.exe' : 'mysql'
    ),

    'mysqldump_binary' => env(
        'MYSQLDUMP_BINARY',
        PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe' : 'mysqldump'
    ),

    'max_upload_kilobytes' => (int) env('SQL_BACKUP_MAX_UPLOAD_KB', 2 * 1024 * 1024),
    'max_uncompressed_bytes' => (int) env('SQL_BACKUP_MAX_UNCOMPRESSED_BYTES', 10 * 1024 * 1024 * 1024),
];
