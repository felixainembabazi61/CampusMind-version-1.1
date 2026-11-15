<?php
declare(strict_types=1);

/*
 * Server-side validation suite (project report §10). Each test gets a brand-new
 * in-memory SQLite database seeded with the demo data, so the real database is never touched.
 * Run from the System Tests page, or from a terminal:  php api/tests.php
 */

require __DIR__ . '/lib/Database.php';
require __DIR__ . '/lib/CampusMind.php';
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone']);

if ($config['live']) { // the test runner is a development tool; keep it off the public site
    http_response_code(404);
    exit('Not found');
}

final class Skip extends Exception {}

function check(bool $cond, string $msg): void
{
    if (!$cond) throw new RuntimeException($msg);
}

function expectError(callable $fn, string $pattern = ''): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($pattern && !preg_match($pattern, $e->getMessage())) throw new RuntimeException('Wrong error: ' . $e->getMessage());
        return $e;
    }
    throw new RuntimeException('Expected an error but none was thrown');
}

// `php api/tests.php --mysql` runs the same tests on MySQL, in a separate `campusmind_test` database.
$useMysql = PHP_SAPI === 'cli' && in_array('--mysql', $argv, true);

/** @return array{0: CampusMind, 1: PDO} */
function freshApp(): array
{
    global $useMysql, $config;
    if ($useMysql) {
        $m = ['database' => 'campusmind_test'] + $config['mysql'];
        if (getenv('CM_MYSQL_PORT')) $m['port'] = (int)getenv('CM_MYSQL_PORT');
        $pdo = Database::connect(['driver' => 'mysql', 'mysql' => $m]);
        Database::dropAll($pdo);
        Database::migrate($pdo);
    } else {
        $pdo = Database::connect(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
    }
    $app = new CampusMind($pdo, 4); // low bcrypt cost keeps the suite fast
    $app->seed();
    return [$app, $pdo];
}

function as_(CampusMind $app, string $who): array
{
    $cred = [
        'student' => ['student@must.ac.ug', 'Student@123'], 'student2' => ['amina@must.ac.ug', 'Student@123'],
        'counselor' => ['sarah.namara@campusmind.ac.ug', 'Counselor@123'], 'moderator' => ['moderator@campusmind.ac.ug', 'Moderator@123'],
        'admin' => ['admin@campusmind.ac.ug', 'Admin@123'],
    ][$who];
    return $app->login(...$cred);
}

/** A future open slot that doesn't clash with the student's existing bookings. */
function freeSlot(CampusMind $app, array $student): array
{
    $busy = array_map(fn($b) => $app->find('slots', $b['slotId']), $app->rows('bookings', "`studentId` = ? AND `status` = 'confirmed'", [$student['id']]));
    foreach ($app->rows('slots', "`status` = 'open' AND `date` > ?", [CampusMind::addDays(0)], '`date`, `start`') as $s) {
        $clash = false;
        foreach ($busy as $x) if ($x && $x['date'] === $s['date'] && $x['start'] < $s['end'] && $s['start'] < $x['end']) $clash = true;
        if (!$clash) return $s;
    }
    throw new RuntimeException('No free slot available to test with');
}

$tests = [
    ['Database', 'Tables are created and the demo data is seeded', function () {
        [$app, $pdo] = freshApp();
        foreach (array_keys(Database::TABLES) as $t) $pdo->query("SELECT COUNT(*) FROM `$t`");
        check(count($app->rows('users', "`role` = 'counselor'")) >= 1, 'No counselors seeded');
        check(count($app->rows('slots', "`status` = 'open'")) > 0, 'No open slots seeded');
    }],
    ['Database', 'Foreign keys protect related records', function () {
        [, $pdo] = freshApp();
        expectError(fn() => $pdo->exec("INSERT INTO `bookings` (`id`,`slotId`,`studentId`,`counselorId`,`reason`,`status`,`createdAt`) VALUES ('x','missing','usr_s1','usr_c1','r','confirmed','now')"));
    }],
    ['Security', 'Passwords are stored as bcrypt hashes, never plain text', function () {
        [$app] = freshApp();
        foreach ($app->rows('users') as $u) {
            check(str_starts_with($u['passwordHash'], '$2y$'), 'Not a bcrypt hash for ' . $u['email']);
            check(!str_contains($u['passwordHash'], '123'), 'Password visible for ' . $u['email']);
        }
    }],
    ['Security', 'Password hashes are never sent to the browser', function () {
        [$app] = freshApp();
        $json = json_encode($app->snapshot(as_($app, 'admin')));
        check(!str_contains($json, 'passwordHash'), 'Snapshot contains password hashes');
    }],
    ['Auth', 'Login succeeds with valid credentials', function () {
        [$app] = freshApp();
        check(as_($app, 'student')['role'] === 'student', 'Student did not log in');
    }],
    ['Auth', 'Login is rejected with a wrong password or unknown email', function () {
        [$app] = freshApp();
        expectError(fn() => $app->login('student@must.ac.ug', 'wrong-password1'), '/incorrect/i');
        expectError(fn() => $app->login('nobody@must.ac.ug', 'Student@123'), '/incorrect/i');
    }],
    ['Report §10b', 'The login redirect works properly for every role', function () {
        [$app] = freshApp();
        foreach (['student' => 'dashboard.html', 'counselor' => 'counselor.html', 'moderator' => 'moderation.html', 'admin' => 'admin.html'] as $role => $page) {
            check(CampusMind::homeFor(as_($app, $role)) === $page, "$role is not redirected to $page");
        }
    }],
    ['Auth', 'Deactivated accounts cannot log in', function () {
        [$app] = freshApp();
        $app->setUserActive(as_($app, 'admin'), 'usr_s2', false);
        expectError(fn() => as_($app, 'student2'), '/deactivated/i');
    }],
    ['Registration', 'Registration validates input and creates a student account', function () {
        [$app] = freshApp();
        $e = expectError(fn() => $app->register(['name' => 'A', 'email' => 'bad', 'password' => 'short', 'confirm' => 'x', 'studentNo' => '', 'terms' => false]));
        foreach (['name', 'email', 'password', 'confirm', 'studentNo', 'terms'] as $f) check(isset($e->fields[$f]), "No error for $f");
        expectError(fn() => $app->register(['name' => 'Copy Cat', 'email' => 'student@must.ac.ug', 'password' => 'Passw0rd1', 'confirm' => 'Passw0rd1', 'studentNo' => 'X', 'terms' => true]), '/fix/i');
        $u = $app->register(['name' => 'New Student', 'email' => 'new@must.ac.ug', 'password' => 'Passw0rd1', 'confirm' => 'Passw0rd1', 'studentNo' => '2025/BSE/999/PS', 'terms' => true]);
        check($u['role'] === 'student', 'New accounts must be students');
        check($app->login('new@must.ac.ug', 'Passw0rd1')['id'] === $u['id'], 'New account cannot log in');
    }],
    ['Report §10c', 'A user can book an available slot', function () {
        [$app] = freshApp();
        $st = as_($app, 'student');
        $b = $app->bookSlot($st, freeSlot($app, $st)['id'], 'Exam stress before finals');
        check($b['status'] === 'confirmed', 'Booking was not confirmed');
    }],
    ['Report §10d', 'The booking is saved in the database', function () {
        [$app, $pdo] = freshApp();
        $st = as_($app, 'student');
        $slot = freeSlot($app, $st);
        $b = $app->bookSlot($st, $slot['id'], 'Exam stress before finals');
        $row = $pdo->query("SELECT * FROM `bookings` WHERE `id` = " . $pdo->quote($b['id']))->fetch();
        check($row && $row['studentId'] === $st['id'] && $row['slotId'] === $slot['id'], 'Booking row not found in the bookings table');
        check($app->find('slots', $slot['id'])['status'] === 'booked', 'Slot was not marked as booked');
        check(count($app->rows('notifications', '`userId` = ?', [$slot['counselorId']])) > 0, 'Counselor was not notified');
    }],
    ['Booking', 'A slot cannot be double-booked and a reason is required', function () {
        [$app] = freshApp();
        $st = as_($app, 'student');
        $slot = freeSlot($app, $st);
        expectError(fn() => $app->bookSlot($st, $slot['id'], ''), '/talk about/i');
        $app->bookSlot($st, $slot['id'], 'First booking');
        expectError(fn() => $app->bookSlot(as_($app, 'student2'), $slot['id'], 'Second booking'), '/taken/i');
    }],
    ['Booking', 'Cancelling a booking releases the slot', function () {
        [$app] = freshApp();
        $st = as_($app, 'student');
        $slot = freeSlot($app, $st);
        $b = $app->bookSlot($st, $slot['id'], 'Need to talk');
        $app->cancelBooking($st, $b['id']);
        check($app->find('bookings', $b['id'])['status'] === 'cancelled', 'Booking not cancelled');
        check($app->find('slots', $slot['id'])['status'] === 'open', 'Slot not reopened');
    }],
    ['Roles', 'Only counselors/admins manage slots; overlaps are rejected', function () {
        [$app] = freshApp();
        $c = as_($app, 'counselor');
        $date = CampusMind::addDays(30);
        expectError(fn() => $app->addSlot(as_($app, 'student'), ['date' => $date, 'start' => '08:00']), '/permission/i');
        $s = $app->addSlot($c, ['date' => $date, 'start' => '08:00', 'duration' => 60, 'mode' => 'Online']);
        check($s['counselorId'] === $c['id'] && $s['end'] === '09:00', 'Slot created incorrectly');
        expectError(fn() => $app->addSlot($c, ['date' => $date, 'start' => '08:30', 'duration' => 60]), '/overlaps/i');
        expectError(fn() => $app->addSlot($c, ['date' => CampusMind::addDays(-1), 'start' => '10:00']), '/future/i');
    }],
    ['Intake', 'Intake requires consent and flags safety risks as urgent', function () {
        [$app] = freshApp();
        $st = as_($app, 'student');
        $base = ['concerns' => ['Anxiety'], 'description' => 'I have been feeling anxious before every exam this term.', 'mood' => 2, 'urgency' => 'routine'];
        $e = expectError(fn() => $app->submitIntake($st, $base + ['consent' => false]));
        check(isset($e->fields['consent']), 'Consent error missing');
        $i = $app->submitIntake($st, $base + ['consent' => true, 'safetyRisk' => 'yes']);
        check($i['urgency'] === 'urgent' && $i['safetyRisk'] === true, 'Safety risk was not escalated');
    }],
    ['Privacy', 'Students only receive their own bookings and intakes', function () {
        [$app] = freshApp();
        $snap = $app->snapshot(as_($app, 'student'));
        foreach ($snap['data']['bookings'] as $b) check($b['studentId'] === 'usr_s1', 'Another student\'s booking was exposed');
        foreach ($snap['data']['intakes'] as $i) check($i['studentId'] === 'usr_s1', 'Another student\'s intake was exposed');
        check(!$snap['data']['reports'], 'Reports exposed to a student');
        foreach ($snap['data']['users'] as $u) if ($u['id'] !== 'usr_s1') check(!isset($u['email']), 'Other users\' emails exposed to a student');
    }],
    ['Privacy', 'Anonymous authors stay hidden from other students', function () {
        [$app] = freshApp();
        $snap = $app->snapshot(as_($app, 'student'));
        foreach ($snap['data']['stories'] as $s) if ($s['anonymous']) check($s['authorId'] === null || $s['authorId'] === 'usr_s1', 'Anonymous story author exposed');
        $modSnap = $app->snapshot(as_($app, 'moderator'));
        check((bool)array_filter($modSnap['data']['stories'], fn($s) => $s['anonymous'] && $s['authorId']), 'Moderators should see who wrote anonymous stories');
    }],
    ['Stories', 'Student stories wait for moderation before publishing', function () {
        [$app] = freshApp();
        $st = as_($app, 'student');
        $s = $app->submitStory($st, ['title' => 'Finding my feet', 'body' => 'It took me a whole semester to feel at home here, and that is perfectly okay to admit.', 'anonymous' => true]);
        check($s['status'] === 'pending', 'Story published without review');
        expectError(fn() => $app->moderateStory($st, $s['id'], 'published'), '/permission/i');
        $app->moderateStory(as_($app, 'moderator'), $s['id'], 'published');
        check($app->find('stories', $s['id'])['status'] === 'published', 'Approved story not published');
    }],
    ['Forum', 'Posts and replies work; locked threads block student replies', function () {
        [$app] = freshApp();
        $st = as_($app, 'student');
        $mod = as_($app, 'moderator');
        $p = $app->createPost($st, ['categoryId' => 'cat_1', 'title' => 'Study tips?', 'body' => 'What helps you focus?']);
        $app->addComment($mod, $p['id'], 'Try the Pomodoro method.');
        check(count($app->rows('forum_comments', '`postId` = ?', [$p['id']])) === 1, 'Reply not saved');
        expectError(fn() => $app->createPost($st, ['categoryId' => 'cat_5', 'title' => 'Fake announcement', 'body' => 'Students cannot post here']));
        $app->setPostFlags($mod, $p['id'], ['locked' => true]);
        expectError(fn() => $app->addComment($st, $p['id'], 'Late reply'), '/locked/i');
    }],
    ['Reports', 'Reporting content and hiding it through moderation', function () {
        [$app] = freshApp();
        $st = as_($app, 'student');
        $r = $app->reportContent($st, 'post', 'pst_4', 'Spam or off-topic');
        expectError(fn() => $app->reportContent($st, 'post', 'pst_4', 'Spam or off-topic'), '/already/i');
        $app->resolveReport(as_($app, 'moderator'), $r['id'], 'hide');
        check($app->find('forum_posts', 'pst_4')['status'] === 'hidden', 'Reported post still visible');
    }],
    ['Messages', 'Students can message counselors but not other students', function () {
        [$app] = freshApp();
        $st = as_($app, 'student');
        $c = $app->openConversation($st, 'usr_c2');
        $app->sendMessage($st, $c['id'], 'Hello, can we talk?');
        check(count($app->rows('messages', '`conversationId` = ?', [$c['id']])) === 1, 'Message not saved');
        expectError(fn() => $app->openConversation($st, 'usr_s2'), '/cannot/i');
        expectError(fn() => $app->sendMessage(as_($app, 'student2'), $c['id'], 'Snooping'), '/not found/i');
    }],
    ['Admin', 'Only administrators can change roles and contact details', function () {
        [$app] = freshApp();
        $admin = as_($app, 'admin');
        $c = as_($app, 'counselor');
        expectError(fn() => $app->setUserRole($c, 'usr_s1', 'admin'), '/permission/i');
        $app->setUserRole($admin, 'usr_s3', 'moderator');
        check($app->find('users', 'usr_s3')['role'] === 'moderator', 'Role not updated');
        expectError(fn() => $app->setUserRole($admin, $admin['id'], 'student'), '/own role/i');
        expectError(fn() => $app->updateSettings($c, ['phone' => '+256 711 111 111']), '/permission/i');
        expectError(fn() => $app->updateSettings($admin, ['email' => 'not-an-email']));
        $app->updateSettings($admin, ['phone' => '+256 711 111 111']);
        check($app->settings()['phone'] === '+256 711 111 111', 'Contact phone not saved');
    }],
];

$results = [];
$t0 = microtime(true);
foreach ($tests as [$area, $name, $fn]) {
    try {
        $fn();
        $results[] = ['status' => 'pass', 'area' => $area, 'name' => $name];
    } catch (Skip $e) {
        $results[] = ['status' => 'skip', 'area' => $area, 'name' => $name, 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        $results[] = ['status' => 'fail', 'area' => $area, 'name' => $name, 'message' => $e->getMessage()];
    }
}
$out = ['ms' => (int)round((microtime(true) - $t0) * 1000), 'results' => $results];

if (PHP_SAPI === 'cli') {
    foreach ($results as $r) echo str_pad(strtoupper($r['status']), 6) . $r['name'] . (isset($r['message']) ? '  -> ' . $r['message'] : '') . PHP_EOL;
    $failed = count(array_filter($results, fn($r) => $r['status'] === 'fail'));
    echo PHP_EOL . (count($results) - $failed) . ' passed, ' . $failed . ' failed in ' . $out['ms'] . 'ms' . PHP_EOL;
    exit($failed ? 1 : 0);
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($out);
