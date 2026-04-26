# EDMS — Electronic Document Management System

Enterprise-grade document management for national government deployment.  
Dual-mode: Drive-like working files + strict official records management.

---

## Prerequisites

- **PHP** 8.2+
- **Composer**
- **Node.js** (for frontend assets)
- **PostgreSQL** 17 or **MySQL** 8+ (PostgreSQL preferred)

---

## Setup

### 1. Clone the repository

```bash
git clone <repository-url> gedms
cd gedms
```

### 2. Install dependencies

```bash
composer install
npm install && npm run build
```

### 3. Configure environment

Copy the example environment file and set your values:

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and configure:

- **App:** `APP_NAME`, `APP_URL`
- **Database:** connection, credentials, and database name (see below)

### 4. Create database

**PostgreSQL:**

```bash
# Start PostgreSQL, e.g. on macOS:
brew services start postgresql@17

# Create database
psql -h 127.0.0.1 -U postgres -d postgres -c "CREATE DATABASE gedms;"
```

Then in `.env`:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=gedms
DB_USERNAME=postgres
DB_PASSWORD=
```

**MySQL:**

```bash
mysql -u root -e "CREATE DATABASE gedms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Then in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gedms
DB_USERNAME=root
DB_PASSWORD=
```

### 5. Run migrations and seed data

```bash
php artisan migrate
php artisan db:seed
```

### 6. Start the application

```bash
php artisan serve
```

Open [http://localhost:8000](http://localhost:8000) in your browser.

### 7. Default login credentials

| Role   | Email              | Password  |
|--------|--------------------|-----------|
| Admin  | admin@example.com  | password  |
| Officer| officer@example.com| password  |

---

## Development

- Rebuild frontend after changes: `npm run dev` (watch) or `npm run build`
- Run tests: `php artisan test`
- Code style: `./vendor/bin/pint`
