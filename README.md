# CampusMind: Integrated University Counseling & Peer Support Platform

Website for the CampusMind project (BSc Software Engineering, Mbarara University of Science and Technology).
It has a PHP back end and a real database (SQLite by default, or MySQL/MariaDB). The front end uses HTML, CSS and JavaScript.

## How to run it

1. PHP 8.3 is already installed on this computer (if needed: `winget install PHP.PHP.8.3`).
2. Double-click **`start-server.bat`**. The site opens at **http://localhost:8000**.
   Keep the black window open while you use the site.

The first request creates the database and fills it with demo data automatically.

> Opening the `.html` files directly (double-clicking them) will not work any more. The pages need the PHP server to reach the database.

## Putting it online

See **`deploy/HOSTING-GUIDE.md`**, and upload **`deploy/CampusMind-upload.zip`**.
The code switches itself into **live mode** on any address other than `localhost`:

- uses MySQL
- no demo-login buttons
- strong passwords (stored in `api/config.php`)
- reset data and tests switched off
- HTTPS-only login cookie
- no database details in error messages

## Using MySQL instead of SQLite

SQLite works with no setup and stores everything in `storage/campusmind.sqlite`.
To use your MySQL server (for example MySQL 9.2 or Laragon), open **`api/config.php`** and change:

```php
'driver' => 'mysql',
...
'username' => 'root',
'password' => 'your MySQL password',
```

Restart `start-server.bat`. The `campusmind` database and all tables are created automatically.
`database/schema.sql` contains the full table design, which you can open or import in MySQL Workbench.

## Demo accounts

| Role          | Email                            | Password       |
|---------------|----------------------------------|----------------|
| Student       | student@must.ac.ug               | Student@123    |
| Student       | amina@must.ac.ug                 | Student@123    |
| Counselor     | sarah.namara@campusmind.ac.ug    | Counselor@123  |
| Counselor     | daniel.okello@campusmind.ac.ug   | Counselor@123  |
| Moderator     | moderator@campusmind.ac.ug       | Moderator@123  |
| Administrator | admin@campusmind.ac.ug           | Admin@123      |

All people in the demo data, including the team on the About page, are fictional.

## Project structure

```
index.html … admin.html   pages (front end)
assets/css/style.css      design
assets/js/app.js          shared UI: header/footer, forms, modals, role checks
assets/js/store.js        talks to the API and keeps the data the user may see
api/index.php             JSON API (router, sessions, CSRF check, login rate limit)
api/lib/CampusMind.php    business logic: one section per controller in the report
api/lib/Database.php      database connection, table definitions (migrations)
api/config.php            database settings
api/tests.php             automated test suite
database/schema.sql       MySQL version of the schema
storage/                  SQLite database file (blocked from the web)
server.php                router for PHP's built-in server (blocks private files)
start-server.bat          starts the site
```

## How the report's requirements map to the site

**Functional scope**
1. **Login and registration:** `login.html`, `register.html` → `CampusMind::login()` / `register()`
2. **Role-based access** for students, counselors, moderators and administrators: every action checks the role on the server (`need()`). Pages show "Access restricted" to other roles.
3. **Counselor availability and slot management:** `counselor.html` → `addSlot()` / `addSlots()` / `removeSlot()`, with overlap checks
4. **Booking counseling sessions:** `book.html` → `bookSlot()`. A database transaction makes double-booking impossible. It also blocks time clashes and allows at most 3 upcoming sessions.
5. **Intake form:** `intake.html` → `submitIntake()`. Safety-risk answers are escalated to urgent.
6. **Stories:** `stories.html` → `submitStory()` / `moderateStory()`. Stories are moderated before publishing.
7. **Forum:** `forum.html`, `thread.html` → `createPost()` / `addComment()` / `setPostFlags()`
8. **Admin dashboard:** `admin.html`. KPIs, charts, users and roles, staff accounts, sessions, schedules, forum categories, **contact details**, contact-form inbox, export and reset.

**Non-functional scope**
- **Secure authentication:** bcrypt password hashes, secure HTTP-only session cookies, session expiry after 8 hours, login rate limiting, CSRF header check, safe redirects, and HTML escaping of all user content.
- **Privacy:** the server only sends each person the data they are allowed to see. Students get only their own bookings and intakes, other people's emails are hidden, and anonymous authors stay anonymous.
- **Structured data storage:** 15 related tables with foreign keys (`database/schema.sql`).
- **Error handling:** field-level validation messages, plus clear messages for business-rule and server errors.
- **Responsive design and maintainability:** the layout works on phones, and the API, business logic, database and UI are kept in separate files.

## Contact details

The phone number, email, address, opening hours and emergency numbers are **edited by the administrator**:
log in as admin → **Admin Dashboard → Contact details**. Changes appear in the top bar, footer and crisis banner on every page.
The demo values (+256 700 000 000, counseling@campusmind.ac.ug) are placeholders. Replace them with the real ones.

## Tests

Open **System Tests** (link in the footer), or run in a terminal:

```bash
php api/tests.php
```

28 checks cover everything in report §10 (page loads, login redirect, booking an available slot, booking saved in the database), plus security, privacy and every module.
`php api/tests.php --mysql` runs the same tests on MySQL, using a separate `campusmind_test` database.

## Resetting

**Admin → System → Reset demo data** rebuilds the database with the original sample data.
