<?php
declare(strict_types=1);

class ValidationError extends RuntimeException
{
    public array $fields;

    public function __construct(string $message, array $fields = [])
    {
        parent::__construct($message);
        $this->fields = $fields;
    }
}

/**
 * CampusMind business logic. Each section corresponds to a controller in the
 * project report (LoginController, BookingController, IntakeController, ...).
 * Every method that changes data receives the acting user and checks their role.
 */
final class CampusMind
{
    public const ROLES = ['student', 'counselor', 'moderator', 'admin'];
    private const REACTIONS = ['support', 'relate', 'thanks'];
    private const TARGET_TABLE = ['story' => 'stories', 'post' => 'forum_posts', 'comment' => 'forum_comments'];
    private const PASSWORD_RULE = '/^(?=.*[A-Za-z])(?=.*\d).{8,}$/';
    public const DEFAULT_SETTINGS = [
        'topbarMessage' => 'Free & confidential support for every MUST student',
        'address'       => "Counseling Centre, Student Affairs Block\nMUST Main Campus, Mbarara, Uganda",
        'addressShort'  => 'Student Affairs Block, MUST Main Campus, Mbarara',
        'phone'         => '+256 700 000 000',
        'email'         => 'counseling@campusmind.ac.ug',
        'hoursWeekdays' => 'Mon – Fri: 8:00 AM – 6:00 PM',
        'hoursSaturday' => 'Sat: 9:00 AM – 1:00 PM',
        'emergency'     => '112, 999',
    ];

    /**
     * @param array|null $demoPasswords role => password for the seeded demo accounts (live site); null = simple local passwords
     * @param bool $live live (public) site: destructive admin tools are switched off
     */
    public function __construct(private PDO $db, private int $bcryptCost = 10, private ?array $demoPasswords = null, private bool $live = false)
    {
    }

    /* ================= low-level helpers ================= */

    public static function uid(string $prefix): string
    {
        return $prefix . '_' . base_convert((string)time(), 10, 36) . bin2hex(random_bytes(4));
    }

