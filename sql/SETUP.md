# Database Setup

The new setup flow is split into schema and generated seed data.

1. Import [schema.sql](/C:/xampp/htdocs/FairMedAlloc/sql/schema.sql) into MySQL or phpMyAdmin.
2. Run `php sql/seed.php` from the project root.
3. Create the first admin account through [create_admin.php](/C:/xampp/htdocs/FairMedAlloc/create_admin.php).

Why this split exists:

- `schema.sql` keeps table structure readable.
- `seed.php` generates repeated hostel and room patterns without thousands of duplicated SQL rows.
- The seeder preserves your current hostel layouts, capacities, corner-room rules, postgrad blocks, foundation block, and proximal hostels.

One important fix is included in the new schema: `student_profiles.is_paid` now exists, because the PHP app already reads and writes that column.
