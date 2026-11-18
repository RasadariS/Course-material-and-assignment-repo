# Course-material-and-assignment-repo
MIT4301 Independent Study_Topic Number 18. Course Material and Assignment Repository_Tasks are  Upload/organize course materials by subject and week, track submission deadlines, and log downloads/views.

## Login (PHP)

Open `login.php` through XAMPP/Apache. It accepts an email address or NIC from the `users` table, verifies `password_hash` with PHP's `password_verify()`, and sets `user_id`, `full_name`, and `role` in the PHP session. Existing account passwords must have been stored with `password_hash()`; plain-text passwords are not accepted.

The login page links to `admin/signup.php`. Database connection settings default to `localhost`, database `course_repository_db`, MySQL user `root`, and an empty password; set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` environment variables to override them.

The signup page creates a user and its matching student or instructor row in one transaction. Only signed-in administrators can select Administrator; that account and its `system_administrators` row are created transactionally. Public administrator registration is blocked.

Existing databases missing the `system_administrators` table can apply `database/create_system_administrators_table.sql` in phpMyAdmin or the MySQL command line. To preserve rows from an older `admins` table, apply `database/migrate_admins_to_system_administrators.sql` after creating the new table.

Signed-in instructors can open `admin/instructorprofile.html` to view and edit their own profile. Updates are validated and saved to the `users` and `instructors` tables; profile photos are optional.
