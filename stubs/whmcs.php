<?php

declare(strict_types=1);

namespace WHMCS\Config {
    final class Setting
    {
        public static function getValue(string $name): mixed {}
    }
}

namespace WHMCS\Database {
    final class Capsule
    {
        public static function table(string $table): QueryBuilder {}
        public static function schema(): SchemaBuilder {}
        /** @param list<mixed> $bindings */
        public static function selectOne(string $query, array $bindings = []): object {}
    }

    final class QueryBuilder
    {
        public function where(string $column, mixed $value): self {}
        public function first(): object|false|null {}
        /** @param array<string, mixed> $attributes @param array<string, mixed> $values */
        public function updateOrInsert(array $attributes, array $values = []): bool {}
    }

    final class SchemaBuilder
    {
        public function hasTable(string $table): bool {}
        /** @param callable(Blueprint): void $callback */
        public function create(string $table, callable $callback): void {}
    }

    final class Blueprint
    {
        public function string(string $column, ?int $length = null): ColumnDefinition {}
        public function mediumText(string $column): ColumnDefinition {}
        public function unsignedInteger(string $column): ColumnDefinition {}
        public function dateTime(string $column): ColumnDefinition {}
        public function text(string $column): ColumnDefinition {}
    }

    final class ColumnDefinition
    {
        public function primary(): self {}
        public function nullable(): self {}
        public function default(mixed $value): self {}
    }
}

namespace WHMCS\Module {
    abstract class AbstractWidget {}
}

namespace WHMCS\Module\Addon {
    final class Setting
    {
        public static function getSettingValueForModule(string $module, string $setting): mixed {}
    }
}

namespace {
    function add_hook(string $hook, int $priority, callable $callback): void {}
    function check_token(string $namespace): bool {}
    function generate_token(string $type): string {}
    /** @param array<string, mixed> $parameters @return array<string, mixed> */
    function localAPI(string $command, array $parameters): array {}
    function logActivity(string $message): void {}
}