    public static function nowIso(?int $ts = null): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z', $ts ?? time());
    }

    public static function addDays(int $days, ?string $from = null): string
    {
        $base = $from ? strtotime($from . ' 12:00:00') : strtotime('today 12:00:00');
        return date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days', $base));
    }

    private static function addMinutes(string $time, int $mins): string
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        $t = $h * 60 + $m + $mins;
        return sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
    }

    private static function slotStart(array $slot): int
    {
        return (int)strtotime($slot['date'] . ' ' . $slot['start'] . ':00');
    }

    private static function isEmail(string $v): bool
    {
        return (bool)filter_var($v, FILTER_VALIDATE_EMAIL);
    }

    private static function str(mixed $v, int $max = 10000): string
    {
        return mb_substr(trim(is_scalar($v) ? (string)$v : ''), 0, $max);
    }

    public function find(string $table, ?string $id): ?array
    {
        if ($id === null || $id === '') return null;
        $st = $this->db->prepare("SELECT * FROM `$table` WHERE `id` = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ? Database::cast($table, $row) : null;
    }

    public function rows(string $table, string $where = '1=1', array $params = [], string $order = ''): array
    {
        $st = $this->db->prepare("SELECT * FROM `$table` WHERE $where" . ($order ? " ORDER BY $order" : ''));
        $st->execute($params);
        return array_map(fn($r) => Database::cast($table, $r), $st->fetchAll());
    }

    private function count(string $table, string $where = '1=1', array $params = []): int
    {
        $st = $this->db->prepare("SELECT COUNT(*) FROM `$table` WHERE $where");
        $st->execute($params);
        return (int)$st->fetchColumn();
    }

    public function insert(string $table, array $row): array
    {
        $row = array_merge(['id' => self::uid(substr($table, 0, 3)), 'createdAt' => self::nowIso()], $row);
        $data = Database::prepare($table, $row);
        $cols = array_keys($data);
        $sql = "INSERT INTO `$table` (`" . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $this->db->prepare($sql)->execute(array_values($data));
        return Database::cast($table, $data);
    }

    public function update(string $table, string $id, array $patch, bool $touch = true): array
    {
        if ($touch && !in_array($table, ['reactions', 'conversations', 'messages', 'notifications', 'contact_messages'], true)) {
            $patch['updatedAt'] = self::nowIso();
        }
        $data = Database::prepare($table, $patch);
        if ($data) {
            $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
            $this->db->prepare("UPDATE `$table` SET $set WHERE `id` = ?")->execute([...array_values($data), $id]);
        }
        $row = $this->find($table, $id);
        if (!$row) throw new RuntimeException('Record not found.');
        return $row;
    }

    private function delete(string $table, string $id): void
    {
        $this->db->prepare("DELETE FROM `$table` WHERE `id` = ?")->execute([$id]);
    }

    private function need(?array $user, ?array $roles = null): void
    {
        if (!$user) throw new RuntimeException('Please log in to continue.');
        if (!$user['active']) throw new RuntimeException('This account has been deactivated.');
        if ($roles && !in_array($user['role'], $roles, true)) throw new RuntimeException('You do not have permission to do that.');
    }

    private function notify(string $userId, string $text, string $link = ''): void
    {
        $this->insert('notifications', ['userId' => $userId, 'text' => mb_substr($text, 0, 500), 'link' => $link, 'isRead' => false]);
    }

    private function notifyRoles(array $roles, string $text, string $link): void
    {
        $in = implode(',', array_fill(0, count($roles), '?'));
        foreach ($this->rows('users', "`role` IN ($in) AND `active` = 1", $roles) as $u) {
            $this->notify($u['id'], $text, $link);
        }
    }

    public function displayName(?string $userId, bool $anonymous = false): string
    {
        if ($anonymous) return 'Anonymous student';
        $u = $this->find('users', $userId);
        return $u['name'] ?? 'Former member';
    }

    private function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => $this->bcryptCost]);
    }

    /* ================= Auth/LoginController ================= */

    public function register(array $in): array
    {
        $errors = [];
        $name = self::str($in['name'] ?? '', 120);
        $email = strtolower(self::str($in['email'] ?? '', 190));
        $password = (string)($in['password'] ?? '');
        if (mb_strlen($name) < 3) $errors['name'] = 'Enter your full name.';
        if (!self::isEmail($email)) $errors['email'] = 'Enter a valid email address.';
        elseif ($this->count('users', '`email` = ?', [$email])) $errors['email'] = 'An account with this email already exists.';
        if (!preg_match(self::PASSWORD_RULE, $password)) $errors['password'] = 'Use at least 8 characters with letters and numbers.';
        if ($password !== (string)($in['confirm'] ?? '')) $errors['confirm'] = 'Passwords do not match.';
        if (self::str($in['studentNo'] ?? '') === '') $errors['studentNo'] = 'Enter your registration number.';
        if (empty($in['terms'])) $errors['terms'] = 'Please accept the confidentiality terms.';
        if ($errors) throw new ValidationError('Please fix the highlighted fields.', $errors);

        $u = $this->insert('users', [
            'id' => self::uid('usr'), 'name' => $name, 'email' => $email, 'role' => 'student', 'passwordHash' => $this->hash($password),
            'studentNo' => self::str($in['studentNo'], 40), 'faculty' => self::str($in['faculty'] ?? '', 120), 'active' => true, 'specialties' => [],
        ]);
        $this->notify($u['id'], 'Welcome to CampusMind! Start by booking a session or completing an intake form.', 'dashboard.html');
        return $u;
    }

    public function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        if (!self::isEmail($email) || $password === '') throw new ValidationError('Enter your email and password.');
        $u = $this->rows('users', '`email` = ?', [$email])[0] ?? null;
        if (!$u || !password_verify($password, $u['passwordHash'])) throw new ValidationError('Incorrect email or password.');
        if (!$u['active']) throw new ValidationError('This account has been deactivated. Contact the administrator.');
        $patch = ['lastLogin' => self::nowIso()];
        if (password_needs_rehash($u['passwordHash'], PASSWORD_BCRYPT, ['cost' => $this->bcryptCost])) $patch['passwordHash'] = $this->hash($password);
        return $this->update('users', $u['id'], $patch, false);
    }

    public static function homeFor(array $user): string
    {
        return ['student' => 'dashboard.html', 'counselor' => 'counselor.html', 'moderator' => 'moderation.html', 'admin' => 'admin.html'][$user['role']] ?? 'index.html';
    }

    public function changePassword(?array $user, string $current, string $next): void
    {
        $this->need($user);
        if (!password_verify($current, $user['passwordHash'])) throw new ValidationError('Current password is incorrect.');
        if (!preg_match(self::PASSWORD_RULE, $next)) throw new ValidationError('Use at least 8 characters with letters and numbers.');
        $this->update('users', $user['id'], ['passwordHash' => $this->hash($next)]);
    }

    /* ================= Slots (counselor availability) ================= */

    public function addSlot(?array $user, array $in): array
    {
        $this->need($user, ['counselor', 'admin']);
        $counselorId = $user['role'] === 'admin' ? (string)($in['counselorId'] ?? '') : $user['id'];
        $counselor = $this->find('users', $counselorId);
        if (!$counselor || $counselor['role'] !== 'counselor') throw new ValidationError('Choose a counselor.');
        $date = (string)($in['date'] ?? '');
        $start = (string)($in['start'] ?? '');
        $duration = (int)($in['duration'] ?? 60);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $start)) throw new ValidationError('Choose a date and start time.');
        if ($duration < 15 || $duration > 180) throw new ValidationError('Sessions must be between 15 and 180 minutes.');
        $slot = ['counselorId' => $counselorId, 'date' => $date, 'start' => $start, 'end' => self::addMinutes($start, $duration),
            'mode' => ($in['mode'] ?? '') === 'Online' ? 'Online' : 'In-person', 'status' => 'open'];
        if (self::slotStart($slot) <= time()) throw new ValidationError('Slots must be in the future.');
        if ($slot['start'] < '07:00' || $slot['end'] > '20:00') throw new ValidationError('Slots must be between 07:00 and 20:00.');
        $clash = $this->rows('slots', "`counselorId` = ? AND `date` = ? AND `status` <> 'cancelled' AND `start` < ? AND ? < `end`", [$counselorId, $date, $slot['end'], $slot['start']]);
        if ($clash) throw new ValidationError('This overlaps an existing slot (' . $clash[0]['start'] . '–' . $clash[0]['end'] . ').');
        return $this->insert('slots', ['id' => self::uid('slt')] + $slot);
    }

    /** Adds one slot, the next 5 weekdays, or the same slot weekly for 4 weeks. */
    public function addSlots(?array $user, array $in): array
    {
        $date = (string)($in['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new ValidationError('Choose a date.');
        $dates = [];
        $repeat = (string)($in['repeat'] ?? '1');
        if ($repeat === '5') {
            for ($k = 0; count($dates) < 5 && $k < 10; $k++) {
                $d = self::addDays($k, $date);
                $dow = (int)date('w', strtotime($d));
                if ($dow > 0 && $dow < 6) $dates[] = $d;
            }
        } elseif ($repeat === '4w') {
            for ($w = 0; $w < 4; $w++) $dates[] = self::addDays($w * 7, $date);
        } else {
            $dates[] = $date;
        }
        $added = 0;
        $errors = [];
        foreach ($dates as $d) {
            try {
                $this->addSlot($user, ['date' => $d] + $in);
                $added++;
            } catch (ValidationError $e) {
                $errors[] = $d . ': ' . $e->getMessage();
            }
        }
        if (!$added && $errors) throw new ValidationError($errors[0]);
        return ['added' => $added, 'errors' => $errors];
    }

    public function removeSlot(?array $user, string $slotId): void
    {
        $this->need($user, ['counselor', 'admin']);
        $s = $this->find('slots', $slotId);
        if (!$s) throw new RuntimeException('Slot not found.');
        if ($user['role'] !== 'admin' && $s['counselorId'] !== $user['id']) throw new RuntimeException('You can only manage your own slots.');
        if ($s['status'] === 'booked') throw new RuntimeException('This slot is booked. Cancel the booking first.');
        // keep slots that past bookings point to, so session history stays intact
        if ($this->count('bookings', '`slotId` = ?', [$slotId])) $this->update('slots', $slotId, ['status' => 'cancelled']);
        else $this->delete('slots', $slotId);
    }

    /* ================= BookingController ================= */

    public function bookSlot(?array $user, string $slotId, string $reason): array
    {
        $this->need($user, ['student']);
        $slot = $this->find('slots', $slotId);
        $reason = self::str($reason, 500);
        if (!$slot) throw new RuntimeException('That slot no longer exists.');
        if ($slot['status'] !== 'open') throw new RuntimeException('Sorry, that slot has just been taken. Please pick another time.');
        if (self::slotStart($slot) <= time()) throw new RuntimeException('That slot is in the past.');
        if (mb_strlen($reason) < 5) throw new ValidationError('Tell us briefly what you would like to talk about (at least 5 characters).', ['reason' => 'Required']);

        $mine = $this->rows('bookings', "`studentId` = ? AND `status` = 'confirmed'", [$user['id']]);
        $upcoming = 0;
        foreach ($mine as $b) {
            $s = $this->find('slots', $b['slotId']);
            if (!$s) continue;
            if ($s['date'] === $slot['date'] && $s['start'] < $slot['end'] && $slot['start'] < $s['end']) throw new RuntimeException('You already have a session at that time.');
            if (self::slotStart($s) > time()) $upcoming++;
        }
        if ($upcoming >= 3) throw new RuntimeException('You can hold up to 3 upcoming sessions. Cancel one to book another.');

        // claim the slot atomically so two students can never book the same time
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare("UPDATE `slots` SET `status` = 'booked', `updatedAt` = ? WHERE `id` = ? AND `status` = 'open'");
            $st->execute([self::nowIso(), $slotId]);
            if ($st->rowCount() !== 1) throw new RuntimeException('Sorry, that slot has just been taken. Please pick another time.');
            $booking = $this->insert('bookings', ['id' => self::uid('bkg'), 'slotId' => $slotId, 'studentId' => $user['id'], 'counselorId' => $slot['counselorId'],
                'reason' => $reason, 'status' => 'confirmed', 'notes' => '']);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $when = $slot['date'] . ' at ' . $slot['start'];
        $this->notify($user['id'], 'Your session with ' . $this->displayName($slot['counselorId']) . ' on ' . $when . ' is confirmed.', 'dashboard.html');
        $this->notify($slot['counselorId'], $user['name'] . ' booked a session on ' . $when . '.', 'counselor.html');
        return $booking;
    }

    public function cancelBooking(?array $user, string $bookingId): array
    {
        $this->need($user);
        $b = $this->find('bookings', $bookingId);
        if (!$b) throw new RuntimeException('Booking not found.');
        if (!($user['role'] === 'admin' || $b['studentId'] === $user['id'] || $b['counselorId'] === $user['id'])) throw new RuntimeException('You cannot cancel this booking.');
        if ($b['status'] !== 'confirmed') throw new RuntimeException('Only confirmed bookings can be cancelled.');
        $b = $this->update('bookings', $bookingId, ['status' => 'cancelled', 'cancelledBy' => $user['id']]);
        $slot = $this->find('slots', $b['slotId']);
        if ($slot) $this->update('slots', $slot['id'], ['status' => self::slotStart($slot) > time() ? 'open' : 'cancelled']);
        $when = $slot ? $slot['date'] . ' at ' . $slot['start'] : 'an upcoming date';
        if ($user['id'] !== $b['studentId']) $this->notify($b['studentId'], 'Your session on ' . $when . ' was cancelled by ' . $user['name'] . '. Please book another time.', 'book.html');
        if ($user['id'] !== $b['counselorId']) $this->notify($b['counselorId'], 'The session on ' . $when . ' was cancelled by ' . $user['name'] . '.', 'counselor.html');
        return $b;
    }

    public function setBookingStatus(?array $user, string $bookingId, string $status, ?string $notes = null): array
    {
        $this->need($user, ['counselor', 'admin']);
        $b = $this->find('bookings', $bookingId);
        if (!$b) throw new RuntimeException('Booking not found.');
        if ($user['role'] !== 'admin' && $b['counselorId'] !== $user['id']) throw new RuntimeException('Not your session.');
        if (!in_array($status, ['completed', 'no-show', 'confirmed'], true)) throw new RuntimeException('Invalid status.');
        return $this->update('bookings', $bookingId, ['status' => $status, 'notes' => $notes !== null ? self::str($notes, 1000) : $b['notes']]);
    }

    /* ================= IntakeController ================= */

    public function submitIntake(?array $user, array $in): array
    {
        $this->need($user, ['student']);
        $errors = [];
        $concerns = array_values(array_filter(array_map(fn($c) => self::str($c, 60), (array)($in['concerns'] ?? []))));
        $description = self::str($in['description'] ?? '', 2000);
        $mood = (int)($in['mood'] ?? 0);
        $urgency = (string)($in['urgency'] ?? '');
        if (!$concerns) $errors['concerns'] = 'Select at least one area you need support with.';
        if (mb_strlen($description) < 20) $errors['description'] = 'Describe what is going on in at least 20 characters.';
        if ($mood < 1 || $mood > 5) $errors['mood'] = 'Tell us how you are feeling today.';
        if (!in_array($urgency, ['routine', 'soon', 'urgent'], true)) $errors['urgency'] = 'Choose how soon you need support.';
        if (empty($in['consent'])) $errors['consent'] = 'Consent is required so a counselor can review your intake.';
        if ($errors) throw new ValidationError('Please complete the required fields.', $errors);

        $risk = ($in['safetyRisk'] ?? '') === 'yes';
        $urgent = $urgency === 'urgent' || $risk;
        $preferred = (string)($in['preferredCounselorId'] ?? '');
        $pc = $this->find('users', $preferred);
        if (!$pc || $pc['role'] !== 'counselor') $preferred = '';
        $intake = $this->insert('intakes', [
            'id' => self::uid('ink'), 'studentId' => $user['id'], 'concerns' => array_slice($concerns, 0, 12), 'description' => $description, 'mood' => $mood,
            'duration' => self::str($in['duration'] ?? '', 40), 'urgency' => $urgent ? 'urgent' : $urgency, 'safetyRisk' => $risk,
            'contactPref' => self::str($in['contactPref'] ?? 'In-person', 40), 'preferredCounselorId' => $preferred, 'status' => 'new', 'assignedTo' => null,
        ]);
        $text = ($urgent ? 'URGENT intake' : 'New intake') . ' submitted by a student.';
        if ($preferred) $this->notify($preferred, $text, 'counselor.html#intakes');
        else $this->notifyRoles(['counselor'], $text, 'counselor.html#intakes');
        return $intake;
    }

    public function updateIntake(?array $user, string $id, array $patch): array
    {
        $this->need($user, ['counselor', 'admin']);
        $i = $this->find('intakes', $id);
        if (!$i) throw new RuntimeException('Intake not found.');
        $allowed = [];
        if (isset($patch['status']) && in_array($patch['status'], ['new', 'reviewed', 'assigned', 'closed'], true)) $allowed['status'] = $patch['status'];
        if (array_key_exists('assignedTo', $patch)) {
            $c = $this->find('users', (string)$patch['assignedTo']);
            if (!$c || $c['role'] !== 'counselor') throw new RuntimeException('Intakes can only be assigned to a counselor.');
            $allowed['assignedTo'] = $c['id'];
        }
        if (array_key_exists('counselorNote', $patch)) $allowed['counselorNote'] = self::str($patch['counselorNote'], 1000);
        $row = $this->update('intakes', $id, $allowed);
        if (isset($allowed['assignedTo'])) $this->notify($i['studentId'], 'A counselor has been assigned to your intake and will follow up.', 'dashboard.html');
        return $row;
    }

    /* ================= StoryController ================= */

    public function submitStory(?array $user, array $in): array
    {
        $this->need($user, ['student', 'counselor', 'admin']);
        $title = self::str($in['title'] ?? '', 120);
        $body = self::str($in['body'] ?? '', 4000);
        $errors = [];
        if (mb_strlen($title) < 5) $errors['title'] = 'Give your story a title (at least 5 characters).';
        if (mb_strlen($body) < 50) $errors['body'] = 'Stories need at least 50 characters.';
        if ($errors) throw new ValidationError('Please complete your story.', $errors);
        $tags = array_slice(array_values(array_filter(array_map(fn($t) => self::str($t, 40), (array)($in['tags'] ?? [])))), 0, 4);
        $story = $this->insert('stories', ['id' => self::uid('sty'), 'authorId' => $user['id'], 'anonymous' => !empty($in['anonymous']), 'title' => $title, 'body' => $body,
            'tags' => $tags, 'status' => $user['role'] === 'admin' ? 'published' : 'pending']);
        if ($story['status'] === 'pending') $this->notifyRoles(['moderator', 'admin'], 'A new story is waiting for review.', 'moderation.html');
        return $story;
    }

    public function moderateStory(?array $user, string $id, string $status, string $note = ''): array
    {
        $this->need($user, ['moderator', 'admin']);
        if (!in_array($status, ['published', 'rejected', 'hidden'], true)) throw new RuntimeException('Invalid status.');
        if (!$this->find('stories', $id)) throw new RuntimeException('Story not found.');
        $s = $this->update('stories', $id, ['status' => $status, 'moderatorNote' => self::str($note, 500), 'moderatedBy' => $user['id']]);
        $this->notify($s['authorId'], $status === 'published'
            ? 'Your story "' . $s['title'] . '" is now live. Thank you for sharing.'
            : 'Your story "' . $s['title'] . '" was not published. ' . $note, 'stories.html');
        return $s;
    }

    /* ================= ReactionController ================= */

    public function toggleReaction(?array $user, string $targetType, string $targetId, string $type): bool
    {
        $this->need($user);
        if (!in_array($type, self::REACTIONS, true)) throw new RuntimeException('Unknown reaction.');
        if (!in_array($targetType, ['story', 'post'], true) || !$this->find(self::TARGET_TABLE[$targetType], $targetId)) throw new RuntimeException('Content not found.');
        $existing = $this->rows('reactions', '`targetType` = ? AND `targetId` = ? AND `userId` = ? AND `type` = ?', [$targetType, $targetId, $user['id'], $type]);
        if ($existing) {
            $this->delete('reactions', $existing[0]['id']);
            return false;
        }
        $this->insert('reactions', ['id' => self::uid('rct'), 'targetType' => $targetType, 'targetId' => $targetId, 'userId' => $user['id'], 'type' => $type]);
        return true;
    }

    /* ================= ForumController, PostController, CommentController ================= */

    public function addCategory(?array $user, array $in): array
    {
        $this->need($user, ['admin']);
        $name = self::str($in['name'] ?? '', 80);
        if ($name === '') throw new ValidationError('Category name is required.');
        if ($this->count('forum_categories', 'LOWER(`name`) = ?', [mb_strtolower($name)])) throw new ValidationError('That category already exists.');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['color'] ?? '')) ? $in['color'] : '#2f7a5c';
        return $this->insert('forum_categories', ['id' => self::uid('cat'), 'name' => $name, 'description' => self::str($in['description'] ?? '', 255), 'color' => $color]);
    }

    public function createPost(?array $user, array $in): array
    {
        $this->need($user);
        $errors = [];
        $categoryId = (string)($in['categoryId'] ?? '');
        $title = self::str($in['title'] ?? '', 150);
        $body = self::str($in['body'] ?? '', 4000);
        if (!$this->find('forum_categories', $categoryId)) $errors['categoryId'] = 'Choose a category.';
        if (mb_strlen($title) < 5) $errors['title'] = 'Title needs at least 5 characters.';
        if (mb_strlen($body) < 10) $errors['body'] = 'Write at least 10 characters.';
        if ($categoryId === 'cat_5' && $user['role'] !== 'admin') $errors['categoryId'] = 'Only administrators can post announcements.';
        if ($errors) throw new ValidationError('Please complete your post.', $errors);
        return $this->insert('forum_posts', ['id' => self::uid('pst'), 'categoryId' => $categoryId, 'authorId' => $user['id'],
            'anonymous' => !empty($in['anonymous']) && $user['role'] === 'student', 'title' => $title, 'body' => $body,
            'pinned' => false, 'locked' => false, 'status' => 'visible']);
    }

    public function addComment(?array $user, string $postId, string $body, bool $anonymous = false): array
    {
        $this->need($user);
        $p = $this->find('forum_posts', $postId);
        if (!$p || $p['status'] !== 'visible') throw new RuntimeException('This discussion is not available.');
        if ($p['locked'] && !in_array($user['role'], ['moderator', 'admin'], true)) throw new RuntimeException('This discussion is locked.');
        $body = self::str($body, 2000);
        if (mb_strlen($body) < 2) throw new ValidationError('Write a reply first.', ['body' => 'Write a reply first.']);
        $c = $this->insert('forum_comments', ['id' => self::uid('cmt'), 'postId' => $postId, 'authorId' => $user['id'],
            'anonymous' => $anonymous && $user['role'] === 'student', 'body' => $body, 'status' => 'visible']);
        if ($p['authorId'] !== $user['id']) $this->notify($p['authorId'], 'Someone replied to "' . $p['title'] . '".', 'thread.html?id=' . $p['id']);
        return $c;
    }

    public function setPostFlags(?array $user, string $postId, array $flags): array
    {
        $this->need($user, ['moderator', 'admin']);
        if (!$this->find('forum_posts', $postId)) throw new RuntimeException('Discussion not found.');
        $patch = [];
        foreach (['pinned', 'locked'] as $k) if (array_key_exists($k, $flags)) $patch[$k] = (bool)$flags[$k];
        if (isset($flags['status']) && in_array($flags['status'], ['visible', 'hidden'], true)) $patch['status'] = $flags['status'];
        return $this->update('forum_posts', $postId, $patch);
    }

    public function deleteOwn(?array $user, string $type, string $id): void
    {
        $this->need($user);
        $table = self::TARGET_TABLE[$type] ?? null;
        $row = $table ? $this->find($table, $id) : null;
        if (!$row) throw new RuntimeException('Not found.');
        if ($row['authorId'] !== $user['id'] && !in_array($user['role'], ['moderator', 'admin'], true)) throw new RuntimeException('You can only remove your own content.');
        $this->update($table, $id, ['status' => 'hidden']);
    }

    public function restoreContent(?array $user, string $type, string $id): void
    {
        $this->need($user, ['moderator', 'admin']);
        $table = self::TARGET_TABLE[$type] ?? null;
        if (!$table || !$this->find($table, $id)) throw new RuntimeException('Not found.');
        $this->update($table, $id, ['status' => $type === 'story' ? 'published' : 'visible']);
    }

    /* ================= ReportController ================= */

    public function reportContent(?array $user, string $targetType, string $targetId, string $reason, string $details = ''): array
    {
        $this->need($user);
        $table = self::TARGET_TABLE[$targetType] ?? null;
        if (!$table) throw new RuntimeException('Unknown content type.');
        if (!$this->find($table, $targetId)) throw new RuntimeException('That content no longer exists.');
        $reason = self::str($reason, 120);
        if ($reason === '') throw new ValidationError('Choose a reason for the report.', ['reason' => 'Choose a reason for the report.']);
        if ($this->count('reports', "`targetId` = ? AND `reporterId` = ? AND `status` = 'open'", [$targetId, $user['id']])) {
            throw new RuntimeException('You have already reported this. Our moderators are reviewing it.');
        }
        $r = $this->insert('reports', ['id' => self::uid('rpt'), 'targetType' => $targetType, 'targetId' => $targetId, 'reporterId' => $user['id'],
            'reason' => $reason, 'details' => self::str($details, 500), 'status' => 'open']);
        $this->notifyRoles(['moderator', 'admin'], 'New content report: ' . $reason, 'moderation.html');
        return $r;
    }

    public function resolveReport(?array $user, string $reportId, string $action): array
    {
        $this->need($user, ['moderator', 'admin']);
        $r = $this->find('reports', $reportId);
        if (!$r) throw new RuntimeException('Report not found.');
        if ($action === 'hide') {
            $table = self::TARGET_TABLE[$r['targetType']];
            if ($this->find($table, $r['targetId'])) $this->update($table, $r['targetId'], ['status' => 'hidden']);
        }
        return $this->update('reports', $reportId, ['status' => $action === 'hide' ? 'resolved' : 'dismissed', 'resolvedBy' => $user['id']]);
    }

    public function escalateReport(?array $user, string $reportId): void
    {
        $this->need($user, ['moderator', 'admin']);
        $r = $this->find('reports', $reportId);
        if (!$r) throw new RuntimeException('Report not found.');
        $row = $this->find(self::TARGET_TABLE[$r['targetType']], $r['targetId']);
        $this->notifyRoles(['counselor'], 'Safety concern escalated by moderation about a ' . $r['targetType'] . ' by ' . $this->displayName($row['authorId'] ?? null) . '. Please check in.', 'counselor.html');
    }

    /* ================= ConversationController + MessageController ================= */

    public function canMessage(?array $user, ?array $other): bool
    {
        if (!$user || !$other || $user['id'] === $other['id'] || !$other['active']) return false;
        if ($user['role'] === 'admin' || $other['role'] === 'admin' || $user['role'] === 'counselor') return true;
        if ($user['role'] === 'student') return $other['role'] === 'counselor';
        return $other['role'] !== 'student';
    }

    public function openConversation(?array $user, string $otherId): array
    {
        $this->need($user);
        $other = $this->find('users', $otherId);
        if (!$this->canMessage($user, $other)) throw new RuntimeException('You cannot message this person.');
        $existing = $this->rows('conversations', '(`userA` = ? AND `userB` = ?) OR (`userA` = ? AND `userB` = ?)', [$user['id'], $otherId, $otherId, $user['id']]);
        return $existing[0] ?? $this->insert('conversations', ['id' => self::uid('cnv'), 'userA' => $user['id'], 'userB' => $otherId]);
    }

    private function conversationFor(array $user, string $conversationId): array
    {
        $c = $this->find('conversations', $conversationId);
        if (!$c || !in_array($user['id'], [$c['userA'], $c['userB']], true)) throw new RuntimeException('Conversation not found.');
        return $c;
    }

    public function sendMessage(?array $user, string $conversationId, string $body): array
    {
        $this->need($user);
        $c = $this->conversationFor($user, $conversationId);
        $body = trim($body);
        if ($body === '') throw new ValidationError('Type a message first.');
        if (mb_strlen($body) > 2000) throw new ValidationError('Messages are limited to 2000 characters.');
        $m = $this->insert('messages', ['id' => self::uid('msg'), 'conversationId' => $conversationId, 'senderId' => $user['id'], 'body' => $body, 'readBy' => [$user['id']]]);
        $other = $c['userA'] === $user['id'] ? $c['userB'] : $c['userA'];
        $this->notify($other, 'New message from ' . $user['name'] . '.', 'messages.html?c=' . $c['id']);
        return $m;
    }

    public function markRead(?array $user, string $conversationId): void
    {
        $this->need($user);
        $this->conversationFor($user, $conversationId);
        foreach ($this->rows('messages', '`conversationId` = ?', [$conversationId]) as $m) {
            if (!in_array($user['id'], $m['readBy'], true)) $this->update('messages', $m['id'], ['readBy' => [...$m['readBy'], $user['id']]], false);
        }
    }

    public function markNotificationsRead(?array $user): void
    {
        $this->need($user);
        $this->db->prepare('UPDATE `notifications` SET `isRead` = 1 WHERE `userId` = ?')->execute([$user['id']]);
    }

    /* ================= AdminController ================= */

    public function setUserRole(?array $user, string $userId, string $role): array
    {
        $this->need($user, ['admin']);
        if (!in_array($role, self::ROLES, true)) throw new RuntimeException('Invalid role.');
        if ($userId === $user['id']) throw new RuntimeException('You cannot change your own role.');
        if (!$this->find('users', $userId)) throw new RuntimeException('User not found.');
        return $this->update('users', $userId, ['role' => $role]);
    }

    public function setUserActive(?array $user, string $userId, bool $active): array
    {
        $this->need($user, ['admin']);
        if ($userId === $user['id']) throw new RuntimeException('You cannot deactivate yourself.');
        if (!$this->find('users', $userId)) throw new RuntimeException('User not found.');
        return $this->update('users', $userId, ['active' => $active]);
    }

    public function createStaff(?array $user, array $in): array
    {
        $this->need($user, ['admin']);
        $errors = [];
        $name = self::str($in['name'] ?? '', 120);
        $email = strtolower(self::str($in['email'] ?? '', 190));
        $role = (string)($in['role'] ?? '');
        $password = (string)($in['password'] ?? '');
        if ($name === '') $errors['name'] = 'Name is required.';
        if (!self::isEmail($email)) $errors['email'] = 'Valid email required.';
        elseif ($this->count('users', '`email` = ?', [$email])) $errors['email'] = 'Email already in use.';
        if (!in_array($role, ['counselor', 'moderator', 'admin'], true)) $errors['role'] = 'Choose a staff role.';
        if (!preg_match(self::PASSWORD_RULE, $password)) $errors['password'] = 'At least 8 characters with letters and numbers.';
        if ($errors) throw new ValidationError('Please fix the highlighted fields.', $errors);
        $specialties = array_values(array_filter(array_map('trim', explode(',', (string)($in['specialties'] ?? '')))));
        return $this->insert('users', ['id' => self::uid('usr'), 'name' => $name, 'email' => $email, 'role' => $role, 'passwordHash' => $this->hash($password),
            'title' => self::str($in['title'] ?? '', 120), 'specialties' => $specialties, 'bio' => '', 'active' => true]);
    }

    public function settings(): array
    {
        $out = self::DEFAULT_SETTINGS;
        foreach ($this->rows('settings') as $r) $out[$r['id']] = $r['value'];
        return $out;
    }

    public function updateSettings(?array $user, array $in): array
    {
        $this->need($user, ['admin']);
        $errors = [];
        $clean = [];
        foreach (self::DEFAULT_SETTINGS as $k => $_) {
            if (!array_key_exists($k, $in)) continue;
            $clean[$k] = self::str($in[$k], $k === 'address' ? 300 : 150);
            if ($clean[$k] === '') $errors[$k] = 'This field is required.';
        }
        if (isset($clean['email']) && !self::isEmail($clean['email'])) $errors['email'] = 'Enter a valid email address.';
        if (isset($clean['phone']) && !preg_match('/^[+0-9 ()-]{7,20}$/', $clean['phone'])) $errors['phone'] = 'Enter a valid phone number.';
        if (isset($clean['emergency']) && !preg_match('/^[0-9 ,\/+-]{2,40}$/', $clean['emergency'])) $errors['emergency'] = 'Use numbers separated by commas, e.g. 112, 999.';
        if ($errors) throw new ValidationError('Please fix the highlighted fields.', $errors);
        foreach ($clean as $k => $v) {
            if ($this->find('settings', $k)) $this->update('settings', $k, ['value' => $v]);
            else $this->insert('settings', ['id' => $k, 'value' => $v]);
        }
        return $this->settings();
    }

    public function contact(array $in): array
    {
        $errors = [];
        $name = self::str($in['name'] ?? '', 120);
        $email = self::str($in['email'] ?? '', 190);
        $message = self::str($in['message'] ?? '', 2000);
        if (mb_strlen($name) < 2) $errors['name'] = 'Please enter your name.';
        if (!self::isEmail($email)) $errors['email'] = 'Please enter a valid email.';
        if (mb_strlen($message) < 10) $errors['message'] = 'Please write at least 10 characters.';
        if ($errors) throw new ValidationError('Please fix the highlighted fields.', $errors);
        $row = $this->insert('contact_messages', ['id' => self::uid('ctm'), 'name' => $name, 'email' => $email, 'message' => $message]);
        $this->notifyRoles(['admin'], 'Contact message from ' . $name . ' (' . $email . '): ' . mb_substr($message, 0, 140), 'admin.html#messages');
        return $row;
    }

    public function exportData(?array $user): array
    {
        $this->need($user, ['admin']);
        $out = ['exportedAt' => self::nowIso()];
        foreach (Database::TABLES as $table => $js) {
            $rows = $this->rows($table);
            if ($table === 'users') $rows = array_map(fn($u) => array_diff_key($u, ['passwordHash' => 1]), $rows);
            $out[$js] = $rows;
        }
        return $out;
    }

    public function resetData(?array $user): void
    {
        $this->need($user, ['admin']);
        if ($this->live) throw new RuntimeException('Resetting data is disabled on the live site.');
        Database::dropAll($this->db);
        Database::migrate($this->db);
        $this->seed();
    }

    /* ================= statistics + snapshot for the front end ================= */

    public function stats(): array
    {
        $today = self::addDays(0);
        $future = $this->count('slots', "`date` >= ? AND `status` <> 'cancelled'", [$today]);
        $booked = $this->count('slots', "`date` >= ? AND `status` = 'booked'", [$today]);
        return [
            'users'          => $this->count('users'),
            'students'       => $this->count('users', "`role` = 'student'"),
            'counselors'     => $this->count('users', "`role` = 'counselor' AND `active` = 1"),
            'bookings'       => $this->count('bookings'),
            'upcoming'       => $this->count('bookings', "`status` = 'confirmed'"),
            'completed'      => $this->count('bookings', "`status` = 'completed'"),
            'noShow'         => $this->count('bookings', "`status` = 'no-show'"),
            'cancelled'      => $this->count('bookings', "`status` = 'cancelled'"),
            'utilization'    => $future ? (int)round($booked / $future * 100) : 0,
            'intakes'        => $this->count('intakes'),
            'urgentOpen'     => $this->count('intakes', "`urgency` = 'urgent' AND `status` NOT IN ('closed', 'reviewed')"),
            'newIntakes'     => $this->count('intakes', "`status` = 'new'"),
            'stories'        => $this->count('stories', "`status` = 'published'"),
            'pendingStories' => $this->count('stories', "`status` = 'pending'"),
            'posts'          => $this->count('forum_posts', "`status` = 'visible'"),
            'comments'       => $this->count('forum_comments', "`status` = 'visible'"),
            'openReports'    => $this->count('reports', "`status` = 'open'"),
        ];
    }

    private static function publicUser(array $u): array
    {
        return array_intersect_key($u, array_flip(['id', 'name', 'role', 'title', 'photo', 'specialties', 'bio', 'active']));
    }

    /**
     * Everything the signed-in person (or a guest) is allowed to see, shaped for the front end.
     * Private data is filtered here on the server, never in the browser.
     */
    public function snapshot(?array $viewer): array
    {
        $vid = $viewer['id'] ?? '';
        $role = $viewer['role'] ?? 'guest';
        $staff = in_array($role, ['counselor', 'moderator', 'admin'], true);
        $mod = in_array($role, ['moderator', 'admin'], true);
        $mask = function (array $row) use ($vid, $mod) {
            // anonymous authors stay anonymous to everyone except themselves and moderators
            if (!empty($row['anonymous']) && $row['authorId'] !== $vid && !$mod) $row['authorId'] = null;
            return $row;
        };

        $users = array_map(function ($u) use ($vid, $staff) {
            unset($u['passwordHash']);
            return ($staff || $u['id'] === $vid) ? $u : self::publicUser($u);
        }, $this->rows('users', '1=1', [], '`name`'));

        $bookings = match ($role) {
            'admin' => $this->rows('bookings'),
            'counselor' => $this->rows('bookings', '`counselorId` = ?', [$vid]),
            'student' => $this->rows('bookings', '`studentId` = ?', [$vid]),
            default => [],
        };
        $intakes = match ($role) {
            'admin', 'counselor' => $this->rows('intakes'),
            'student' => $this->rows('intakes', '`studentId` = ?', [$vid]),
            default => [],
        };
        $stories = $mod ? $this->rows('stories') : $this->rows('stories', "`status` = 'published' OR `authorId` = ?", [$vid]);
        $posts = $mod ? $this->rows('forum_posts') : $this->rows('forum_posts', "`status` = 'visible' OR `authorId` = ?", [$vid]);
        $comments = $mod ? $this->rows('forum_comments') : $this->rows('forum_comments', "`status` = 'visible'");
        // counts are public, but who reacted is only visible to the person themselves
        $reactions = array_map(fn($r) => $r['userId'] === $vid ? $r : array_merge($r, ['userId' => null]), $this->rows('reactions'));

        $conversations = $vid ? $this->rows('conversations', '`userA` = ? OR `userB` = ?', [$vid, $vid]) : [];
        $messages = [];
        foreach ($conversations as &$c) {
            $c['participants'] = [$c['userA'], $c['userB']];
            array_push($messages, ...$this->rows('messages', '`conversationId` = ?', [$c['id']], '`createdAt`'));
        }
        unset($c);
        $notifications = $vid ? array_map(function ($n) {
            $n['read'] = $n['isRead'];
            return $n;
        }, array_slice($this->rows('notifications', '`userId` = ?', [$vid], '`createdAt` DESC'), 0, 50)) : [];

        $me = null;
        foreach ($users as $u) if ($u['id'] === $vid) $me = $u;

        return [
            'me' => $me,
            'app' => ['live' => $this->live],
            'stats' => $this->stats(),
            'settings' => $this->settings(),
            'data' => [
                'users' => $users,
                'slots' => $this->rows('slots', '`date` >= ?', [self::addDays(-60)]),
                'bookings' => $bookings,
                'intakes' => $intakes,
                'stories' => array_map($mask, $stories),
                'forumCategories' => $this->rows('forum_categories', '1=1', [], '`createdAt`, `id`'),
                'forumPosts' => array_map($mask, $posts),
                'forumComments' => array_map($mask, $comments),
                'reactions' => $reactions,
                'reports' => $mod ? $this->rows('reports') : [],
                'conversations' => $conversations,
                'messages' => $messages,
                'notifications' => $notifications,
                'contactMessages' => $role === 'admin' ? $this->rows('contact_messages', '1=1', [], '`createdAt` DESC') : [],
            ],
        ];
    }

    /* ================= sample data ================= */

    public function seedIfEmpty(): void
    {
        if ($this->count('users') === 0) $this->seed();
    }

    private static function daysAgoIso(int $days, int $hour = 10): string
    {
        return self::nowIso((int)strtotime(self::addDays(-$days) . sprintf(' %02d:%02d:00', $hour, random_int(0, 59))));
    }

    private function generateSlots(array $counselors, int $fromDay, int $days): void
    {
        $times = ['09:00', '10:30', '14:00', '15:30'];
        for ($d = $fromDay; $d < $fromDay + $days; $d++) {
            $date = self::addDays($d);
            $dow = (int)date('w', strtotime($date));
            if ($dow === 0 || $dow === 6) continue;
            foreach (array_values($counselors) as $ci => $c) {
                foreach ($times as $ti => $t) {
                    if (($d + $ci + $ti) % 5 === 0) continue; // leave natural gaps in each schedule
                    $this->insert('slots', ['id' => self::uid('slt'), 'counselorId' => $c['id'], 'date' => $date, 'start' => $t, 'end' => self::addMinutes($t, 60),
                        'mode' => ($ti + $ci) % 3 === 0 ? 'Online' : 'In-person', 'status' => 'open']);
                }
            }
        }
    }

    /** Make sure each active counselor always has some future availability for the demo. */
    public function topUpSlots(): void
    {
        $today = self::addDays(0);
        $needing = array_filter($this->rows('users', "`role` = 'counselor' AND `active` = 1"),
            fn($c) => !$this->count('slots', "`counselorId` = ? AND `status` = 'open' AND `date` > ?", [$c['id'], $today]));
        if ($needing) $this->generateSlots($needing, 1, 14);
    }

    public function seed(): void
    {
        $this->db->beginTransaction();
        try {
            $this->seedRows();
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function seedRows(): void
    {
        $photo = fn(string $id) => "https://images.unsplash.com/photo-$id?w=200&h=200&fit=crop&crop=faces";
        $people = [
            ['usr_admin', 'Grace Atuhaire', 'admin@campusmind.ac.ug', 'Admin@123', 'admin', ['title' => 'System Administrator']],
            ['usr_mod', 'Brian Tumusiime', 'moderator@campusmind.ac.ug', 'Moderator@123', 'moderator', ['title' => 'Community Moderator']],
            ['usr_c1', 'Dr. Sarah Namara', 'sarah.namara@campusmind.ac.ug', 'Counselor@123', 'counselor', ['title' => 'Senior Counseling Psychologist', 'photo' => $photo('1573497019940-1c28c88b4f3e'),
                'specialties' => ['Anxiety & Stress', 'Academic Pressure', 'Depression'], 'bio' => 'Ten years supporting university students through exam stress, low mood and life transitions.']],
            ['usr_c2', 'Mr. Daniel Okello', 'daniel.okello@campusmind.ac.ug', 'Counselor@123', 'counselor', ['title' => 'Student Wellbeing Counselor', 'photo' => $photo('1507003211169-0a1dd7228f2d'),
                'specialties' => ['Relationships', 'Grief & Loss', 'Substance Use'], 'bio' => 'Focused on relationships, family pressure and helping students rebuild healthy routines.']],
            ['usr_c3', 'Ms. Ruth Kansiime', 'ruth.kansiime@campusmind.ac.ug', 'Counselor@123', 'counselor', ['title' => 'Peer Support Coordinator', 'photo' => $photo('1580894732444-8ecded7900cd'),
                'specialties' => ['Adjustment to Campus', 'Financial Stress', 'Self-esteem'], 'bio' => 'Helps first-year and international students settle in and find their footing.']],
            ['usr_s1', 'Ivan Kato', 'student@must.ac.ug', 'Student@123', 'student', ['studentNo' => '2024/BSE/102/PS', 'faculty' => 'Computing & Informatics']],
            ['usr_s2', 'Amina Nakato', 'amina@must.ac.ug', 'Student@123', 'student', ['studentNo' => '2023/MED/044/PS', 'faculty' => 'Medicine']],
            ['usr_s3', 'Joel Mugisha', 'joel@must.ac.ug', 'Student@123', 'student', ['studentNo' => '2025/BBA/210/PS', 'faculty' => 'Business & Management Sciences']],
        ];
        foreach ($people as [$id, $name, $email, $pw, $role, $extra]) {
            $pw = $this->demoPasswords[$role] ?? $pw;
            $this->insert('users', array_merge(['id' => $id, 'name' => $name, 'email' => $email, 'role' => $role, 'passwordHash' => $this->hash($pw),
                'active' => true, 'specialties' => [], 'createdAt' => self::daysAgoIso(60)], $extra));
        }

        $counselors = $this->rows('users', "`role` = 'counselor'", [], '`id`');
        $students = $this->rows('users', "`role` = 'student'", [], '`id`');
        $reasons = ['Exam stress and trouble sleeping', 'Feeling homesick and isolated', 'Struggling with motivation', 'Family conflict', 'Managing anxiety before presentations'];

        // past sessions for history and analytics
        $i = 0;
        for ($d = 13; $d >= 1; $d--) {
            $date = self::addDays(-$d);
            $dow = (int)date('w', strtotime($date));
            if ($dow === 0 || $dow === 6) continue;
            foreach ($counselors as $ci => $c) {
                if (($d + $ci) % 2 !== 0) continue;
                $start = $ci % 2 ? '10:30' : '14:00';
                $slot = $this->insert('slots', ['id' => self::uid('slt'), 'counselorId' => $c['id'], 'date' => $date, 'start' => $start, 'end' => self::addMinutes($start, 60),
                    'mode' => 'In-person', 'status' => 'booked', 'createdAt' => self::daysAgoIso($d + 3)]);
                $this->insert('bookings', ['id' => self::uid('bkg'), 'slotId' => $slot['id'], 'studentId' => $students[$i % count($students)]['id'], 'counselorId' => $c['id'],
                    'reason' => $reasons[$i % count($reasons)], 'status' => $i % 7 === 3 ? 'no-show' : 'completed', 'notes' => '', 'createdAt' => self::daysAgoIso($d + 2)]);
                $i++;
            }
        }

        $this->generateSlots($counselors, 1, 21);
        $open = $this->rows('slots', "`status` = 'open'", [], '`date`, `start`, `counselorId`');
        foreach ([0, 5, 9] as $k => $idx) {
            $s = $open[$idx * 3 + $k] ?? null;
            if (!$s) continue;
            $this->update('slots', $s['id'], ['status' => 'booked']);
            $this->insert('bookings', ['id' => self::uid('bkg'), 'slotId' => $s['id'], 'studentId' => $students[$k]['id'], 'counselorId' => $s['counselorId'],
                'reason' => $reasons[($k + 2) % count($reasons)], 'status' => 'confirmed', 'notes' => '', 'createdAt' => self::daysAgoIso(1)]);
        }

        $intakes = [
            ['usr_s2', ['Academic stress', 'Sleep problems'], 'Clinical rotations have been overwhelming and I barely sleep before exams.', 2, '1-3 months', 'soon', 'In-person', 'usr_c1', 'new', null, 1],
            ['usr_s3', ['Financial stress', 'Adjustment to campus'], 'First semester and struggling to manage money and fit in.', 3, 'Less than a month', 'routine', 'Online', '', 'assigned', 'usr_c3', 4],
            ['usr_s1', ['Anxiety', 'Academic stress'], 'Presentations make me panic. I want strategies before final year project defence.', 3, '3-6 months', 'routine', 'In-person', '', 'reviewed', 'usr_c1', 9],
        ];
        foreach ($intakes as [$sid, $concerns, $desc, $mood, $dur, $urg, $pref, $pc, $status, $assigned, $ago]) {
            $this->insert('intakes', ['id' => self::uid('ink'), 'studentId' => $sid, 'concerns' => $concerns, 'description' => $desc, 'mood' => $mood, 'duration' => $dur,
                'urgency' => $urg, 'safetyRisk' => false, 'contactPref' => $pref, 'preferredCounselorId' => $pc, 'status' => $status, 'assignedTo' => $assigned, 'createdAt' => self::daysAgoIso($ago)]);
        }

        $stories = [
            ['sty_1', 'usr_s2', false, 'The semester I stopped pretending I was fine', 'During my third year I was carrying a full load of rotations and pretending everything was okay. I was sleeping three hours a night and snapping at my friends. Booking one session felt like admitting defeat, but it was the opposite. My counselor helped me build a realistic study plan and, more importantly, gave me permission to rest. If you are on the fence, book the session. You do not have to be in crisis to deserve support.', ['Academic stress', 'Recovery'], 'published', 12],
            ['sty_2', 'usr_s3', true, 'Homesick in my first month', 'I moved from Arua and did not know anyone. Weekends were the hardest. Reading stories here made me realise lots of first-years feel the same. I joined a study group from the forum and now Sunday evenings are something I look forward to.', ['Adjustment', 'Loneliness'], 'published', 8],
            ['sty_3', 'usr_s1', false, 'What helped my presentation anxiety', 'Breathing exercises sounded silly to me until I tried box breathing right before my mini-project demo. Four seconds in, hold, out, hold. My counselor also had me practise in front of one friend, then three, then the whole group. Small steps really work.', ['Anxiety', 'Coping tips'], 'published', 5],
            ['sty_4', 'usr_s2', true, 'Grief during exams', 'I lost my grandmother two weeks before finals. The counseling centre helped me apply for a deferral and gave me space to grieve. I am sharing so others know these options exist.', ['Grief', 'Academic stress'], 'pending', 0],
        ];
        foreach ($stories as [$id, $author, $anon, $title, $body, $tags, $status, $ago]) {
            $this->insert('stories', ['id' => $id, 'authorId' => $author, 'anonymous' => $anon, 'title' => $title, 'body' => $body, 'tags' => $tags, 'status' => $status, 'createdAt' => self::daysAgoIso($ago)]);
        }

        $cats = [
            ['cat_1', 'Academic Stress', 'Exams, deadlines, research and study habits.', '#2f7a5c'],
            ['cat_2', 'Campus Life', 'Settling in, hostels, clubs and making friends.', '#b7791f'],
            ['cat_3', 'Mental Wellness', 'Mood, sleep, anxiety and self-care practices.', '#5a67d8'],
            ['cat_4', 'Relationships', 'Family, friendships and romantic relationships.', '#c05680'],
            ['cat_5', 'Announcements', 'Official updates from the CampusMind team.', '#163d2e'],
        ];
        foreach ($cats as $n => [$id, $name, $desc, $color]) {
            $this->insert('forum_categories', ['id' => $id, 'name' => $name, 'description' => $desc, 'color' => $color, 'createdAt' => self::daysAgoIso(90 - $n)]);
        }

        $posts = [
            ['pst_1', 'cat_5', 'usr_admin', false, 'Welcome to the CampusMind community forum', "This is a safe, moderated space for MUST students. Be kind, protect each other's privacy, and use the Report button if anything feels unsafe. If you are in immediate danger please call 112 or visit the counseling centre.", true, true, 30],
            ['pst_2', 'cat_1', 'usr_s1', false, 'How do you plan for back-to-back deadlines?', 'I have three assignments and a test in the same week. What planning methods have worked for you?', false, false, 3],
            ['pst_3', 'cat_2', 'usr_s3', true, 'Any first-years want to form a weekend study group?', 'Thinking Saturdays at the library, 10am. All faculties welcome.', false, false, 6],
            ['pst_4', 'cat_3', 'usr_s2', false, 'Sleep routine tips that actually work?', 'I keep scrolling my phone until 2am. Has anyone fixed this?', false, false, 2],
        ];
        foreach ($posts as [$id, $cat, $author, $anon, $title, $body, $pinned, $locked, $ago]) {
            $this->insert('forum_posts', ['id' => $id, 'categoryId' => $cat, 'authorId' => $author, 'anonymous' => $anon, 'title' => $title, 'body' => $body,
                'pinned' => $pinned, 'locked' => $locked, 'status' => 'visible', 'createdAt' => self::daysAgoIso($ago)]);
        }
        $comments = [
            ['pst_2', 'usr_s2', false, 'Time-blocking on paper helps me. I give each task a colour and block 90-minute chunks.', 2],
            ['pst_2', 'usr_c1', false, 'Great question. Start with the item due first, and protect one evening for rest. Burnout costs more time than it saves.', 2],
            ['pst_3', 'usr_s1', false, 'Count me in!', 5],
            ['pst_4', 'usr_s3', true, 'Charging my phone outside the room was a game changer.', 1],
        ];
        $lastComment = null;
        foreach ($comments as [$post, $author, $anon, $body, $ago]) {
            $lastComment = $this->insert('forum_comments', ['id' => self::uid('cmt'), 'postId' => $post, 'authorId' => $author, 'anonymous' => $anon, 'body' => $body,
                'status' => 'visible', 'createdAt' => self::daysAgoIso($ago)]);
        }

        foreach ([['story', 'sty_1', 'usr_s1', 'support', 10], ['story', 'sty_1', 'usr_s3', 'relate', 9], ['story', 'sty_2', 'usr_s1', 'relate', 7],
                     ['story', 'sty_3', 'usr_s2', 'thanks', 4], ['post', 'pst_2', 'usr_s2', 'support', 2]] as [$tt, $tid, $uid, $type, $ago]) {
            $this->insert('reactions', ['id' => self::uid('rct'), 'targetType' => $tt, 'targetId' => $tid, 'userId' => $uid, 'type' => $type, 'createdAt' => self::daysAgoIso($ago)]);
        }
        $this->insert('reports', ['id' => self::uid('rpt'), 'targetType' => 'comment', 'targetId' => $lastComment['id'], 'reporterId' => 'usr_s2',
            'reason' => 'Spam or off-topic', 'details' => 'Testing the report flow.', 'status' => 'open', 'createdAt' => self::daysAgoIso(0)]);

        $this->insert('conversations', ['id' => 'cnv_1', 'userA' => 'usr_s1', 'userB' => 'usr_c1', 'createdAt' => self::daysAgoIso(8)]);
        $this->insert('messages', ['id' => self::uid('msg'), 'conversationId' => 'cnv_1', 'senderId' => 'usr_s1', 'body' => 'Hello Dr. Namara, thank you for the session. Could you share the breathing exercise again?',
            'readBy' => ['usr_s1', 'usr_c1'], 'createdAt' => self::daysAgoIso(8, 15)]);
        $this->insert('messages', ['id' => self::uid('msg'), 'conversationId' => 'cnv_1', 'senderId' => 'usr_c1', 'body' => 'Of course. Box breathing: in for 4, hold 4, out 4, hold 4. Repeat four times. See you next week!',
            'readBy' => ['usr_c1'], 'createdAt' => self::daysAgoIso(7, 9)]);
    }
}
