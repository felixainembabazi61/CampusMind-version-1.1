# Putting CampusMind online with InfinityFree

`CampusMind-upload.zip` (in this folder) contains everything the live site needs.
The same code runs in **live mode** automatically on any address that isn't `localhost`:

- uses MySQL
- no demo-login buttons
- strong passwords
- "Reset data" and the test page are switched off
- HTTPS-only login cookie
- no database details in error messages

## 1. Create the account and website (about 5 minutes)
1. Go to **infinityfree.com** and sign up (free).
2. Click **Create Account** and choose a free subdomain, e.g. `campusmind.infinityfreeapp.com`.
3. Open the account's **Control Panel**.

## 2. Create the MySQL database
1. In the Control Panel open **MySQL Databases**.
2. Create a database named `campusmind`.
3. Note the four values shown on that page:
   - **MySQL Hostname**, e.g. `sql123.infinityfree.com`
   - **MySQL DB Name**, e.g. `if0_12345678_campusmind`
   - **MySQL Username**, e.g. `if0_12345678`
   - **MySQL Password**: the same as your hosting account's password ("Show" in the account page)

## 3. Upload the files
1. Open **File Manager** (Control Panel → Online File Manager) and go into the **`htdocs`** folder.
2. Delete the default `index2.html` (or similar) welcome file if there is one.
3. Upload `CampusMind-upload.zip` into `htdocs`, then use **Extract** in the file manager.
   - If extracting isn't offered, unzip it on your computer first and upload the folders and files instead.
   - **FileZilla** (FTP details are in the Control Panel) is the most reliable way to upload many files.
4. Check that `htdocs` now directly contains `index.html`, `api/`, `assets/` and `.htaccess`
   (not an extra `CampusMind-upload` folder around them).

## 4. Connect it to the database
1. In the file manager open **`htdocs/api/config.php`** with the built-in editor.
2. In the second half (the live section), replace these four values:
   - `PASTE_MYSQL_HOSTNAME`
   - `PASTE_DATABASE_NAME`
   - `PASTE_MYSQL_USERNAME`
   - `PASTE_MYSQL_PASSWORD`
3. Save.

## 5. Turn on HTTPS
Control Panel → **Free SSL Certificates** → request one for your domain and install it. This can take a little while.
Use the `https://` address afterwards. Login is designed for HTTPS.

## 6. First visit
Open your site. The first page load creates all 15 tables and the demo data, so it may take a few seconds.

**Logging in on the live site:** the emails are the same as locally
(admin@campusmind.ac.ug, sarah.namara@campusmind.ac.ug, moderator@campusmind.ac.ug, student@must.ac.ug).
The **passwords are different**. They are in `api/config.php` under `demo_passwords` in your local copy.
Keep that file private.

Then, as admin, go to **Admin Dashboard → Contact details** and enter the real phone number, email and address.

## If something goes wrong
- **"The service is temporarily unavailable"**: the four MySQL values in `config.php` are wrong. Check them against the MySQL Databases page.
- **Pages load but nothing appears / login does nothing**: the `api` folder or `.htaccess` is missing, or the files are inside an extra folder.
  Check step 3.4.
- **Login works over http but not https (or the other way round)**: finish step 5 and always use the `https://` address.

