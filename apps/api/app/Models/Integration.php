<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Integration extends Model
{
    use HasFactory, UserOwned;

    public const PROVIDER_GOOGLE = 'google_calendar';

    public const PROVIDER_APPLE = 'apple_calendar';

    public const PROVIDERS = [self::PROVIDER_GOOGLE, self::PROVIDER_APPLE];

    public const KIND_CALENDAR = 'calendar';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_EXPIRED, self::STATUS_REVOKED];

    public const IMPORT_BUSY_ONLY = 'busy_only';

    public const IMPORT_TITLE = 'title';

    public const IMPORT_DETAILS = [self::IMPORT_BUSY_ONLY, self::IMPORT_TITLE];

    public const EXPORT_TIME_BLOCK = 'time_block';

    public const EXPORT_ROUTINE = 'routine';

    public const EXPORT_SLEEP = 'sleep';

    public const EXPORT_HABIT = 'habit';

    public const EXPORT_WORKOUT = 'workout';

    public const EXPORT_SUPPLEMENT = 'supplement';

    public const EXPORT_FINANCE = 'finance';

    public const EXPORT_CATEGORIES = [
        self::EXPORT_TIME_BLOCK, self::EXPORT_ROUTINE, self::EXPORT_SLEEP, self::EXPORT_HABIT,
        self::EXPORT_WORKOUT, self::EXPORT_SUPPLEMENT, self::EXPORT_FINANCE,
    ];

    protected $fillable = [
        'user_id', 'provider', 'kind', 'status', 'external_account_id', 'external_account_label',
        'external_calendar_id', 'external_calendar_name', 'access_token', 'refresh_token', 'secret',
        'token_expires_at', 'sync_cursor', 'settings', 'last_sync_at', 'last_success_at', 'last_error_code',
    ];

    protected $hidden = [
        'user_id', 'external_account_id', 'external_account_label', 'external_calendar_id',
        'access_token', 'refresh_token', 'secret', 'sync_cursor',
    ];

    protected static function booted(): void
    {
        static::creating(function (Integration $integration): void {
            $integration->kind ??= self::KIND_CALENDAR;
            $integration->status ??= self::STATUS_PENDING;
            $integration->settings = self::normalizeSettings($integration->settings);
            self::assertContract($integration);
        });
        static::saving(function (Integration $integration): void {
            if ($integration->exists) {
                $integration->settings = self::normalizeSettings($integration->settings);
                self::assertContract($integration);
            }
        });
    }

    /** @return array{import_detail:string,export_categories:list<string>,calendar_writable:bool,calendar_timezone:?string} */
    public static function defaultSettings(): array
    {
        return [
            'import_detail' => self::IMPORT_BUSY_ONLY,
            'export_categories' => [],
            'calendar_writable' => false,
            'calendar_timezone' => null,
        ];
    }

    /** @return array{import_detail:string,export_categories:list<string>,calendar_writable:bool,calendar_timezone:?string} */
    public static function normalizeSettings(mixed $value): array
    {
        $settings = is_array($value) ? [...self::defaultSettings(), ...$value] : self::defaultSettings();
        $categories = array_values(array_unique(array_filter(
            is_array($settings['export_categories'] ?? null) ? $settings['export_categories'] : [],
            static fn (mixed $category): bool => is_string($category) && in_array($category, self::EXPORT_CATEGORIES, true),
        )));
        sort($categories);

        return [
            'import_detail' => in_array($settings['import_detail'] ?? null, self::IMPORT_DETAILS, true)
                ? $settings['import_detail'] : self::IMPORT_BUSY_ONLY,
            'export_categories' => $categories,
            'calendar_writable' => (bool) ($settings['calendar_writable'] ?? false),
            'calendar_timezone' => is_string($settings['calendar_timezone'] ?? null)
                && $settings['calendar_timezone'] !== '' ? $settings['calendar_timezone'] : null,
        ];
    }

    private static function assertContract(Integration $integration): void
    {
        if (! in_array($integration->provider, self::PROVIDERS, true)
            || $integration->kind !== self::KIND_CALENDAR
            || ! in_array($integration->status, self::STATUSES, true)) {
            throw new LogicException('Integration is outside the supported calendar contract.');
        }
    }

    protected function casts(): array
    {
        return [
            'external_account_label' => 'encrypted',
            'external_calendar_id' => 'encrypted',
            'external_calendar_name' => 'encrypted',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'secret' => 'encrypted',
            'sync_cursor' => 'encrypted',
            'settings' => 'array',
            'token_expires_at' => 'immutable_datetime',
            'last_sync_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
        ];
    }

    public function externalEvents(): HasMany
    {
        return $this->hasMany(ExternalCalendarEvent::class);
    }

    public function syncedItems(): HasMany
    {
        return $this->hasMany(SyncedItem::class);
    }
}
