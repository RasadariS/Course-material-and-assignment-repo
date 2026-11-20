# Course-material-and-assignment-repo
MIT4301 Independent Study_Topic Number 18. Course Material and Assignment Repository_Tasks are  Upload/organize course materials by subject and week, track submission deadlines, and log downloads/views.

## Login (PHP)

Open `login.php` through XAMPP/Apache. Students sign in with their registration number from the `students` table. Instructors and administrators sign in with their NIC from the `users` table. The login page verifies `password_hash` with PHP's `password_verify()` and sets `user_id`, `full_name`, and `role` in the PHP session. Existing account passwords must have been stored with `password_hash()`; plain-text passwords are not accepted.

The login page links to `admin/signup.php`. Database connection settings default to `localhost`, database `course_repository_db`, MySQL user `root`, and an empty password; set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` environment variables to override them.

Password reset uses one-time links. Run `database/create_password_reset_tokens_table.sql` in the project database, set `APP_BASE_URL` to the full base URL of the application, and set `PASSWORD_RESET_FROM_EMAIL` to the sender address. PHP email delivery must also be configured on the server. Students request a link with their registration number; instructors and administrators use their email or NIC.

The signup page creates a user and its matching student or instructor row in one transaction. Only signed-in administrators can select Administrator; that account and its `system_administrators` row are created transactionally. Public administrator registration is blocked.

Existing databases missing the `system_administrators` table can apply `database/create_system_administrators_table.sql` in phpMyAdmin or the MySQL command line. To preserve rows from an older `admins` table, apply `database/migrate_admins_to_system_administrators.sql` after creating the new table.

Existing databases must apply `database/add_user_status.sql` once to add an active/inactive account status. New users default to active. Deleting an administrator account from the profile page marks it inactive and prevents future sign-in.

Signed-in students can open `admin/studentprofile.php` to edit their own profile or choose View to open `admin/studentprofileview.php`. Both pages load the student's details from the `users` and `students` tables, including registration number, intake year, semester, and optional profile photo.

Signed-in instructors can open `admin/instructorprofile.php` to view and edit their own profile. The profile page uses the `users` and `instructors` tables; profile photos are optional.
