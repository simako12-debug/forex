---
title: Sharry Database Migration Standards
description: Migration conventions for Laravel monorepo — maintainability, performance, indexing strategy, and common antipatterns
paths:
  - "**/database/migrations/**/*.php"
---

# Database Migration Standards

Rules for writing Laravel migrations in the Sharry monorepo. Focus on **maintainability**, **performance**, and **avoiding common antipatterns**. Additionally, prefer PostgreSQL-compatible constructs where practical — a future migration is planned.

## File Structure

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ...
    }

    public function down(): void
    {
        // ...
    }
};
```

- Always use anonymous class syntax (`return new class extends Migration`)
- Always include both `up()` and `down()` methods
- `down()` must fully reverse what `up()` does
- Use `declare(strict_types=1)`

## Naming Convention

Format: `YYYY_MM_DD_HHMMSS_description.php`

- Use snake_case description
- Start with table name: `2025_03_12_140000_users_add_phone_column.php`
- Be descriptive: what table, what change

## Migration Location

Migrations live inside their respective directory:
```
packages/complex/{package}/database/migrations/
packages/microservices/{package}/database/migrations/
microservices/{service}/database/migrations/
```

Never put package migrations into `complex/database/migrations/`.

## Primary Keys

- Always use UUIDs: `$table->uuid('id')->primary()`
- Never use `$table->id()` (auto-incrementing integer)
- Foreign keys must also be UUID: `$table->uuid('user_id')`

## Columns

- Use `->nullable()` explicitly when a column can be null
- Add `->index()` on foreign keys and frequently queried columns
- Use `$table->timestamps()` for created_at/updated_at
- Use `$table->softDeletes()` when the model uses SoftDeletes trait

## Indexing Strategy

**Think carefully about every index.** Indexes speed up reads but slow down writes and consume storage. Every index must be justified.

### When to add an index
- Foreign key columns — always index (`$table->uuid('user_id')->index()`)
- Columns used in `WHERE` clauses frequently
- Columns used in `ORDER BY` on large tables
- Columns used in `unique()` constraints (implicit index)
- `deleted_at` — always add `softDeletes()` on every table except pivot tables

### When NOT to add an index
- Boolean columns with low cardinality (e.g. `is_active`) — index won't help, full table scan is often faster
- Columns on small tables (< 1000 rows) — overhead isn't worth it
- Columns that are rarely queried
- Columns with very low selectivity (few distinct values relative to row count)

### Composite indexes
Use composite indexes when queries filter on multiple columns together:

```php
// Query: WHERE tenant_id = ? AND status = ? ORDER BY created_at
$table->index(['tenant_id', 'status', 'created_at']);
```

**Column order matters** — put the most selective (most distinct values) column first, or the column used in equality conditions before range conditions.

### Avoid redundant indexes
```php
// WRONG — the composite index already covers tenant_id alone
$table->index('tenant_id');
$table->index(['tenant_id', 'status']);

// CORRECT — only the composite index
$table->index(['tenant_id', 'status']);
```

A composite index on `(a, b)` can serve queries filtering on `a` alone, but NOT queries filtering on `b` alone.

## Common Antipatterns

### Over-indexing
Do not add indexes "just in case". Each index on a write-heavy table has a real cost. Justify every index with a known query pattern.

### Wide VARCHAR columns as indexes
```php
// BAD — indexing a potentially large text column
$table->string('description', 1000)->index();

// BETTER — index a hash or a prefix, or reconsider schema design
$table->string('description', 1000);
$table->string('description_hash', 64)->index(); // store and index a hash
```

### Missing foreign key indexes
Every `uuid('*_id')` column that references another table should have an index. Laravel does NOT add indexes automatically for UUID foreign keys.

### Nullable unique constraints
```php
// CAUTION — MySQL allows multiple NULLs in unique index, but be aware of the behavior
$table->string('external_id')->nullable()->unique();
```

Document the intended behavior when combining `nullable()` with `unique()`.

### Data manipulation in migrations

**⚠️ Migrations are STATIC. They must be fully self-contained and never reference code that can change over time:**

- **NEVER use Eloquent models** — no `User::query()`, no `User::create()`, no scopes. Models change (renamed columns, removed scopes, changed casts) and will break old migrations. Always use `DB::table()`.
- **NEVER use PHP Enums** — no `StatusEnum::Active->value`. Enum cases get renamed or removed. Hardcode the string values directly.
- **NEVER reference constants or config** — no `User::STATUS_ACTIVE`, no `config('app.something')`. Inline the actual values.

A migration must produce the same result whether it runs today or in 2 years. The only imports allowed are `Migration`, `Blueprint`, `Schema`, `DB`, and `MySQLMigrationService`.

**Migrations should only change schema, not data.** Data seeding and manipulation belongs in Seeders. Always push back hard and suggest a Seeder instead.

If the author insists that a data change absolutely must be in a migration (e.g. renaming enum values in-place, backfilling a new NOT NULL column before altering it), allow it reluctantly — but:

1. **Challenge it first** — can this be a Seeder? Can the deploy process run the Seeder after the migration?
2. **If it truly must be a migration**, batch large updates:

```php
// OK IN MIGRATION — backfilling a default value as part of schema change
// (add column nullable → backfill → alter to NOT NULL)
DB::table('users')->whereNull('status')->update(['status' => 'active']);

