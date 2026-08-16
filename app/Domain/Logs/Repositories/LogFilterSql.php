<?php

namespace App\Domain\Logs\Repositories;

use App\Domain\Logs\Data\LogFilters;

final class LogFilterSql
{
    /**
     * @return array{where: list<string>, bindings: list<string>}
     */
    public function build(LogFilters $filters): array
    {
        $where = [];
        $bindings = [];

        if ($filters->service !== null) {
            $where[] = 'service = ?';
            $bindings[] = $filters->service;
        }

        if ($filters->level !== null) {
            $where[] = 'level = ?';
            $bindings[] = $filters->level;
        }

        if ($filters->since !== null) {
            $where[] = 'event_timestamp >= CAST(? AS timestamptz)';
            $bindings[] = $filters->since;
        }

        if ($filters->until !== null) {
            $where[] = 'event_timestamp < CAST(? AS timestamptz)';
            $bindings[] = $filters->until;
        }

        foreach ($filters->attributes as $key => $value) {
            $where[] = 'attributes_text @> hstore(CAST(? AS text), CAST(? AS text))';
            $bindings[] = $key;
            $bindings[] = $value;
        }

        if ($filters->message !== null) {
            $where[] = <<<'SQL'
                message ILIKE ? ESCAPE E'\\'
                SQL;
            $bindings[] = '%'.strtr($filters->message, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']).'%';
        }

        return ['where' => $where, 'bindings' => $bindings];
    }
}
