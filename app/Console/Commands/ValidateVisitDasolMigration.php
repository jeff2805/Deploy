<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * READ-ONLY validation of the MySQL -> Supabase PostgreSQL migration.
 *
 * This command runs SELECT statements and nothing else. It never writes,
 * never creates, never drops. Its whole job is to answer one question
 * before anybody risks an import: would the data that is in MySQL
 * survive the journey into PostgreSQL unchanged?
 *
 * WHY A SEPARATE COMMAND FROM THE PREFLIGHT.
 *
 * visitdasol:import-mysql --dry-run answers "what is missing in the
 * target" by comparing row counts and primary keys. That is necessary
 * and not sufficient: two tables can hold the same column names and
 * still disagree about whether a column accepts NULL, how wide a
 * decimal is, or whether 'properties' holds parseable JSON. Those are
 * the failures that surface halfway through an import, with some tables
 * written and some not. This command looks for them in advance.
 *
 * ON SPEED. Supabase is a long way from a laptop in Pangasinan; every
 * round trip costs real time. So every check here is written as an
 * aggregate or a batched IN (...) query rather than a query per row.
 */
class ValidateVisitDasolMigration extends Command
{
    protected $signature = 'visitdasol:validate-migration
                            {--source=mysql_source : The MySQL connection to read from}
                            {--target=pgsql : The PostgreSQL connection to compare against}';

    protected $description = 'Read-only compatibility validation before the MySQL to PostgreSQL import';

    /**
     * The tables to migrate, in foreign-key dependency order: a table
     * never appears before a table it points at. The import relies on
     * this order, so it is validated here rather than trusted.
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

    /** Findings, newest last. @var list<array{0:string,1:string,2:string}> */
    private array $findings = [];

    private int $passCount = 0;

    private int $warnCount = 0;

    private int $failCount = 0;

    public function handle(): int
    {
        $this->info('VisitDasol migration validation');
        $this->warn('READ-ONLY: this command issues SELECT statements only.');
        $this->newLine();

        try {
            $source = DB::connection((string) $this->option('source'));
            $target = DB::connection((string) $this->option('target'));
            $source->getPdo();
            $target->getPdo();
        } catch (Throwable $e) {
            // The message can name a host but never a password: Laravel's
            // PDO exceptions carry the DSN, so it is not echoed verbatim.
            $this->error('Could not reach both databases. Check the connections and try again.');

            return self::FAILURE;
        }

        $this->line('Source: '.$source->getDatabaseName().' (MySQL)');
        $this->line('Target: '.$target->getDatabaseName().' (PostgreSQL)');
        $this->newLine();

        $sourceSchema = $this->mysqlSchema($source);
        $targetSchema = $this->pgsqlSchema($target);

        $this->checkTablesExist($sourceSchema, $targetSchema);
        $this->checkColumns($sourceSchema, $targetSchema);
        $this->checkPrimaryKeys($sourceSchema, $targetSchema);
        $this->checkUniqueConstraints($sourceSchema, $targetSchema);
        $this->checkForeignKeys($sourceSchema, $targetSchema);
        $this->checkOrphanedRows($source, $sourceSchema);
        $this->checkNullability($source, $sourceSchema, $targetSchema);
        $this->checkJson($source, $sourceSchema, $targetSchema);
        $this->checkBooleans($source, $sourceSchema, $targetSchema);
        $this->checkNumericRanges($source, $sourceSchema, $targetSchema);
        $this->checkUniqueCollisions($source, $target, $sourceSchema, $targetSchema);
        $this->checkSequences($source, $target, $sourceSchema, $targetSchema);

        return $this->report();
    }

    /* Schema readers ----------------------------------------------------- */

