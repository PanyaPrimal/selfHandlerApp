<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\ProfileDefaults;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: static fn (mixed $value): string => self::normalizeEmail($value),
        );
    }

    public static function normalizeEmail(mixed $value): string
    {
        return Str::lower(trim((string) $value));
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function routines(): HasMany
    {
        return $this->hasMany(Routine::class);
    }

    public function routineLogs(): HasMany
    {
        return $this->hasMany(RoutineLog::class);
    }

    public function dailyReviews(): HasMany
    {
        return $this->hasMany(DailyReview::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function ensureProfile(): UserProfile
    {
        if ($this->relationLoaded('profile') && $this->profile) {
            return $this->profile;
        }

        $profile = $this->profile()->firstOrCreate([], ProfileDefaults::attributes());
        $this->setRelation('profile', $profile);

        return $profile;
    }

    public function calendarTimezone(): string
    {
        return $this->ensureProfile()->timezone;
    }
}
