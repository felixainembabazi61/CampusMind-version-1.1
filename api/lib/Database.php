<?php
declare(strict_types=1);

/**
 * Connection, schema (migrations) and row helpers.
 * Tables mirror the Laravel models listed in the project report.
 */
final class Database
{
    /** table => JavaScript collection name used by the front end */
    public const TABLES = [
        'users'            => 'users',
        'slots'            => 'slots',
        'bookings'         => 'bookings',
        'intakes'          => 'intakes',
        'stories'          => 'stories',
        'forum_categories' => 'forumCategories',
        'forum_posts'      => 'forumPosts',
        'forum_comments'   => 'forumComments',
        'reactions'        => 'reactions',
        'reports'          => 'reports',
        'conversations'    => 'conversations',
        'messages'         => 'messages',
        'notifications'    => 'notifications',
        'settings'         => 'settings',
        'contact_messages' => 'contactMessages',
    ];

    /** column types used to convert DB values back to proper JSON types */
    private const CASTS = [
        'users'          => ['bool' => ['active'], 'json' => ['specialties']],
        'intakes'        => ['bool' => ['safetyRisk'], 'json' => ['concerns'], 'int' => ['mood']],
        'stories'        => ['bool' => ['anonymous'], 'json' => ['tags']],
        'forum_posts'    => ['bool' => ['anonymous', 'pinned', 'locked']],
        'forum_comments' => ['bool' => ['anonymous']],
        'messages'       => ['json' => ['readBy']],
        'notifications'  => ['bool' => ['isRead']],
    ];

