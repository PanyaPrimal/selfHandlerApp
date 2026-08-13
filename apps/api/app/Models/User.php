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
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

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

    public function sleepPlans(): HasMany
    {
        return $this->hasMany(SleepPlan::class);
    }

    public function sleepLogs(): HasMany
    {
        return $this->hasMany(SleepLog::class);
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(Exercise::class);
    }

    public function workoutPrograms(): HasMany
    {
        return $this->hasMany(WorkoutProgram::class);
    }

    public function workoutSessions(): HasMany
    {
        return $this->hasMany(WorkoutSession::class);
    }

    public function foodItems(): HasMany
    {
        return $this->hasMany(FoodItem::class);
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    public function meals(): HasMany
    {
        return $this->hasMany(Meal::class);
    }

    public function nutritionSettings(): HasOne
    {
        return $this->hasOne(NutritionSettings::class);
    }

    public function nutritionDailyTargets(): HasMany
    {
        return $this->hasMany(NutritionDailyTarget::class);
    }

    public function trainingGoalDetails(): HasMany
    {
        return $this->hasMany(TrainingGoalDetail::class);
    }

    public function dailyReviews(): HasMany
    {
        return $this->hasMany(DailyReview::class);
    }

    public function financeAccounts(): HasMany
    {
        return $this->hasMany(FinanceAccount::class);
    }

    public function financeCategories(): HasMany
    {
        return $this->hasMany(FinanceCategory::class);
    }

    public function financeExchangeRates(): HasMany
    {
        return $this->hasMany(FinanceExchangeRate::class);
    }

    public function financeTransactionGroups(): HasMany
    {
        return $this->hasMany(FinanceTransactionGroup::class);
    }

    public function financeLedgerEntries(): HasMany
    {
        return $this->hasMany(FinanceLedgerEntry::class);
    }

    public function financeBudgetLimits(): HasMany
    {
        return $this->hasMany(FinanceBudgetLimit::class);
    }

    public function financeRecurringOperations(): HasMany
    {
        return $this->hasMany(FinanceRecurringOperation::class);
    }

    public function financeOccurrenceDetails(): HasMany
    {
        return $this->hasMany(FinanceOccurrenceDetail::class);
    }

    public function financeOccurrenceFacts(): HasMany
    {
        return $this->hasMany(FinanceOccurrenceFact::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function notificationSettings(): HasOne
    {
        return $this->hasOne(NotificationSettings::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(InAppNotification::class);
    }

    public function ensureNotificationSettings(): NotificationSettings
    {
        if ($this->relationLoaded('notificationSettings') && $this->notificationSettings) {
            return $this->notificationSettings;
        }

        $settings = $this->notificationSettings()->firstOrCreate([], NotificationSettings::defaults());
        $this->setRelation('notificationSettings', $settings);

        return $settings;
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
