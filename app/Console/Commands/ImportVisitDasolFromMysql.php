<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Move the VisitDasol data from local MySQL into Supabase PostgreSQL.
 *
 * Two modes, and the destructive-sounding one is not destructive:
 *
 *   --dry-run   compares the two databases and writes nothing.
 *   --execute   INSERTs the rows that are missing from PostgreSQL.
 *
 * WHAT --execute WILL NEVER DO. It issues INSERT and, at the end,
 * setval() on the sequences. It never UPDATEs, never DELETEs, never
 * TRUNCATEs, never drops or recreates anything. A row that already
 * exists in PostgreSQL is left exactly as it is: if it matches the
 * source it is skipped, and if it does not, the whole import stops
 * rather than guess which copy is right. Nothing here can overwrite
 * work done in Supabase.
 *
 * Run visitdasol:validate-migration first. This command checks what it
 * needs to protect the data; that one checks whether the schemas agree
 * in the first place, which is a different and larger question.
 */
class ImportVisitDasolFromMysql extends Command
{
    protected $signature = 'visitdasol:import-mysql
                            {--dry-run : Read-only comparison; writes nothing}
                            {--execute : Insert the missing rows into PostgreSQL}
                            {--table=* : Limit the run to these tables}';

    protected $description = 'Compare, and on request import, VisitDasol records from MySQL into Supabase PostgreSQL';

    /**
     * Every table to migrate, in foreign-key dependency order: no table
     * appears before one it points at. ValidateVisitDasolMigration
     * proves this order against the real foreign keys.
     *
     * social_accounts was missing from this list and holds the Google
     * sign-in links. Without it those accounts keep their password but
     * lose the button they actually use.
     *
     * @var list<string>
     */
    private array $tables = [
        'users',
        'social_accounts',
        'destination_categories',
        'amenities',
        'resorts',
        'owner_profiles',
        'rooms',
        'resort_extras',
        'resort_images',
        'room_images',
        'amenity_resort',
        'announcements',
        'resort_manual_payment_accounts',
        'resort_payment_policies',
        'bookings',
        'payments',
        'payment_proofs',
        'conversations',
        'booking_extras',
        'messages',
        'message_attachments',
        'reviews',
        'destinations',
        'destination_images',
        'activity_logs',
    ];

    /**
     * Tables without a single "id" primary key.
     *
     * @var array<string, list<string>>
     */
    private array $primaryKeys = [
        'amenity_resort' => ['amenity_id', 'resort_id'],
    ];

    /** How many rows to send in one multi-row INSERT. */
    private const BATCH = 50;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');

        if ($dry === $execute) {
            $this->error('Choose exactly one of --dry-run or --execute.');

            return self::FAILURE;
        }

        try {
            $source = DB::connection('mysql_source');
            $target = DB::connection('pgsql');
            $source->getPdo();
            $target->getPdo();
        } catch (Throwable $e) {
            // Never echo the exception: a PDO DSN can carry the user.
            $this->error('Database connection failed. Check the mysql_source and pgsql connections.');

            return self::FAILURE;
        }

        $tables = $this->option('table') ?: $this->tables;

        foreach ($tables as $table) {
            if (! in_array($table, $this->tables, true)) {
                $this->error("Unknown table: {$table}");

                return self::FAILURE;
            }
        }

