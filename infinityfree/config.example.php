<?php
declare(strict_types=1);

/*
 * Copy to config.php on the hosting account. Never commit or upload this template.
 * setup_token must be a separate random secret (64+ characters) used only for first setup.
 */
return [
    'db_host' => 'YOUR_MYSQL_HOST',
    'db_name' => 'YOUR_DATABASE_NAME',
    'db_user' => 'YOUR_DATABASE_USERNAME',
    'db_password' => 'CHANGE_ME',
    'app_secret' => 'REPLACE_WITH_AT_LEAST_64_RANDOM_CHARACTERS',
    'setup_token' => 'REPLACE_WITH_A_DIFFERENT_64_RANDOM_CHARACTER_SECRET',
    'timezone' => 'Asia/Tokyo',
];
