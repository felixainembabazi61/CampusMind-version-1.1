<?php
declare(strict_types=1);

/*
 * CampusMind JSON API.  POST api/index.php?action=<name>  with a JSON body.
 * Every successful call returns { ok: true, result, snapshot } where snapshot is
 * the data the signed-in user may see. Errors return { ok: false, error, fields? }.
 */

require __DIR__ . '/lib/Database.php';
require __DIR__ . '/lib/CampusMind.php';

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone']);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['ok' => false, 'error' => 'Use POST.']);
// Browsers cannot send this custom header cross-site without CORS approval, so it blocks CSRF.
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'CampusMind') respond(403, ['ok' => false, 'error' => 'Forbidden.']);

session_name('campusmind');
$https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
// Secure: the cookie is only ever sent over HTTPS when the site is served over HTTPS
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'path' => '/', 'secure' => $https]);
session_start();

try {
    $pdo = Database::connect($config);
    $app = new CampusMind($pdo, (int)$config['bcrypt_cost'], $config['demo_passwords'] ?? null, (bool)$config['live']);
    $app->seedIfEmpty();
    $app->topUpSlots();
} catch (Throwable $e) {
    error_log('CampusMind DB error: ' . $e->getMessage());
    // on the live site never show database details to visitors (they go to the PHP error log instead)
    respond(500, ['ok' => false, 'error' => $config['live']
        ? 'The service is temporarily unavailable. Please try again later.'
        : 'Cannot connect to the database. Check the settings in api/config.php. (' . $e->getMessage() . ')']);
}

function currentUser(CampusMind $app): ?array
{
    if (empty($_SESSION['uid'])) return null;
    if (($_SESSION['expires'] ?? 0) < time()) {
        $_SESSION = [];
        return null;
    }
    $u = $app->find('users', $_SESSION['uid']);
    if (!$u || !$u['active']) {
        $_SESSION = [];
        return null;
    }
    return $u;
}

function startSession(array $u, array $config): void
{
    session_regenerate_id(true);
    $_SESSION = ['uid' => $u['id'], 'expires' => time() + $config['session_hours'] * 3600];
}

$user = currentUser($app);
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$s = fn(string $k): string => is_scalar($in[$k] ?? null) ? (string)$in[$k] : '';
$action = (string)($_GET['action'] ?? '');

try {
    switch ($action) {
        case 'bootstrap':
            $result = null;
            break;

        /* auth */
        case 'login':
            if (($_SESSION['fails'] ?? 0) >= 5 && ($_SESSION['failAt'] ?? 0) > time() - 60) {
                throw new ValidationError('Too many attempts. Please wait a minute and try again.');
            }
            try {
                $u = $app->login($s('email'), $s('password'));
            } catch (ValidationError $e) {
                $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
                $_SESSION['failAt'] = time();
                throw $e;
            }
            startSession($u, $config);
            $user = $u;
            $result = ['home' => CampusMind::homeFor($u)];
            break;
        case 'register':
            $u = $app->register($in);
            startSession($u, $config);
            $user = $u;
            $result = ['home' => CampusMind::homeFor($u)];
            break;
        case 'logout':
            $_SESSION = [];
            session_regenerate_id(true);
            $user = null;
            $result = true;
            break;
        case 'changePassword':  $app->changePassword($user, $s('current'), $s('next')); $result = true; break;

        /* slots + bookings */
        case 'addSlots':        $result = $app->addSlots($user, $in); break;
        case 'removeSlot':      $app->removeSlot($user, $s('slotId')); $result = true; break;
        case 'bookSlot':        $result = $app->bookSlot($user, $s('slotId'), $s('reason')); break;
        case 'cancelBooking':   $result = $app->cancelBooking($user, $s('bookingId')); break;
        case 'setBookingStatus':
            $result = $app->setBookingStatus($user, $s('bookingId'), $s('status'), array_key_exists('notes', $in) ? $s('notes') : null);
            break;

        /* intake */
        case 'submitIntake':    $result = $app->submitIntake($user, $in); break;
        case 'updateIntake':    $result = $app->updateIntake($user, $s('id'), (array)($in['patch'] ?? [])); break;

        /* stories, reactions, forum, reports */
        case 'submitStory':     $result = $app->submitStory($user, $in); break;
        case 'moderateStory':   $result = $app->moderateStory($user, $s('id'), $s('status'), $s('note')); break;
        case 'toggleReaction':  $result = $app->toggleReaction($user, $s('targetType'), $s('targetId'), $s('type')); break;
        case 'addCategory':     $result = $app->addCategory($user, $in); break;
        case 'createPost':      $result = $app->createPost($user, $in); break;
        case 'addComment':      $result = $app->addComment($user, $s('postId'), $s('body'), !empty($in['anonymous'])); break;
        case 'setPostFlags':    $result = $app->setPostFlags($user, $s('postId'), (array)($in['flags'] ?? [])); break;
        case 'deleteOwn':       $app->deleteOwn($user, $s('type'), $s('id')); $result = true; break;
        case 'restoreContent':  $app->restoreContent($user, $s('type'), $s('id')); $result = true; break;
        case 'reportContent':   $result = $app->reportContent($user, $s('targetType'), $s('targetId'), $s('reason'), $s('details')); break;
        case 'resolveReport':   $result = $app->resolveReport($user, $s('reportId'), $s('action')); break;
        case 'escalateReport':  $app->escalateReport($user, $s('reportId')); $result = true; break;

        /* messaging + notifications */
        case 'openConversation': $result = $app->openConversation($user, $s('otherId')); break;
        case 'sendMessage':     $result = $app->sendMessage($user, $s('conversationId'), $s('body')); break;
        case 'markRead':        $app->markRead($user, $s('conversationId')); $result = true; break;
        case 'markNotificationsRead': $app->markNotificationsRead($user); $result = true; break;

        /* admin */
        case 'setUserRole':     $result = $app->setUserRole($user, $s('userId'), $s('role')); break;
        case 'setUserActive':   $result = $app->setUserActive($user, $s('userId'), !empty($in['active'])); break;
        case 'createStaff':     $result = $app->createStaff($user, $in); break;
        case 'updateSettings':  $result = $app->updateSettings($user, $in); break;
        case 'exportData':      $result = $app->exportData($user); break;
        case 'resetData':
            $app->resetData($user);
            $_SESSION = [];
            $user = null;
            $result = true;
            break;
        case 'contact':         $result = $app->contact($in); break;

        default:
            respond(404, ['ok' => false, 'error' => 'Unknown action.']);
    }

    if ($user) $user = $app->find('users', $user['id']); // pick up any role/status change
    respond(200, ['ok' => true, 'result' => $result, 'snapshot' => $app->snapshot($user)]);
} catch (ValidationError $e) {
    respond(422, ['ok' => false, 'error' => $e->getMessage(), 'fields' => (object)$e->fields]);
} catch (RuntimeException $e) {
    respond(400, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('CampusMind error: ' . $e);
    respond(500, ['ok' => false, 'error' => 'Something went wrong on the server. Please try again.']);
}
