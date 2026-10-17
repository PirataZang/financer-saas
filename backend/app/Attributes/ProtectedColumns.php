<?php

namespace App\Attributes;

use Attribute;

/**
 * Sensitive columns of a model (see App\Models\Model). Example:
 * #[ProtectedColumns(['token', 'master'], locked: ['master'])]
 *
 * - columns: never serialized, never mass-assigned, never audited; backend code writes them with
 *   forceFill() and may expose one only on purpose with makeVisible().
 * - locked: also immutable during HTTP requests, by any code path — only the database or the
 *   console (seeders, artisan, tinker) changes them.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ProtectedColumns
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $locked
     */
    public function __construct(
        public array $columns = [],
        public array $locked = [],
    ) {
    }
}
