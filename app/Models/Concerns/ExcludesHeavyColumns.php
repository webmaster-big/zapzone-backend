<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

trait ExcludesHeavyColumns
{
    protected static array $lightColumns = [];

    public function scopeWithoutHeavyColumns(Builder $query): Builder
    {
        if (!config('media.exclude_heavy_columns', true)) {
            return $query;
        }

        $table = $this->getTable();

        if (!array_key_exists($table, static::$lightColumns)) {
            static::$lightColumns[$table] = $this->resolveLightColumns($table);
        }

        $columns = static::$lightColumns[$table];
        if ($columns === null) {
            return $query;
        }

        return $query->select(array_map(fn (string $column) => $table . '.' . $column, $columns));
    }

    private function resolveLightColumns(string $table): ?array
    {
        try {
            $all = Schema::connection($this->getConnectionName())->getColumnListing($table);
        } catch (\Throwable $e) {
            return null;
        }

        $heavy = array_map('strtolower', static::HEAVY_COLUMNS);
        $light = array_values(array_filter($all, fn (string $column) => !in_array(strtolower($column), $heavy, true)));

        if ($light === [] || count($light) === count($all)) {
            return null;
        }

        return $light;
    }
}