        return $execute
            ? $this->runImport($source, $target, $tables)
            : $this->preflight($source, $target, $tables);
    }

    /* Read-only comparison ------------------------------------------------ */

    /** @param list<string> $tables */
    private function preflight(Connection $source, Connection $target, array $tables): int
    {
        $this->info('VisitDasol MySQL to Supabase preflight');
        $this->warn('READ-ONLY MODE: no database records will be changed.');

        $totals = ['new' => 0, 'same' => 0, 'conflict' => 0];

        foreach ($tables as $table) {
            $this->newLine();
            $this->info("Checking table: {$table}");

            try {
                $counts = $this->compare($source, $target, $table);
            } catch (Throwable $e) {
                $this->error("ERROR IN {$table}: ".$e->getMessage());
                $totals['conflict']++;

                continue;
            }

            foreach ($counts['conflicts'] as $label) {
                $this->warn("ID CONFLICT: {$table} ({$label})");
            }

            $this->table(['Metric', 'Count'], [
                ['Source records', $counts['source']],
                ['Supabase records', $counts['target']],
                ['New records', $counts['new']],
                ['Identical records', $counts['same']],
                ['Conflicting records', count($counts['conflicts'])],
            ]);

            $totals['new'] += $counts['new'];
            $totals['same'] += $counts['same'];
            $totals['conflict'] += count($counts['conflicts']);
        }

        $this->newLine();
        $this->info('PREFLIGHT SUMMARY');
        $this->line("New records: {$totals['new']}");
        $this->line("Identical records: {$totals['same']}");
        $this->line("Conflicts / errors: {$totals['conflict']}");
        $this->warn('No INSERT, UPDATE or DELETE was executed.');

        return $totals['conflict'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Compare one table without writing anything.
     *
     * @return array{source: int, target: int, new: int, same: int, conflicts: list<string>}
     */
    private function compare(Connection $source, Connection $target, string $table): array
    {
        $this->assertComparable($source, $target, $table);

        $keys = $this->primaryKeys[$table] ?? ['id'];
        $existing = $this->existingKeys($target, $table, $keys);

        $new = 0;
        $same = 0;
        $conflicts = [];

        foreach ($this->sourceRows($source, $table, $keys) as $row) {
            $record = (array) $row;
            $fingerprint = $this->keyOf($record, $keys);

            if (! array_key_exists($fingerprint, $existing)) {
                $new++;

                continue;
            }

            $this->sameRow($record, $existing[$fingerprint])
                ? $same++
                : $conflicts[] = $this->labelFor($record, $keys);
        }

        return [
            'source' => $source->table($table)->count(),
            'target' => $target->table($table)->count(),
            'new' => $new,
            'same' => $same,
            'conflicts' => $conflicts,
        ];
    }

    /* The import ---------------------------------------------------------- */

    /**
     * NOT NAMED execute(). Symfony's Command already has a protected
     * execute() and PHP refuses a private one with a different
     * signature, so the import mode gets its own name.
     *
     * @param list<string> $tables
     */
    private function runImport(Connection $source, Connection $target, array $tables): int
    {
        $this->info('VisitDasol MySQL to Supabase IMPORT');
        $this->warn('This will INSERT rows into PostgreSQL. Nothing is updated, deleted or truncated.');
        $this->newLine();

        $summary = [];
        $inserted = 0;
        $skipped = 0;

        foreach ($tables as $table) {
            $this->info("Importing {$table}");

            try {
                $result = $this->importTable($source, $target, $table);
            } catch (Throwable $e) {
                // importTable rolls its own transaction back before
                // rethrowing, so the failed table is untouched. Earlier
                // tables stay committed on purpose: re-running the
                // command skips what is already there.
                $this->newLine();
                $this->error("IMPORT STOPPED IN {$table}: ".$e->getMessage());
                $this->warn('That table was rolled back. Tables imported before it are committed and will be skipped on the next run.');

                $this->summarise($summary, $inserted, $skipped);

                return self::FAILURE;
            }

            $summary[] = [$table, $result['inserted'], $result['skipped'], $result['sequence']];
            $inserted += $result['inserted'];
            $skipped += $result['skipped'];

            $this->line("  inserted {$result['inserted']}, skipped {$result['skipped']}");
        }

        $this->summarise($summary, $inserted, $skipped);
        $this->newLine();
        $this->info('Import finished. Run --dry-run again to confirm the two databases agree.');

        return self::SUCCESS;
    }

    /**
     * One table, inside one transaction.
     *
     * @return array{inserted: int, skipped: int, sequence: string}
     */
    private function importTable(Connection $source, Connection $target, string $table): array
    {
        $this->assertComparable($source, $target, $table);

        $keys = $this->primaryKeys[$table] ?? ['id'];
        $types = $this->targetTypes($target, $table);
        $existing = $this->existingKeys($target, $table, $keys);

        $pending = [];
        $skipped = 0;

        foreach ($this->sourceRows($source, $table, $keys) as $row) {
            $record = (array) $row;
            $fingerprint = $this->keyOf($record, $keys);

            if (array_key_exists($fingerprint, $existing)) {
                if (! $this->sameRow($record, $existing[$fingerprint])) {
                    // Refusing is the whole point. The row in Supabase may
                    // be newer than the one here, and this command has no
                    // way to know which to believe.
                    throw new \RuntimeException(
                        "row {$this->labelFor($record, $keys)} already exists in PostgreSQL with different values; "
                        .'resolve it by hand, or re-run with --table to skip this table'
                    );
                }

                $skipped++;

                continue;
            }

            $pending[] = $this->convert($record, $types);
        }

        if ($pending === []) {
            return ['inserted' => 0, 'skipped' => $skipped, 'sequence' => 'unchanged'];
        }

        $this->assertNoUniqueCollision($target, $table, $pending, $keys);

        $target->beginTransaction();

        try {
            foreach (array_chunk($pending, self::BATCH) as $chunk) {
                $target->table($table)->insert($chunk);
            }

            $sequence = $this->advanceSequence($target, $table, $keys);

            $target->commit();
        } catch (Throwable $e) {
            $target->rollBack();

            throw $e;
        }

        return ['inserted' => count($pending), 'skipped' => $skipped, 'sequence' => $sequence];
    }

    /**
     * Move the id sequence past the ids just inserted.
     *
     * Without this the next tourist to register is handed id 1, which
     * already belongs to somebody, and the insert fails on the primary
     * key. GREATEST means the sequence is only ever moved forward: a
     * sequence already ahead of the import is left where it is.
     */
    private function advanceSequence(Connection $target, string $table, array $keys): string
    {
        if ($keys !== ['id']) {
            return 'none (composite key)';
        }

        $row = $target->selectOne("select pg_get_serial_sequence('public.'||?, 'id') as sequence", [$table]);
        $sequence = $row?->sequence;

        if (! $sequence) {
            return 'none found';
        }

        $target->statement("
            select setval(
                ?::regclass,
                greatest(
                    (select coalesce(max(id), 1) from {$table}),
                    (select last_value from {$sequence})
                )
            )
        ", [$sequence]);

        $now = $target->selectOne("select last_value from {$sequence}");

        return 'set to '.($now->last_value ?? '?');
    }

    /**
     * Stop before inserting if any pending row would break a unique
     * constraint against a row already in PostgreSQL.
     *
     * The database would catch this itself, but only mid-insert and with
     * a message naming a constraint rather than a record. Catching it
     * here keeps the table untouched and says which value collided.
     *
     * @param list<array<string, mixed>> $pending
     */
    private function assertNoUniqueCollision(Connection $target, string $table, array $pending, array $keys): void
    {
        if ($target->table($table)->count() === 0) {
            return;   // nothing to collide with
        }

        foreach ($this->uniqueConstraints($target, $table) as $name => $columns) {
            if (array_diff($columns, array_keys($pending[0]))) {
                continue;
            }

            if ($columns === $keys) {
                continue;   // the primary key, already handled above
            }

            foreach (array_chunk($pending, 200) as $chunk) {
                $hits = $target->table($table)
                    ->where(function ($outer) use ($chunk, $columns) {
                        foreach ($chunk as $row) {
                            $outer->orWhere(function ($inner) use ($row, $columns) {
                                foreach ($columns as $column) {
                                    $inner->where($column, $row[$column]);
                                }
                            });
                        }
                    })
                    ->limit(1)
                    ->get($columns);

                if ($hits->isNotEmpty()) {
                    $value = implode('+', array_map(
                        fn ($c) => (string) ((array) $hits->first())[$c],
                        $columns
                    ));

                    throw new \RuntimeException(
                        "unique constraint {$name} (".implode(', ', $columns).") already holds {$value} in PostgreSQL"
                    );
                }
            }
        }
    }

    /* Value conversion ----------------------------------------------------- */

    /**
     * Turn one MySQL row into something PostgreSQL will accept.
     *
     * The three that actually bite:
     *
     *   BOOLEAN. MySQL hands back 1 and 0 from a tinyint(1). PostgreSQL
     *   refuses an integer in a boolean column outright — "column is of
     *   type boolean but expression is of type integer" — so the value
     *   becomes a real PHP bool and PDO sends it as one.
     *
     *   JSON. The source column is longtext, the target is json. A
     *   string goes in unchanged and PostgreSQL parses it, but an empty
     *   string is not valid JSON and has to become NULL.
     *
     *   ZERO DATES. MySQL's '0000-00-00 00:00:00' has no PostgreSQL
     *   equivalent and is stored as NULL rather than rejected.
     *
     * @param array<string, mixed> $record
     * @param array<string, string> $types
     * @return array<string, mixed>
     */
    private function convert(array $record, array $types): array
    {
        $out = [];

        foreach ($record as $column => $value) {
            $type = $types[$column] ?? null;

            if ($value === null) {
                $out[$column] = null;

                continue;
            }

            $out[$column] = match ($type) {
                'boolean' => (bool) (int) $value,
                'json', 'jsonb' => $this->jsonValue($value),
                'date', 'timestamp without time zone', 'timestamp with time zone' => $this->dateValue($value),
                default => $value,
            };
        }

        return $out;
    }

    private function jsonValue(mixed $value): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('a value bound for a json column is not valid JSON');
        }

        return $text;
    }

    private function dateValue(mixed $value): ?string
    {
        $text = (string) $value;

        return str_starts_with($text, '0000-00-00') ? null : $text;
    }

    /* Shared helpers -------------------------------------------------------- */

    private function assertComparable(Connection $source, Connection $target, string $table): void
    {
        if (! $source->getSchemaBuilder()->hasTable($table)) {
            throw new \RuntimeException("source table {$table} is missing");
        }

        if (! $target->getSchemaBuilder()->hasTable($table)) {
            throw new \RuntimeException("target table {$table} is missing: run the migrations on PostgreSQL first");
        }

        $from = $source->getSchemaBuilder()->getColumnListing($table);
        $to = $target->getSchemaBuilder()->getColumnListing($table);

        sort($from);
        sort($to);

        if ($from !== $to) {
            throw new \RuntimeException(
                'columns differ. MySQL only: ['.implode(', ', array_diff($from, $to))
                .'], PostgreSQL only: ['.implode(', ', array_diff($to, $from)).']'
            );
        }

        $keys = $this->primaryKeys[$table] ?? ['id'];

        foreach ($keys as $key) {
            if (! in_array($key, $from, true)) {
                throw new \RuntimeException("primary key column {$key} is missing from {$table}");
            }
        }
    }

    /** @return \Generator<object> */
    private function sourceRows(Connection $source, string $table, array $keys): \Generator
    {
        $query = $source->table($table);

        foreach ($keys as $key) {
            $query->orderBy($key);
        }

        yield from $query->cursor();
    }

    /**
     * Every row already in the target, keyed by its primary key, in one
     * query rather than one per source row.
     *
     * @return array<string, array<string, mixed>>
     */
    private function existingKeys(Connection $target, string $table, array $keys): array
    {
        $out = [];

        foreach ($target->table($table)->cursor() as $row) {
            $record = (array) $row;
            $out[$this->keyOf($record, $keys)] = $record;
        }

        return $out;
    }

    /** @return array<string, string> column => PostgreSQL data_type */
    private function targetTypes(Connection $target, string $table): array
    {
        $types = [];

        foreach ($target->select("
            select column_name, data_type
            from information_schema.columns
            where table_schema = 'public' and table_name = ?
        ", [$table]) as $row) {
            $types[$row->column_name] = $row->data_type;
        }

        return $types;
    }

    /** @return array<string, list<string>> */
    private function uniqueConstraints(Connection $target, string $table): array
    {
        $out = [];

        foreach ($target->select("
            select c.conname as name,
                   (select string_agg(a.attname, ',' order by k.ord)
                      from unnest(c.conkey) with ordinality as k(attnum, ord)
                      join pg_attribute a on a.attrelid = rel.oid and a.attnum = k.attnum) as cols
            from pg_constraint c
            join pg_class rel on rel.oid = c.conrelid
            join pg_namespace ns on ns.oid = rel.relnamespace
            where ns.nspname = 'public' and c.contype = 'u' and rel.relname = ?
        ", [$table]) as $row) {
            $out[$row->name] = explode(',', (string) $row->cols);
        }

        return $out;
    }

    private function keyOf(array $record, array $keys): string
    {
        return implode('|', array_map(fn ($k) => (string) ($record[$k] ?? ''), $keys));
    }

    private function labelFor(array $record, array $keys): string
    {
        return implode(', ', array_map(fn ($k) => "{$k}=".($record[$k] ?? ''), $keys));
    }

    /**
     * Whether two rows say the same thing, allowing for the fact that
     * the two engines hand back the same value in different shapes:
     * PostgreSQL returns a real bool where MySQL returns 1, and a
     * numeric column can come back as "1500.00" or "1500.0".
     */
    private function sameRow(array $source, array $target): bool
    {
        unset(
            $source['created_at'], $source['updated_at'],
            $target['created_at'], $target['updated_at'],
        );

        return $this->normalize($source) === $this->normalize($target);
    }

    private function normalize(array $record): array
    {
        ksort($record);

        return array_map(function ($value) {
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            if ($value === null) {
                return null;
            }

            if (is_numeric($value)) {
                // "1500.00" and "1500.0" are the same amount.
                return rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.');
            }

            return (string) $value;
        }, $record);
    }

    /** @param list<array{0:string,1:int,2:int,3:string}> $summary */
    private function summarise(array $summary, int $inserted, int $skipped): void
    {
        if ($summary === []) {
            return;
        }

        $this->newLine();
        $this->info('IMPORT SUMMARY');
        $this->table(['Table', 'Inserted', 'Skipped', 'Sequence'], $summary);
        $this->line("Total inserted: {$inserted}");
        $this->line("Total skipped (already identical): {$skipped}");
    }
}
