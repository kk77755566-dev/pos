<?php
declare(strict_types=1);

/*
 * Copy to config.php and fill values from InfinityFree Control Panel.
 * Keep config.php private; never commit real credentials.
 */
return [
    'db_host' => 'YOUR_MYSQL_HOST',
    'db_name' => 'YOUR_DATABASE_NAME',
    'db_user' => 'YOUR_DATABASE_USERNAME',
    'db_password' => 'CHANGE_ME',
    'app_secret' => 'REPLACE_WITH_AT_LEAST_64_RANDOM_CHARACTERS',
    'timezone' => 'Asia/Tokyo',
];