    /**
     * Everything about the MySQL side in four queries rather than four
     * per table.
     *
     * @return array{columns: array<string, array<string, array<string, mixed>>>, primary: array<string, list<string>>, unique: array<string, array<string, list<string>>>, foreign: array<string, list<array<string, string>>>}
     */
    private function mysqlSchema(Connection $db): array
    {
        $schema = $db->getDatabaseName();
        $list = $this->tables;

        $columns = [];

        foreach ($db->select('
            select table_name, column_name, column_type, data_type,
                   is_nullable, column_default, extra,
                   numeric_precision, numeric_scale, character_maximum_length
            from information_schema.columns
            where table_schema = ?
            order by table_name, ordinal_position
        ', [$schema]) as $row) {
            $row = (array) array_change_key_case((array) $row);

            if (! in_array($row['table_name'], $list, true)) {
                continue;
            }

            $columns[$row['table_name']][$row['column_name']] = $row;
        }

        $primary = [];
        $unique = [];

        foreach ($db->select("
            select t.table_name, t.constraint_name, t.constraint_type,
                   group_concat(k.column_name order by k.ordinal_position separator ',') as cols
            from information_schema.table_constraints t
            join information_schema.key_column_usage k
              on k.constraint_name = t.constraint_name
             and k.table_schema = t.table_schema
             and k.table_name = t.table_name
            where t.table_schema = ?
              and t.constraint_type in ('PRIMARY KEY','UNIQUE')
            group by t.table_name, t.constraint_name, t.constraint_type
        ", [$schema]) as $row) {
            $row = (array) array_change_key_case((array) $row);

            if (! in_array($row['table_name'], $list, true)) {
                continue;
            }

            $cols = explode(',', (string) $row['cols']);

            if ($row['constraint_type'] === 'PRIMARY KEY') {
                $primary[$row['table_name']] = $cols;
            } else {
                $unique[$row['table_name']][$row['constraint_name']] = $cols;
            }
        }

        $foreign = [];

        foreach ($db->select('
            select k.table_name, k.column_name,
                   k.referenced_table_name, k.referenced_column_name
            from information_schema.key_column_usage k
            where k.table_schema = ?
              and k.referenced_table_name is not null
        ', [$schema]) as $row) {
            $row = (array) array_change_key_case((array) $row);

            if (! in_array($row['table_name'], $list, true)) {
                continue;
            }

            $foreign[$row['table_name']][] = [
                'column' => $row['column_name'],
                'table' => $row['referenced_table_name'],
                'references' => $row['referenced_column_name'],
            ];
        }

        return compact('columns', 'primary', 'unique', 'foreign');
    }

    /**
     * The same four facts from PostgreSQL, in the same shape, so the
     * comparisons below do not care which engine they came from.
     *
     * @return array{columns: array<string, array<string, array<string, mixed>>>, primary: array<string, list<string>>, unique: array<string, array<string, list<string>>>, foreign: array<string, list<array<string, string>>>, sequences: array<string, array{sequence: string, last: int|null}>}
     */
    private function pgsqlSchema(Connection $db): array
    {
        $list = $this->tables;
        $placeholders = implode(',', array_fill(0, count($list), '?'));

        $columns = [];

        foreach ($db->select("
            select table_name, column_name, data_type, udt_name,
                   is_nullable, column_default, is_identity,
                   numeric_precision, numeric_scale, character_maximum_length
            from information_schema.columns
            where table_schema = 'public'
              and table_name in ({$placeholders})
            order by table_name, ordinal_position
        ", $list) as $row) {
            $row = (array) $row;
            $columns[$row['table_name']][$row['column_name']] = $row;
        }

        $primary = [];
        $unique = [];

        foreach ($db->select("
            select c.conname as constraint_name,
                   c.contype as constraint_type,
                   rel.relname as table_name,
                   (select string_agg(a.attname, ',' order by k.ord)
                      from unnest(c.conkey) with ordinality as k(attnum, ord)
                      join pg_attribute a
                        on a.attrelid = rel.oid and a.attnum = k.attnum) as cols
            from pg_constraint c
            join pg_class rel on rel.oid = c.conrelid
            join pg_namespace ns on ns.oid = rel.relnamespace
            where ns.nspname = 'public'
              and c.contype in ('p','u')
              and rel.relname in ({$placeholders})
        ", $list) as $row) {
            $row = (array) $row;
            $cols = explode(',', (string) $row['cols']);

            if ($row['constraint_type'] === 'p') {
                $primary[$row['table_name']] = $cols;
            } else {
                $unique[$row['table_name']][$row['constraint_name']] = $cols;
            }
        }

        $foreign = [];

        foreach ($db->select("
            select rel.relname as table_name,
                   a.attname as column_name,
                   fref.relname as referenced_table_name,
                   fa.attname as referenced_column_name
            from pg_constraint c
            join pg_class rel on rel.oid = c.conrelid
            join pg_namespace ns on ns.oid = rel.relnamespace
            join pg_class fref on fref.oid = c.confrelid
            join unnest(c.conkey) with ordinality as k(attnum, ord) on true
            join pg_attribute a on a.attrelid = rel.oid and a.attnum = k.attnum
            join unnest(c.confkey) with ordinality as fk(attnum, ord)
              on fk.ord = k.ord
            join pg_attribute fa on fa.attrelid = fref.oid and fa.attnum = fk.attnum
            where ns.nspname = 'public'
              and c.contype = 'f'
              and rel.relname in ({$placeholders})
        ", $list) as $row) {
            $row = (array) $row;

            $foreign[$row['table_name']][] = [
                'column' => $row['column_name'],
                'table' => $row['referenced_table_name'],
                'references' => $row['referenced_column_name'],
            ];
        }

        /*
         * The sequence behind each table's id, and where it currently
         * stands. pg_sequence_last_value returns NULL for a sequence that
         * has never issued a value, which is exactly the state of a fresh
         * database and must not be confused with zero.
         */
        $sequences = [];

        foreach ($list as $table) {
            if (! isset($columns[$table]['id'])) {
                continue;
            }

            try {
                $row = $db->selectOne("
                    select pg_get_serial_sequence('public.'||?, 'id') as sequence
                ", [$table]);

                $name = $row?->sequence;

                if (! $name) {
                    continue;
                }

                $last = $db->selectOne('select pg_sequence_last_value(?::regclass) as last', [$name]);

                $sequences[$table] = [
                    'sequence' => $name,
                    'last' => $last?->last === null ? null : (int) $last->last,
                ];
            } catch (Throwable) {
                // A table without a sequence is reported by checkSequences.
            }
        }

        return compact('columns', 'primary', 'unique', 'foreign', 'sequences');
    }

    /* Checks ------------------------------------------------------------- */

    private function checkTablesExist(array $source, array $target): void
    {
        $this->section('1. Table presence');

        foreach ($this->tables as $table) {
            $inSource = isset($source['columns'][$table]);
            $inTarget = isset($target['columns'][$table]);

            if ($inSource && $inTarget) {
                $this->recordPass($table, 'present in both databases');
            } elseif (! $inSource) {
                $this->recordFail($table, 'missing from the MySQL source');
            } else {
                $this->recordFail($table, 'missing from PostgreSQL: run the migrations there first');
            }
        }
    }

    private function checkColumns(array $source, array $target): void
    {
        $this->section('2. Columns and type compatibility');

        foreach ($this->tables as $table) {
            $from = $source['columns'][$table] ?? null;
            $to = $target['columns'][$table] ?? null;

            if (! $from || ! $to) {
                continue;
            }

            $onlySource = array_diff(array_keys($from), array_keys($to));
            $onlyTarget = array_diff(array_keys($to), array_keys($from));

            if ($onlySource) {
                $this->recordFail($table, 'columns in MySQL only: '.implode(', ', $onlySource));
            }

            if ($onlyTarget) {
                // Extra target columns are survivable if they accept NULL
                // or carry a default; the insert simply omits them.
                $blocking = [];

                foreach ($onlyTarget as $column) {
                    if ($to[$column]['is_nullable'] === 'NO' && $to[$column]['column_default'] === null) {
                        $blocking[] = $column;
                    }
                }

                $blocking
                    ? $this->recordFail($table, 'PostgreSQL requires columns MySQL does not have: '.implode(', ', $blocking))
                    : $this->recordWarning($table, 'columns in PostgreSQL only (nullable, so the insert may omit them): '.implode(', ', $onlyTarget));
            }

            foreach ($from as $column => $definition) {
                if (! isset($to[$column])) {
                    continue;
                }

                $a = $this->familyFromMysql($definition);
                $b = $this->familyFromPgsql($to[$column]);

                if ($a === $b) {
                    continue;
                }

                $this->compatible($a, $b)
                    ? $this->recordWarning($table, "{$column}: {$definition['column_type']} -> {$to[$column]['data_type']} (convertible, checked below)")
                    : $this->recordFail($table, "{$column}: {$definition['column_type']} -> {$to[$column]['data_type']} is not a safe conversion");
            }

            if (! $onlySource && ! $onlyTarget) {
                $this->recordPass($table, count($from).' columns, names aligned');
            }
        }
    }

    private function checkPrimaryKeys(array $source, array $target): void
    {
        $this->section('3. Primary keys');

        foreach ($this->tables as $table) {
            $a = $source['primary'][$table] ?? null;
            $b = $target['primary'][$table] ?? null;

            if (! $a) {
                $this->recordFail($table, 'no primary key in MySQL: rows cannot be matched safely');

                continue;
            }

            if (! $b) {
                $this->recordFail($table, 'no primary key in PostgreSQL');

                continue;
            }

            sort($a);
            sort($b);

            $a === $b
                ? $this->recordPass($table, 'primary key ('.implode(', ', $a).')')
                : $this->recordFail($table, 'primary key differs: MySQL ('.implode(', ', $a).') vs PostgreSQL ('.implode(', ', $b).')');
        }
    }

    private function checkUniqueConstraints(array $source, array $target): void
    {
        $this->section('4. Unique constraints');

        foreach ($this->tables as $table) {
            $a = $this->signatures($source['unique'][$table] ?? []);
            $b = $this->signatures($target['unique'][$table] ?? []);

            $missing = array_diff($a, $b);
            $extra = array_diff($b, $a);

            if (! $missing && ! $extra) {
                $this->recordPass($table, $a ? count($a).' unique constraint(s): '.implode(' | ', $a) : 'none on either side');

                continue;
            }

            if ($missing) {
                $this->recordWarning($table, 'unique in MySQL but not PostgreSQL: '.implode(' | ', $missing));
            }

            if ($extra) {
                // This is the dangerous direction: PostgreSQL will refuse
                // rows MySQL happily held.
                $this->recordFail($table, 'unique in PostgreSQL but not MySQL: '.implode(' | ', $extra));
            }
        }
    }

    private function checkForeignKeys(array $source, array $target): void
    {
        $this->section('5. Foreign keys');

        foreach ($this->tables as $table) {
            $a = $this->fkSignatures($source['foreign'][$table] ?? []);
            $b = $this->fkSignatures($target['foreign'][$table] ?? []);

            $missing = array_diff($a, $b);
            $extra = array_diff($b, $a);

            if (! $missing && ! $extra) {
                $this->recordPass($table, $a ? count($a).' foreign key(s)' : 'none on either side');

                continue;
            }

            if ($extra) {
                $this->recordFail($table, 'PostgreSQL enforces foreign keys MySQL does not: '.implode(' | ', $extra));
            }

            if ($missing) {
                $this->recordWarning($table, 'MySQL has foreign keys PostgreSQL does not: '.implode(' | ', $missing));
            }
        }

        $this->checkDependencyOrder($source);
    }

    /**
     * The import writes tables in the order of $this->tables. If a table
     * points at one that comes later, PostgreSQL rejects the row — so the
     * order is checked rather than assumed.
     */
    private function checkDependencyOrder(array $source): void
    {
        $position = array_flip($this->tables);
        $problems = [];

        foreach ($this->tables as $table) {
            foreach ($source['foreign'][$table] ?? [] as $fk) {
                $parent = $fk['table'];

                if ($parent === $table || ! isset($position[$parent])) {
                    continue;   // self-reference, or a parent not being imported
                }

                if ($position[$parent] > $position[$table]) {
                    $problems[] = "{$table}.{$fk['column']} needs {$parent}, which is imported later";
                }
            }
        }

        $problems
            ? $this->recordFail('(order)', implode('; ', $problems))
            : $this->recordPass('(order)', 'every table is imported after the tables it references');
    }

    private function checkOrphanedRows(Connection $db, array $source): void
    {
        $this->section('6. Orphaned rows in the source');

        foreach ($this->tables as $table) {
            foreach ($source['foreign'][$table] ?? [] as $fk) {
                $column = $fk['column'];
                $parent = $fk['table'];
                $references = $fk['references'];

                try {
                    $orphans = $db->table($table.' as c')
                        ->whereNotNull("c.{$column}")
                        ->whereNotExists(function ($query) use ($parent, $references, $column) {
                            $query->selectRaw('1')
                                ->from($parent.' as p')
                                ->whereColumn("p.{$references}", "c.{$column}");
                        })
                        ->count();
                } catch (Throwable $e) {
                    $this->recordWarning($table, "could not check {$column}: ".$e->getMessage());

                    continue;
                }

                $orphans > 0
                    ? $this->recordFail($table, "{$orphans} row(s) point at a missing {$parent}.{$references} via {$column}")
                    : $this->recordPass($table, "{$column} -> {$parent}.{$references}: no orphans");
            }
        }
    }

    private function checkNullability(Connection $db, array $source, array $target): void
    {
        $this->section('7. Nullable columns');

        foreach ($this->tables as $table) {
            $from = $source['columns'][$table] ?? null;
            $to = $target['columns'][$table] ?? null;

            if (! $from || ! $to) {
                continue;
            }

            $tighter = [];

            foreach ($from as $column => $definition) {
                if (! isset($to[$column])) {
                    continue;
                }

                // MySQL accepts NULL here and PostgreSQL does not: any row
                // holding NULL will be refused on insert.
                if ($definition['is_nullable'] === 'YES' && $to[$column]['is_nullable'] === 'NO') {
                    $tighter[] = $column;
                }
            }

            if (! $tighter) {
                $this->recordPass($table, 'no column is stricter in PostgreSQL');

                continue;
            }

            foreach ($tighter as $column) {
                $nulls = $db->table($table)->whereNull($column)->count();

                $nulls > 0
                    ? $this->recordFail($table, "{$column} is NOT NULL in PostgreSQL but holds {$nulls} NULL(s) in MySQL")
                    : $this->recordWarning($table, "{$column} is NOT NULL in PostgreSQL; MySQL allows NULL but holds none today");
            }
        }
    }

    private function checkJson(Connection $db, array $source, array $target): void
    {
        $this->section('8. JSON validity');

        $checked = false;

        foreach ($this->tables as $table) {
            $from = $source['columns'][$table] ?? null;
            $to = $target['columns'][$table] ?? null;

            if (! $from || ! $to) {
                continue;
            }

            foreach ($from as $column => $definition) {
                if (! isset($to[$column])) {
                    continue;
                }

                $targetIsJson = in_array($to[$column]['data_type'], ['json', 'jsonb'], true);
                $sourceIsJson = $definition['data_type'] === 'json';

                // A text column on both sides may still carry JSON that the
                // application casts to an array. Those are worth parsing too,
                // because an unparseable value is a bug either way.
                $looksLikeJson = in_array($column, ['properties', 'payload', 'meta', 'metadata', 'options'], true);

                if (! $targetIsJson && ! $sourceIsJson && ! $looksLikeJson) {
                    continue;
                }

                $checked = true;
                $bad = 0;
                $total = 0;

                foreach ($db->table($table)->select($column)->whereNotNull($column)->cursor() as $row) {
                    $value = ((array) $row)[$column];
                    $total++;

                    if ($value === '') {
                        $bad++;

                        continue;
                    }

                    json_decode((string) $value, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $bad++;
                    }
                }

                $note = $targetIsJson
                    ? "{$definition['data_type']} -> {$to[$column]['data_type']}"
                    : "{$definition['data_type']} on both sides, cast to array by the model";

                $bad > 0
                    ? $this->recordFail($table, "{$column}: {$bad} of {$total} value(s) are not valid JSON ({$note})")
                    : $this->recordPass($table, "{$column}: {$total} value(s) parse as JSON ({$note})");
            }
        }

        if (! $checked) {
            $this->recordPass('(json)', 'no JSON or array-cast columns found');
        }
    }

    private function checkBooleans(Connection $db, array $source, array $target): void
    {
        $this->section('9. Boolean conversion');

        $checked = false;

        foreach ($this->tables as $table) {
            $from = $source['columns'][$table] ?? null;
            $to = $target['columns'][$table] ?? null;

            if (! $from || ! $to) {
                continue;
            }

            foreach ($from as $column => $definition) {
                if (! isset($to[$column])) {
                    continue;
                }

                $isTinyint = str_starts_with((string) $definition['column_type'], 'tinyint(1)');
                $targetBool = in_array($to[$column]['data_type'], ['boolean'], true);

                if (! $isTinyint && ! $targetBool) {
                    continue;
                }

                $checked = true;

                if (! $targetBool) {
                    $this->recordWarning($table, "{$column}: tinyint(1) in MySQL but {$to[$column]['data_type']} in PostgreSQL");

                    continue;
                }

                // PostgreSQL takes true/false, not 1/0, and will refuse
                // anything outside the pair even from a tinyint that MySQL
                // allowed to hold 2.
                $odd = $db->table($table)
                    ->whereNotNull($column)
                    ->whereNotIn($column, [0, 1])
                    ->count();

                $odd > 0
                    ? $this->recordFail($table, "{$column}: {$odd} row(s) hold a value other than 0 or 1")
                    : $this->recordPass($table, "{$column}: tinyint(1) -> boolean, every value is 0 or 1");
            }
        }

        if (! $checked) {
            $this->recordPass('(boolean)', 'no boolean columns found');
        }
    }

    private function checkNumericRanges(Connection $db, array $source, array $target): void
    {
        $this->section('10. Decimal and numeric ranges');

        foreach ($this->tables as $table) {
            $from = $source['columns'][$table] ?? null;
            $to = $target['columns'][$table] ?? null;

            if (! $from || ! $to) {
                continue;
            }

            foreach ($from as $column => $definition) {
                if (! isset($to[$column])) {
                    continue;
                }

                if (! in_array($definition['data_type'], ['decimal', 'numeric'], true)) {
                    continue;
                }

                $sourcePrecision = (int) $definition['numeric_precision'];
                $sourceScale = (int) $definition['numeric_scale'];
                $targetPrecision = (int) ($to[$column]['numeric_precision'] ?? 0);
                $targetScale = (int) ($to[$column]['numeric_scale'] ?? 0);

                if ($targetPrecision === 0) {
                    $this->recordWarning($table, "{$column}: decimal({$sourcePrecision},{$sourceScale}) -> unconstrained {$to[$column]['data_type']}");

                    continue;
                }

                $roomier = ($targetPrecision - $targetScale) >= ($sourcePrecision - $sourceScale)
                    && $targetScale >= $sourceScale;

                if ($roomier) {
                    $this->recordPass($table, "{$column}: decimal({$sourcePrecision},{$sourceScale}) fits numeric({$targetPrecision},{$targetScale})");

                    continue;
                }

                // The target is narrower. Only the actual values decide
                // whether that matters, so they are measured.
                $limit = '9'.str_repeat('9', max(0, $targetPrecision - $targetScale - 1));
                $over = $db->table($table)
                    ->whereNotNull($column)
                    ->where(function ($query) use ($column, $limit) {
                        $query->where($column, '>', $limit)->orWhere($column, '<', '-'.$limit);
                    })
                    ->count();

                $over > 0
                    ? $this->recordFail($table, "{$column}: {$over} value(s) do not fit numeric({$targetPrecision},{$targetScale})")
                    : $this->recordWarning($table, "{$column}: decimal({$sourcePrecision},{$sourceScale}) -> narrower numeric({$targetPrecision},{$targetScale}), but today's values all fit");
            }
        }
    }

    private function checkUniqueCollisions(Connection $source, Connection $target, array $sourceSchema, array $targetSchema): void
    {
        $this->section('11. Unique-value collisions with existing target rows');

        foreach ($this->tables as $table) {
            $constraints = $targetSchema['unique'][$table] ?? [];

            if (! $constraints || ! isset($sourceSchema['columns'][$table])) {
                continue;
            }

            try {
                $targetRows = $target->table($table)->count();
            } catch (Throwable $e) {
                $this->recordWarning($table, 'could not count target rows');

                continue;
            }

            if ($targetRows === 0) {
                $this->recordPass($table, 'target is empty, so no unique value can collide');

                continue;
            }

            foreach ($constraints as $name => $columns) {
                $missing = array_diff($columns, array_keys($sourceSchema['columns'][$table]));

                if ($missing) {
                    $this->recordWarning($table, "{$name}: covers columns the source lacks (".implode(', ', $missing).')');

                    continue;
                }

                $collisions = 0;
                $examples = [];

                // Batched: the source values are collected, then matched
                // against the target in chunks rather than row by row.
                $values = $source->table($table)->select($columns)->get();

                foreach ($values->chunk(200) as $chunk) {
                    $query = $target->table($table);

                    $query->where(function ($outer) use ($chunk, $columns) {
                        foreach ($chunk as $row) {
                            $outer->orWhere(function ($inner) use ($row, $columns) {
                                foreach ($columns as $column) {
                                    $inner->where($column, ((array) $row)[$column]);
                                }
                            });
                        }
                    });

                    foreach ($query->get($columns) as $hit) {
                        $collisions++;

                        if (count($examples) < 3) {
                            $examples[] = implode('+', array_map(
                                fn ($c) => (string) ((array) $hit)[$c],
                                $columns
                            ));
                        }
                    }
                }

                $collisions > 0
                    ? $this->recordFail($table, "{$name} (".implode(', ', $columns)."): {$collisions} value(s) already exist in PostgreSQL, e.g. ".implode(', ', $examples))
                    : $this->recordPass($table, "{$name} (".implode(', ', $columns).'): no collisions');
            }
        }
    }

    private function checkSequences(Connection $source, Connection $target, array $sourceSchema, array $targetSchema): void
    {
        $this->section('12. PostgreSQL sequence readiness');

        foreach ($this->tables as $table) {
            if (! isset($sourceSchema['columns'][$table]['id'])) {
                continue;   // composite-key tables have no sequence to set
            }

            $sequence = $targetSchema['sequences'][$table] ?? null;

            if (! $sequence) {
                $this->recordFail($table, 'id has no PostgreSQL sequence: new rows will not get an id after the import');

                continue;
            }

            $maxId = (int) ($source->table($table)->max('id') ?? 0);
            $last = $sequence['last'];
            $short = basename(str_replace('"', '', $sequence['sequence']));

            if ($maxId === 0) {
                $this->recordPass($table, "{$short}: nothing to import, nothing to advance");

                continue;
            }

            if ($last === null || $last <= $maxId) {
                // Expected before an import. It is only a failure if it is
                // still true afterwards, which is why the import must call
                // setval; this is the warning that says so.
                $this->recordWarning($table, "{$short}: stands at ".($last ?? 'unused').", must be advanced to {$maxId} after the import");

                continue;
            }

            $this->recordPass($table, "{$short}: already past the imported ids (at {$last}, max {$maxId})");
        }
    }

    /* Type families ------------------------------------------------------ */

    private function familyFromMysql(array $column): string
    {
        $type = (string) $column['data_type'];
        $full = (string) $column['column_type'];

        if (str_starts_with($full, 'tinyint(1)')) {
            return 'bool';
        }

        return match ($type) {
            'tinyint', 'smallint', 'mediumint', 'int', 'integer' => 'int',
            'bigint' => 'bigint',
            'decimal', 'numeric' => 'decimal',
            'float', 'double' => 'float',
            'char', 'varchar' => 'string',
            'tinytext', 'text', 'mediumtext', 'longtext' => 'text',
            'json' => 'json',
            'date' => 'date',
            'datetime', 'timestamp' => 'datetime',
            'time' => 'time',
            'year' => 'int',
            'binary', 'varbinary', 'blob', 'longblob', 'mediumblob', 'tinyblob' => 'binary',
            'enum', 'set' => 'string',
            default => $type,
        };
    }

    private function familyFromPgsql(array $column): string
    {
        return match ((string) $column['data_type']) {
            'smallint', 'integer' => 'int',
            'bigint' => 'bigint',
            'numeric' => 'decimal',
            'real', 'double precision' => 'float',
            'character', 'character varying' => 'string',
            'text' => 'text',
            'json', 'jsonb' => 'json',
            'boolean' => 'bool',
            'date' => 'date',
            'timestamp without time zone', 'timestamp with time zone' => 'datetime',
            'time without time zone', 'time with time zone' => 'time',
            'bytea' => 'binary',
            'USER-DEFINED' => (string) ($column['udt_name'] ?? 'user-defined'),
            default => (string) $column['data_type'],
        };
    }

    /**
     * Whether a difference in family is one the import can bridge.
     *
     * Widening is safe; narrowing is measured elsewhere. A string and a
     * text column hold the same characters. Everything else is refused,
     * because a silent conversion is how a decimal becomes a float and
     * a peso disappears.
     */
    private function compatible(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        $allowed = [
            'int' => ['bigint', 'decimal'],
            'bigint' => ['decimal'],
            'string' => ['text'],
            'text' => ['string', 'json'],
            'bool' => ['int', 'bigint'],
            'json' => ['text'],
            'datetime' => ['date'],
        ];

        return in_array($to, $allowed[$from] ?? [], true);
    }

    /* Reporting ---------------------------------------------------------- */

    /** @param array<string, list<string>> $constraints @return list<string> */
    private function signatures(array $constraints): array
    {
        $out = [];

        foreach ($constraints as $columns) {
            sort($columns);
            $out[] = implode('+', $columns);
        }

        sort($out);

        return array_values(array_unique($out));
    }

    /** @param list<array<string, string>> $keys @return list<string> */
    private function fkSignatures(array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            $out[] = $key['column'].'->'.$key['table'].'.'.$key['references'];
        }

        sort($out);

        return array_values(array_unique($out));
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->info($title);
        $this->line(str_repeat('-', 64));
    }

    private function recordPass(string $table, string $message): void
    {
        $this->passCount++;
        $this->findings[] = ['PASS', $table, $message];
        $this->line("  <fg=green>PASS</>    {$table}: {$message}");
    }

    private function recordWarning(string $table, string $message): void
    {
        $this->warnCount++;
        $this->findings[] = ['WARNING', $table, $message];
        $this->line("  <fg=yellow>WARNING</> {$table}: {$message}");
    }

    private function recordFail(string $table, string $message): void
    {
        $this->failCount++;
        $this->findings[] = ['FAIL', $table, $message];
        $this->line("  <fg=red>FAIL</>    {$table}: {$message}");
    }

    private function report(): int
    {
        $this->newLine();
        $this->info('VALIDATION SUMMARY');
        $this->line(str_repeat('=', 64));

        $this->table(['Status', 'Count'], [
            ['PASS', $this->passCount],
            ['WARNING', $this->warnCount],
            ['FAIL', $this->failCount],
        ]);

        if ($this->failCount > 0) {
            $this->newLine();
            $this->error('Failures that must be resolved before importing:');

            foreach ($this->findings as [$status, $table, $message]) {
                if ($status === 'FAIL') {
                    $this->line("  - {$table}: {$message}");
                }
            }
        }

        $this->newLine();
        $this->warn('No records or schemas were modified by this command.');

        if ($this->failCount > 0) {
            $this->error('NOT READY: resolve the failures above first.');

            return self::FAILURE;
        }

        $this->info($this->warnCount > 0
            ? 'READY, with warnings. Read them before running the import.'
            : 'READY. No failures and no warnings.');

        return self::SUCCESS;
    }
}

