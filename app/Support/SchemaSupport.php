<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

class SchemaSupport
{
    private static array $columns = [];

    private static array $tables = [];

    public static function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;

        if (!array_key_exists($key, self::$columns)) {
            try {
                self::$columns[$key] = Schema::hasColumn($table, $column);
            } catch (\Throwable) {
                self::$columns[$key] = false;
            }
        }

        return self::$columns[$key];
    }

    public static function hasTable(string $table): bool
    {
        if (!array_key_exists($table, self::$tables)) {
            try {
                self::$tables[$table] = Schema::hasTable($table);
            } catch (\Throwable) {
                self::$tables[$table] = false;
            }
        }

        return self::$tables[$table];
    }

    public static function flush(): void
    {
        self::$columns = [];
        self::$tables = [];
    }
}
