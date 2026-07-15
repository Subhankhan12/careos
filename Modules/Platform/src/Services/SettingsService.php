<?php

namespace Modules\Platform\Services;

use Modules\Platform\Models\Setting;

/**
 * Typed per-tenant settings with platform defaults.
 *
 * Values are stored as JSON on {@see Setting} (native types round-trip); the
 * `type` is used to coerce on read. Market-pack defaults (Swiss / EU-generic)
 * arrive later — here we ship only the mechanism plus a few platform defaults.
 *
 * Reads/writes are tenant-scoped by BelongsToTenant (fail-closed without a
 * tenant context).
 */
class SettingsService
{
    /**
     * Platform-level defaults: key => [value, type].
     *
     * @var array<string, array{value: mixed, type: string}>
     */
    public const DEFAULTS = [
        'locale' => ['value' => 'en', 'type' => 'string'],
        'timezone' => ['value' => 'UTC', 'type' => 'string'],
        'currency' => ['value' => 'EUR', 'type' => 'string'],
        'nursing.visit.gps_privacy_notice' => [
            'value' => 'Location is captured only at check-in and check-out; no continuous tracking, no background location, and no route capture.',
            'type' => 'string',
        ],
    ];

    public function get(string $key, mixed $default = null): mixed
    {
        $setting = Setting::query()->where('key', $key)->first();

        if ($setting !== null) {
            return $this->coerce($setting->value, $setting->type);
        }

        if (array_key_exists($key, self::DEFAULTS)) {
            return $this->coerce(self::DEFAULTS[$key]['value'], self::DEFAULTS[$key]['type']);
        }

        return $default;
    }

    public function set(string $key, mixed $value, ?string $type = null): Setting
    {
        $type ??= $this->inferType($value);

        return Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'type' => $type],
        );
    }

    /**
     * Remove a tenant's override so the key falls back to its platform default
     * (or to the caller's $default when there is none).
     *
     * Distinct from `set($key, null)`: a stored null still coerces on read —
     * `'array'` reads back as `[]`, not null — so a flag cleared by writing null
     * would still look present. Forgetting removes the row.
     */
    public function forget(string $key): void
    {
        Setting::query()->where('key', $key)->delete();
    }

    private function coerce(mixed $value, ?string $type): mixed
    {
        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'array', 'json' => (array) $value,
            'string' => (string) $value,
            default => $value,
        };
    }

    private function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_array($value) => 'array',
            default => 'string',
        };
    }
}
