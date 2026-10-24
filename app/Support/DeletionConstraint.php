<?php

namespace App\Support;

use Illuminate\Database\QueryException;

class DeletionConstraint
{
    public static function isReferenced(QueryException $exception, string $table): bool
    {
        $state = $exception->errorInfo[0] ?? null;
        $code = (int) ($exception->errorInfo[1] ?? 0);
        $foreignKeyViolation = $state === '23503'
            || ($state === '23000' && ($code === 1451
                || (in_array($code, [19, 787], true) && str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed'))));

        return $foreignKeyViolation
            && preg_match('/^delete\s+from\s+[`"\[]?'.preg_quote($table, '/').'\b/i', $exception->getSql()) === 1;
    }
}
