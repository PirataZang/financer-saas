<?php

namespace App\Attributes;

use Attribute;

/**
 * Declares the permission required to call a controller action (or every action of the
 * controller when placed on the class). Enforced by App\Http\Middleware\CheckPermission.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Permission
{
    public function __construct(
        public string $key,
    ) {
    }
}
