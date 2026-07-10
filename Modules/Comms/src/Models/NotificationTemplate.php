<?php

namespace Modules\Comms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * Tenant-owned, versioned notification template. The CATEGORY lives on the
 * template — never on the caller — so a sender can never relabel a marketing
 * message as legal to dodge the consent gate (D-G4).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string $channel
 * @property string $locale
 * @property string|null $subject
 * @property string $body
 * @property string $category
 * @property bool $active
 * @property int $version
 */
class NotificationTemplate extends Model
{
    use BelongsToTenant, HasUlids;

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_PORTAL = 'portal';

    public const CATEGORY_TRANSACTIONAL = 'transactional';

    public const CATEGORY_LEGAL = 'legal';

    public const CATEGORY_MARKETING = 'marketing';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'channel',
        'locale',
        'subject',
        'body',
        'category',
        'active',
        'version',
    ];

    protected $attributes = [
        'locale' => 'en',
        'active' => true,
        'version' => 1,
    ];

    protected static function booted(): void
    {
        static::saving(function (NotificationTemplate $template): void {
            if (! in_array($template->channel, [self::CHANNEL_EMAIL, self::CHANNEL_SMS, self::CHANNEL_PORTAL], true)) {
                throw new InvalidArgumentException('Unsupported notification channel.');
            }

            if (! in_array($template->category, [self::CATEGORY_TRANSACTIONAL, self::CATEGORY_LEGAL, self::CATEGORY_MARKETING], true)) {
                throw new InvalidArgumentException('Unsupported notification category.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'version' => 'integer',
        ];
    }
}