    public static function connect(array $cfg): PDO
    {
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if (($cfg['driver'] ?? 'sqlite') === 'mysql') {
            $m = $cfg['mysql'];
            $db = preg_replace('/[^A-Za-z0-9_]/', '', $m['database']);
            $server = "mysql:host={$m['host']};port={$m['port']};charset=utf8mb4";
            try {
                // shared hosts (e.g. InfinityFree) create the database in their control panel; just connect to it
                $pdo = new PDO("$server;dbname=$db", $m['username'], $m['password'], $opts);
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1049) throw $e; // 1049 = unknown database: create it (local MySQL)
                $pdo = new PDO($server, $m['username'], $m['password'], $opts);
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `$db`");
            }
        } else {
            $path = $cfg['sqlite_path'];
            if ($path !== ':memory:' && !is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, $opts);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
        }
        self::migrate($pdo);
        return $pdo;
    }

    public static function isMysql(PDO $pdo): bool
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    /** CREATE TABLE statements in dependency order. Works on both MySQL and SQLite. */
    public static function schema(bool $mysql): array
    {
        $ID = 'VARCHAR(40)';
        $S = 'VARCHAR(255)';
        $T = 'TEXT';
        $TS = 'VARCHAR(32)';
        $B0 = 'TINYINT NOT NULL DEFAULT 0';
        $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $fk = fn(string $col, string $table, string $on = '') => "FOREIGN KEY (`$col`) REFERENCES `$table` (`id`)$on";

        $tables = [
            'users' => [
                "`id` $ID PRIMARY KEY", "`name` $S NOT NULL", "`email` $S NOT NULL UNIQUE", "`role` VARCHAR(20) NOT NULL",
                "`passwordHash` $S NOT NULL", "`title` $S NULL", "`photo` $S NULL", "`specialties` $T NULL", "`bio` $T NULL",
                "`studentNo` VARCHAR(40) NULL", "`faculty` $S NULL", "`active` TINYINT NOT NULL DEFAULT 1",
                "`lastLogin` $TS NULL", "`createdAt` $TS NOT NULL", "`updatedAt` $TS NULL",
            ],
            'slots' => [
                "`id` $ID PRIMARY KEY", "`counselorId` $ID NOT NULL", "`date` VARCHAR(10) NOT NULL", "`start` VARCHAR(5) NOT NULL",
                "`end` VARCHAR(5) NOT NULL", "`mode` VARCHAR(20) NOT NULL", "`status` VARCHAR(20) NOT NULL",
                "`createdAt` $TS NOT NULL", "`updatedAt` $TS NULL", $fk('counselorId', 'users'),
            ],
            'bookings' => [
                "`id` $ID PRIMARY KEY", "`slotId` $ID NOT NULL", "`studentId` $ID NOT NULL", "`counselorId` $ID NOT NULL",
                "`reason` $T NOT NULL", "`status` VARCHAR(20) NOT NULL", "`notes` $T NULL", "`cancelledBy` $ID NULL",
                "`createdAt` $TS NOT NULL", "`updatedAt` $TS NULL",
                $fk('slotId', 'slots'), $fk('studentId', 'users'), $fk('counselorId', 'users'),
            ],
            'intakes' => [
                "`id` $ID PRIMARY KEY", "`studentId` $ID NOT NULL", "`concerns` $T NOT NULL", "`description` $T NOT NULL",
                "`mood` INT NOT NULL", "`duration` VARCHAR(40) NULL", "`urgency` VARCHAR(20) NOT NULL", "`safetyRisk` $B0",
                "`contactPref` VARCHAR(40) NULL", "`preferredCounselorId` VARCHAR(40) NULL", "`status` VARCHAR(20) NOT NULL",
                "`assignedTo` $ID NULL", "`counselorNote` $T NULL", "`createdAt` $TS NOT NULL", "`updatedAt` $TS NULL",
                $fk('studentId', 'users'), $fk('assignedTo', 'users'),
            ],
            'stories' => [
                "`id` $ID PRIMARY KEY", "`authorId` $ID NOT NULL", "`anonymous` $B0", "`title` $S NOT NULL", "`body` $T NOT NULL",
                "`tags` $T NULL", "`status` VARCHAR(20) NOT NULL", "`moderatorNote` $T NULL", "`moderatedBy` $ID NULL",
                "`createdAt` $TS NOT NULL", "`updatedAt` $TS NULL", $fk('authorId', 'users'),
            ],
            'forum_categories' => [
                "`id` $ID PRIMARY KEY", "`name` VARCHAR(80) NOT NULL UNIQUE", "`description` $S NULL", "`color` VARCHAR(10) NOT NULL",
                "`createdAt` $TS NOT NULL", "`updatedAt` $TS NULL",
            ],
            'forum_posts' => [
                "`id` $ID PRIMARY KEY", "`categoryId` $ID NOT NULL", "`authorId` $ID NOT NULL", "`anonymous` $B0",
                "`title` $S NOT NULL", "`body` $T NOT NULL", "`pinned` $B0", "`locked` $B0", "`status` VARCHAR(20) NOT NULL",
                "`createdAt` $TS NOT NULL", "`updatedAt` $TS NULL", $fk('categoryId', 'forum_categories'), $fk('authorId', 'users'),
            ],
            'forum_comments' => [
                "`id` $ID PRIMARY KEY", "`postId` $ID NOT NULL", "`authorId` $ID NOT NULL", "`anonymous` $B0", "`body` $T NOT NULL",
                "`status` VARCHAR(20) NOT NULL", "`createdAt` $TS NOT NULL", "`updatedAt` $TS NULL",
                $fk('postId', 'forum_posts'), $fk('authorId', 'users'),
            ],
            'reactions' => [
                "`id` $ID PRIMARY KEY", "`targetType` VARCHAR(20) NOT NULL", "`targetId` $ID NOT NULL", "`userId` $ID NOT NULL",
                "`type` VARCHAR(20) NOT NULL", "`createdAt` $TS NOT NULL",
                'UNIQUE (`targetType`, `targetId`, `userId`, `type`)', $fk('userId', 'users'),
            ],
            'reports' => [
                "`id` $ID PRIMARY KEY", "`targetType` VARCHAR(20) NOT NULL", "`targetId` $ID NOT NULL", "`reporterId` $ID NOT NULL",
                "`reason` $S NOT NULL", "`details` $T NULL", "`status` VARCHAR(20) NOT NULL", "`resolvedBy` $ID NULL",
                "`createdAt` $TS NOT NULL", "`updatedAt` $TS NULL", $fk('reporterId', 'users'),
            ],
            'conversations' => [
                "`id` $ID PRIMARY KEY", "`userA` $ID NOT NULL", "`userB` $ID NOT NULL", "`createdAt` $TS NOT NULL",
                $fk('userA', 'users'), $fk('userB', 'users'),
            ],
            'messages' => [
                "`id` $ID PRIMARY KEY", "`conversationId` $ID NOT NULL", "`senderId` $ID NOT NULL", "`body` $T NOT NULL",
                "`readBy` $T NOT NULL", "`createdAt` $TS NOT NULL",
                $fk('conversationId', 'conversations'), $fk('senderId', 'users'),
            ],
            'notifications' => [
                "`id` $ID PRIMARY KEY", "`userId` $ID NOT NULL", "`text` $T NOT NULL", "`link` $S NULL", "`isRead` $B0",
                "`createdAt` $TS NOT NULL", $fk('userId', 'users', ' ON DELETE CASCADE'),
            ],
            'settings' => [
                "`id` VARCHAR(60) PRIMARY KEY", "`value` $T NULL", "`createdAt` $TS NOT NULL", "`updatedAt` $TS NULL",
            ],
            'contact_messages' => [
                "`id` $ID PRIMARY KEY", "`name` $S NOT NULL", "`email` $S NOT NULL", "`message` $T NOT NULL", "`createdAt` $TS NOT NULL",
            ],
        ];

        $sql = [];
        foreach ($tables as $name => $cols) {
            $sql[$name] = "CREATE TABLE IF NOT EXISTS `$name` (\n  " . implode(",\n  ", $cols) . "\n)$tail";
        }
        return $sql;
    }

    public static function migrate(PDO $pdo): void
    {
        foreach (self::schema(self::isMysql($pdo)) as $stmt) {
            $pdo->exec($stmt);
        }
    }

    public static function dropAll(PDO $pdo): void
    {
        $mysql = self::isMysql($pdo);
        $pdo->exec($mysql ? 'SET FOREIGN_KEY_CHECKS = 0' : 'PRAGMA foreign_keys = OFF');
        foreach (array_reverse(array_keys(self::TABLES)) as $t) {
            $pdo->exec("DROP TABLE IF EXISTS `$t`");
        }
        $pdo->exec($mysql ? 'SET FOREIGN_KEY_CHECKS = 1' : 'PRAGMA foreign_keys = ON');
    }

    /** Turn a stored row into the shape the front end expects. */
    public static function cast(string $table, array $row): array
    {
        $c = self::CASTS[$table] ?? [];
        foreach ($c['bool'] ?? [] as $k) {
            if (array_key_exists($k, $row)) $row[$k] = (bool)(int)$row[$k];
        }
        foreach ($c['int'] ?? [] as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null) $row[$k] = (int)$row[$k];
        }
        foreach ($c['json'] ?? [] as $k) {
            if (array_key_exists($k, $row)) $row[$k] = $row[$k] === null || $row[$k] === '' ? [] : json_decode((string)$row[$k], true);
        }
        return $row;
    }

    /** Prepare PHP values (arrays, booleans) for storage. */
    public static function prepare(string $table, array $row): array
    {
        $c = self::CASTS[$table] ?? [];
        foreach ($c['json'] ?? [] as $k) {
            if (array_key_exists($k, $row) && is_array($row[$k])) $row[$k] = json_encode(array_values($row[$k]), JSON_UNESCAPED_UNICODE);
        }
        foreach ($row as $k => $v) {
            if (is_bool($v)) $row[$k] = $v ? 1 : 0;
        }
        return $row;
    }
}
