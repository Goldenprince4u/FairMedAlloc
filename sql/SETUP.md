# Database Setup

The new setup flow is split into schema and generated seed data.

1. Import [schema.sql](/C:/xampp/htdocs/FairMedAlloc/sql/schema.sql) into MySQL or phpMyAdmin.
2. Run `php sql/seed.php` from the project root.
3. Create the first admin account through [create_admin.php](/C:/xampp/htdocs/FairMedAlloc/create_admin.php).

If you already have an existing database instead of a fresh setup:

1. Apply [20260425_add_algorithm_version.sql](/C:/xampp/htdocs/FairMedAlloc/sql/20260425_add_algorithm_version.sql).
2. Then continue using the app normally. New allocations will start recording `algorithm_version`.

Why this split exists:

- `schema.sql` keeps table structure readable.
- `seed.php` generates repeated hostel and room patterns without thousands of duplicated SQL rows.
- The seeder preserves your current hostel layouts, capacities, corner-room rules, postgrad blocks, foundation block, and proximal hostels.

Important schema updates included here:

- `student_profiles.is_paid` exists, because the PHP app already reads and writes that column.
- `allocations.algorithm_version` now exists, so each new allocation can record which engine or manual override path created it.
