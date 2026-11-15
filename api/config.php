<?php
/*
 * CampusMind configuration.
 *
 * The same files work on your computer and on the web host:
 *   - On localhost (start-server.bat) the site runs in LOCAL mode: SQLite, demo login buttons, reset + tests enabled.
 *   - Anywhere else it runs in LIVE mode: MySQL, no demo buttons, strong passwords, reset + tests disabled.
 */
$host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]);
$live = PHP_SAPI !== 'cli' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true);

$shared = [
    'live'          => $live,
    'timezone'      => 'Africa/Kampala',
    'session_hours' => 8,
    'bcrypt_cost'   => 10,
];

if (!$live) {
    return $shared + [
        'driver'      => 'sqlite',
        'sqlite_path' => __DIR__ . '/../storage/campusmind.sqlite',
        // To test with your local MySQL instead: set 'driver' => 'mysql' and fill in the password below.
        'mysql' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'campusmind', 'username' => 'root', 'password' => ''],
        'demo_passwords' => null, // demo accounts use the simple passwords shown on the login page
    ];
}

return $shared + [
    'driver' => 'mysql',
    // InfinityFree: copy these from Control Panel -> MySQL Databases (host looks like sql123.infinityfree.com,
    // database and username look like if0_12345678_campusmind / if0_12345678).
    'mysql' => [
        'host'     => 'sql204.infinityfree.com',
        'port'     => 3306,
        'database' => 'if0_43384841_campusmind',
        'username' => 'if0_43384841',
        'password' => 'PASTE_MYSQL_PASSWORD',
    ],
    // Passwords for the demo accounts on the live site (same emails as locally). Keep this file private.
    'demo_passwords' => [
        'admin'     => 'gBHJJUvHTKuDsc85',
        'moderator' => 'pUppdtKdN69KH819',
        'counselor' => 'Tm4KJBP3ZCJgiY40',
        'student'   => '5MuZgMNbmZ4tSx22',
    ],
];
