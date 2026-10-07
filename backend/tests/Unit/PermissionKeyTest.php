<?php

namespace Tests\Unit;

use App\Service\PermissionService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PermissionKeyTest extends TestCase
{
    public function test_valid_keys_are_split_into_module_and_action(): void
    {
        $service = new PermissionService('/dev/null');

        $this->assertSame(['users', 'view'], $service->splitKey('users.view'));
        $this->assertSame(['kanban', 'move.card'], $service->splitKey('kanban.move.card'));
        $this->assertSame(['audit-logs', 'view'], $service->splitKey('audit-logs.view'));
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_keys_are_rejected(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PermissionService('/dev/null'))->splitKey($key);
    }

    public static function invalidKeys(): array
    {
        return [['users'], ['Users.view'], ['users.'], ['.view'], ['users view.x'], ['users.view;drop']];
    }
}
