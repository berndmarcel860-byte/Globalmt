# Globalmt

## PHP + MySQL setup

1. Run `database/schema.sql` on your MySQL server.
2. Set DB credentials with environment variables:
   - `GMT_DB_HOST`
   - `GMT_DB_PORT`
   - `GMT_DB_NAME`
   - `GMT_DB_USER`
   - `GMT_DB_PASS`
3. Create an admin account using a strong hashed password:
   - Generate hash: `php -r "echo password_hash('your-strong-password', PASSWORD_DEFAULT), PHP_EOL;"`
   - Insert into `admin_users`.

Entry points:
- Public tracker: `index.php`
- Admin dashboard: `admin/admin.php`
