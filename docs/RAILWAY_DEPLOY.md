# FairMedAlloc on Railway

This guide is specific to the current FairMedAlloc codebase.

## Recommended Railway shape

Use this first:

- `1 Railway web service` for the app container
- `1 Railway MySQL service` for the database
- `1 volume` mounted to persist profile pictures

Do not split the worker or ML service into separate Railway services yet.

Why:

- The app already ships with a root `Dockerfile` that installs PHP, Apache, Python, Supervisor, and the Python packages needed by the solver.
- Supervisor already starts Apache, the local ML service, and the background worker in one container.
- The ML service is intentionally loopback-only, so keeping it in the same container is the simplest and safest setup.

## What I found in the codebase

### Already Railway-friendly

- Environment variables are already supported in `db_config.php`.
- The project already has a root `Dockerfile`.
- Supervisor starts all required processes:
  - Apache web server
  - Python ML service
  - PHP worker launcher
- CSV imports use temp storage and are cleaned up after processing.
- The worker uses MySQL `GET_LOCK`, so only one allocation worker runs at a time.

### Things you must account for on Railway

- Railway MySQL exposes `MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`, and `MYSQLDATABASE`, but your app expects `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, and `DB_NAME`.
- Profile pictures are stored on local disk under `uploads/profile_pics`, so you need a Railway volume if you want them to survive redeploys/restarts.
- The repo now includes a dedicated `/health.php` endpoint for Railway healthchecks.

## Railway setup steps

### 1. Push the project to GitHub

Railway is easiest when the repo is on GitHub.

### 2. Create a new Railway project

Inside Railway:

- Create a new project
- Add your GitHub repo as a service
- Add a MySQL database service to the same project

Railway should detect and use the root `Dockerfile` automatically.

## 3. Configure app variables

In your app service, create these variables by referencing the MySQL service:

```env
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_USER=${{MySQL.MYSQLUSER}}
DB_PASS=${{MySQL.MYSQLPASSWORD}}
DB_NAME=${{MySQL.MYSQLDATABASE}}
ML_SERVICE_URL=http://127.0.0.1:5051
ML_SERVICE_TIMEOUT=120
DB_CONNECT_TIMEOUT=120
FAIRMED_ENABLE_ML_SERVICE=1
FAIRMED_ENABLE_WORKER=1
FAIRMED_ML_BIND_HOST=127.0.0.1
FAIRMED_ML_BIND_PORT=5051
PORT=80
```

Notes:

- Replace `MySQL` in the reference syntax with the exact name of your Railway database service if it differs.
- `PORT=80` is a safety setting because Apache listens on port 80 in the container.

## 4. Add a volume for profile pictures

Attach a volume to the app service and mount it to:

```text
/var/www/html/uploads/profile_pics
```

Why this path:

- Student and admin profile uploads are written to `uploads/profile_pics` from PHP.
- In this Docker image, the application code is copied to `/var/www/html`.

If you skip the volume:

- profile pictures will work temporarily
- but they can disappear after redeploys or container replacement

## 5. First database initialization

For a brand-new Railway MySQL database:

1. import `sql/schema.sql`
2. run `php sql/seed.php`
3. run `php sql/run_migrations.php`

Important:

- Only use `sql/schema.sql` for a fresh database.
- Do not use it against an existing database you want to preserve, because your setup docs describe it as the full table definition reset path.

## 6. How to run the DB init on Railway

You have two practical options.

### Option A: one-time manual shell

After the first deploy:

- open a shell into the app service
- run:

```bash
php sql/seed.php
php sql/run_migrations.php
```

For `schema.sql`, import it into the Railway MySQL database before running the two PHP commands.

### Option B: use a temporary init workflow

If you want, we can add a one-time initialization script so Railway can bootstrap a fresh environment more automatically.

For now, manual first-time initialization is safer because:

- `schema.sql` is only for fresh databases
- future deploys should run migrations only

## 7. Healthcheck

Set the Railway healthcheck path to:

```text
/health.php
```

Why this is the right endpoint:

- it returns HTTP `200` only when the app boots cleanly
- it verifies the database connection
- it verifies the local ML service when that service is enabled
- it returns JSON, which makes failures much easier to diagnose in Railway logs

## 8. First production test

After deploy and DB init:

1. open the Railway public domain
2. visit `/admin_signup.php` if you need to create the first admin
3. sign in through `/admin_login.php`
4. upload a small CSV
5. run an allocation
6. confirm the worker updates `allocation_jobs`

## 9. Why I do not recommend splitting services yet

At this stage, one app container is the best fit.

If you split now, you would need to redesign:

- how the PHP app reaches the ML service
- how the worker service shares code/runtime assumptions
- how job orchestration behaves across multiple services

Your current code is built around:

- local ML access at `127.0.0.1:5051`
- supervisor-managed long-running processes
- one shared filesystem path for uploads

That matches a single Railway service very well.

## 10. Risks to watch after deployment

### Volume permissions

Your volume will be mounted into a path used by Apache and PHP. If Railway mounts it as root and the app cannot write there, fix ownership/permissions in the container start path.

### Large Python dependencies

`xgboost`, `ortools`, `pandas`, and `scikit-learn` can make builds slower. The current Dockerfile should still work, but first deploy may take a while.

### Session resets on redeploy

PHP sessions are file-backed by default, so active logins may be lost on redeploy. That is normal unless you later move sessions into Redis or the database.

### Fresh DB requirement

The app can auto-align some schema details at runtime, but it still needs the base tables from `schema.sql` plus seeded data.

## Practical migration summary

Do this in order:

1. Push repo to GitHub
2. Create Railway project
3. Add app service from the repo
4. Add Railway MySQL service
5. Set `DB_*` app variables by referencing the MySQL service variables
6. Set `ML_SERVICE_URL=http://127.0.0.1:5051`
7. Set `PORT=80`
8. Mount a volume at `/var/www/html/uploads/profile_pics`
9. Initialize the fresh database with `schema.sql`, `seed.php`, and `run_migrations.php`
10. Set healthcheck path to `/health.php`
11. Test login, CSV import, and allocation

## If you want the next step

I can do either of these for you next:

- help you deploy from the Railway dashboard or CLI using the files already prepared here
- help you create the exact Railway variables and deployment checklist from your Railway project screen
