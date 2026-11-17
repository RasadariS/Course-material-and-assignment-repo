# Course-material-and-assignment-repo
MIT4301 Independent Study_Topic Number 18. Course Material and Assignment Repository_Tasks are  Upload/organize course materials by subject and week, track submission deadlines, and log downloads/views.

## Instructor profile (PHP)

Run the project through XAMPP/Apache and open `admin/instructorprofile.php`. The page uses PDO with MySQL and expects the `instructors` table shown in the project materials to exist in `course_repository_db`. By default, the connection uses `localhost`, MySQL user `root`, and an empty password; set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` environment variables to override those settings.

The page uses the signed-in account ID from `$_SESSION['user_id']` as `instructors.user_id`. The PHP login flow sets that value after authenticating the user. Employee code, department, and the optional image are stored in the instructor table; user identity fields are not columns in that table. Uploaded photos are limited to JPEG, PNG, GIF, or WebP files up to 2 MB.

## Login (PHP)

Open `login.php` through XAMPP/Apache. It accepts an email address or NIC from the `users` table, verifies `password_hash` with PHP's `password_verify()`, and sets `user_id`, `full_name`, and `role` in the PHP session. Existing account passwords must have been stored with `password_hash()`; plain-text passwords are not accepted.
