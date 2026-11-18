# Course-material-and-assignment-repo
MIT4301 Independent Study_Topic Number 18. Course Material and Assignment Repository_Tasks are  Upload/organize course materials by subject and week, track submission deadlines, and log downloads/views.

## Login (PHP)

Open `login.php` through XAMPP/Apache. It accepts an email address or NIC from the `users` table, verifies `password_hash` with PHP's `password_verify()`, and sets `user_id`, `full_name`, and `role` in the PHP session. Existing account passwords must have been stored with `password_hash()`; plain-text passwords are not accepted.

The login page links to `admin/signup.php`. Database connection settings default to `localhost`, database `course_repository_db`, MySQL user `root`, and an empty password; set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` environment variables to override them.

The signup page creates a user and the matching student or instructor row in one transaction. It resolves `role_id` from the `roles` table and does not allow public administrator registration.

Signed-in instructors can open `admin/instructorprofile.html` to view and edit their own profile. Updates are validated and saved to the `users` and `instructors` tables; profile photos are optional.