// SHOULD BE A SEEDER — inserting/importing reference data, populating lookup tables
DB::table('roles')->insert([
    ['name' => 'admin', 'label' => 'Administrator'],
    ['name' => 'user', 'label' => 'User'],
]);
```

When data manipulation is necessary in a migration (e.g. backfilling before a NOT NULL alter), batch large updates:

```php
// BAD — chunkById + filtering on the column you're updating skips rows
// (updated rows no longer match the WHERE, shifting pagination)
DB::table('users')->whereNull('full_name')->chunkById(1000, function ($users) {
    foreach ($users as $user) {
        DB::table('users')->where('id', $user->id)
            ->update(['full_name' => $user->first_name . ' ' . $user->last_name]);
    }
});

// CORRECT — collect IDs first, then process
$ids = DB::table('users')->whereNull('full_name')->pluck('id');
foreach ($ids->chunk(1000) as $chunk) {
    $rows = DB::table('users')->whereIn('id', $chunk)->get();
    foreach ($rows as $row) {
        DB::table('users')->where('id', $row->id)
            ->update(['full_name' => $row->first_name . ' ' . $row->last_name]);
    }
}
```

3. **Always leave a comment** explaining why a Seeder couldn't be used

### Adding NOT NULL without default on existing table
```php
// BAD — will fail if table has existing rows
$table->string('phone');

// GOOD — add nullable first, backfill, then change
$table->string('phone')->nullable();
// Separate migration: backfill data, then alter to NOT NULL
```

## MySQL-Specific Constructs

Prefer PostgreSQL-compatible constructs where practical. Raw MySQL SQL is **forbidden** in migrations — use `MySQLMigrationService` as the abstraction layer:

### ENUM columns
```php
// WRONG — raw MySQL ENUM in migration
$table->enum('status', ['active', 'inactive']);
DB::statement("ALTER TABLE `users` MODIFY `status` ENUM('active','inactive') NOT NULL");

// CORRECT — use MySQLMigrationService for ENUM operations
$this->service->getQueryForAlterEnum(table: 'users', column: 'status', items: ['active', 'inactive']);
```

All MySQL-specific SQL **must** go through `MySQLMigrationService`. Never write raw MySQL SQL directly — if we migrate to PostgreSQL, `MySQLMigrationService` becomes the single abstraction point we need to replace.

### UNSIGNED integers
```php
// WRONG — PostgreSQL does not support UNSIGNED
$table->unsignedInteger('count');
$table->unsignedBigInteger('amount');

// CORRECT — use integer with CHECK constraint if needed
$table->integer('count');
$table->bigInteger('amount');
```

### Raw MySQL SQL
```php
// WRONG — raw MySQL-specific syntax in migration
DB::statement('ALTER TABLE users MODIFY column ...');
DB::statement('... IFNULL(...) ...');

// CORRECT — use Schema builder
Schema::table('users', function (Blueprint $table) {
    $table->string('column')->change();
});

// CORRECT — if Schema builder can't handle it, use MySQLMigrationService
DB::statement($this->service->getQueryForAlterEnum(...));
```

All MySQL-specific operations must be encapsulated in `MySQLMigrationService`. Add new methods there if needed — never inline raw MySQL SQL in migrations.

### Other MySQL-only features to avoid
- `GROUP_CONCAT()` — use `STRING_AGG()` or handle in PHP
- `IFNULL()` — use `COALESCE()` (works on both MySQL and PostgreSQL)
- `FIND_IN_SET()` — use proper relational design instead
- Backtick quoting `` `column` `` — use double quotes `"column"` or let Schema builder handle it

## Idempotent Checks

For alter migrations, check before modifying:

```php
public function up(): void
{
    if (Schema::hasColumn('users', 'phone') === false) {
        Schema::table('users', fn(Blueprint $table) => $table->string('phone')->nullable());
    }
}
```

Use `Schema::hasColumn()`, `Schema::hasIndex()`, `Schema::hasTable()` before conditional changes.

## Transactions

Wrap data migrations (not schema changes) in transactions:

```php
public function up(): void
{
    DB::transaction(function () {
        DB::table('users')->whereNull('status')->update(['status' => 'active']);
    });
}
```

Note: MySQL does not support transactional DDL. PostgreSQL does. For schema changes, keep migrations small and atomic.

## Index Naming

For single-column indexes, let Laravel generate names automatically. For composite indexes, use an explicit descriptive name based on the columns:

```php
// Single column — let Laravel name it
$table->index('deleted_at');
$table->unique('email');

// Composite — explicit name derived from columns
$table->index(['tenant_id', 'status', 'created_at'], 'idx_tenant_status_created');
$table->unique(['user_id', 'access_level_id'], 'uq_user_access_level');
```
