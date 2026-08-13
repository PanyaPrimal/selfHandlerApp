<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationSettings extends Model
{
    use HasFactory, UserOwned;

    public const CATEGORIES = ['routine', 'storage', 'habit', 'sleep', 'workout', 'supplement', 'finance'];

    public const CHANNELS = ['in_app'];

    public const SNOOZE_MINUTES = [15, 60, 240, 1440];

    protected $table = 'notification_settings';

    protected $fillable = [
        'user_id',
        'quiet_hours_enabled',
        'quiet_starts_at',
        'quiet_ends_at',
        'digest_enabled',
        'digest_time',
        'categories',
    ];

    protected function casts(): array
    {
        return [
            'quiet_hours_enabled' => 'boolean',
            'digest_enabled' => 'boolean',
            'categories' => 'array',
        ];
    }

    public static function defaults(): array
    {
        return [
            'quiet_hours_enabled' => true,
            'quiet_starts_at' => '23:00',
            'quiet_ends_at' => '08:00',
            'digest_enabled' => true,
            'digest_time' => '08:00',
            'categories' => [
                'routine' => true, 'storage' => true, 'habit' => true, 'sleep' => true, 'workout' => true,
                'supplement' => true,
                'finance' => true,
            ],
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function quietStartsAt(): string
    {
        return substr((string) $this->quiet_starts_at, 0, 5);
    }

    public function quietEndsAt(): string
    {
        return substr((string) $this->quiet_ends_at, 0, 5);
    }

    public function digestTime(): string
    {
        return substr((string) $this->digest_time, 0, 5);
    }

    /** @return array{routine: bool, storage: bool, habit: bool, sleep: bool, workout: bool, supplement: bool, finance: bool} */
    public function categorySettings(): array
    {
        $settings = array_replace(self::defaults()['categories'], $this->categories ?? []);

        return [
            'routine' => (bool) $settings['routine'],
            'storage' => (bool) $settings['storage'],
            'habit' => (bool) $settings['habit'],
            'sleep' => (bool) $settings['sleep'],
            'workout' => (bool) $settings['workout'],
            'supplement' => (bool) $settings['supplement'],
            'finance' => (bool) $settings['finance'],
        ];
    }

    public function categoryEnabled(string $category): bool
    {
        if ($category === InAppNotification::CATEGORY_DIGEST) {
            return $this->digest_enabled;
        }

        return $this->categorySettings()[$category] ?? false;
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'quiet_hours' => [
                'enabled' => $this->quiet_hours_enabled,
                'starts_at' => $this->quietStartsAt(),
                'ends_at' => $this->quietEndsAt(),
            ],
            'digest' => [
                'enabled' => $this->digest_enabled,
                'time' => $this->digestTime(),
            ],
            'categories' => $this->categorySettings(),
        ];
    }
}
