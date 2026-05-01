# Database Setup

## Fresh Installation

Run these steps in order:

1. **Create the database**
   ```sql
   CREATE DATABASE fairmedalloc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. **Import the schema** (tables only, no data)
   ```bash
   mysql -u root -h 127.0.0.1 --port=3307 fairmedalloc < sql/schema.sql
   ```

3. **Seed initial data** (hostels, rooms, faculties, departments, settings)
   ```bash
   php sql/seed.php
   ```

4. **Apply all migrations** (indexes, policy settings, block corrections)
   ```bash
   php sql/run_migrations.php
   ```
   Or apply them individually in filename order — see [Migrations](#migrations) below.

5. **Create the first admin account**
   Visit `http://localhost/FairMedAlloc/admin_signup.php` from the local machine.

---

## Existing Database (Upgrade)

If you already have a running `fairmedalloc` database, only apply migration files
that have not been run yet. Use the migration runner to check:

```bash
php sql/run_migrations.php
```

The runner tracks applied files in a `schema_migrations` table and skips
anything already applied. It is safe to run repeatedly.

---

## Migrations

All migration files are prefixed with `YYYYMMDD_` so they sort chronologically.
Run them **in filename order**. The runner handles this automatically.

| File | Purpose |
|------|---------|
| `schema.sql` | Full table definitions (DROP + CREATE) |
| `seed.php` | Generates hostel, room, faculty, and settings data |
| `20260425_add_algorithm_version.sql` | Adds `algorithm_version` column to `allocations` |
| `20260430_accessible_ground_floor_policy.sql` | Floor metadata for Joshua/Deborah Hall; OR-Tools solver setting |
| `20260501_hostel_restructure.sql` | QE Extension Hall renumbering (→ blocks 38–42); QE Hall blocks 33–37 (28-room, 116-bed); ghost room cleanup for QE Hall Block 1 and PM Hall Block 1 |
| `20260501_add_indexes.sql` | Performance indexes on `hostels`, `rooms`, `notifications`, and audit log tables |

> **Note:** `schema.sql` + `seed.php` produce a fully correct database from scratch,
> including all the block layouts above. The migration files are only needed
> when upgrading an existing database that was set up before these changes.

---

## Why schema + seed are split

- `schema.sql` keeps table structure readable and git-diffable.
- `seed.php` generates the repetitive hostel/room patterns programmatically
  (thousands of rooms) without duplicating SQL rows.
- Migration files handle incremental changes to a live database without data loss.

---

## Applying a single migration (XAMPP)

```powershell
cmd /c "C:\xampp\mysql\bin\mysql.exe -u root -h 127.0.0.1 --port=3307 fairmedalloc < sql\<filename>.sql"
```

Replace `<filename>` with the target migration file.
