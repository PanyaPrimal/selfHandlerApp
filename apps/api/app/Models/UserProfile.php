<?php

namespace App\Models;

use App\Support\UserOwned;
use Database\Factories\UserProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    /** @use HasFactory<UserProfileFactory> */
    use HasFactory, UserOwned;

    protected $fillable = [
        'user_id', 'timezone', 'locale', 'unit_system', 'base_currency',
        'date_of_birth', 'sex', 'height_meters', 'weight_grams',
        'body_fat_percentage', 'baseline_activity', 'recommendation_tone',
        'bmr_formula',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'height_meters' => 'decimal:3',
            'weight_grams' => 'integer',
            'body_fat_percentage' => 'decimal:2',
        ];
    }

    /** @return list<string> */
    public function missingCalculationFields(): array
    {
        $required = $this->bmr_formula === 'katch_mcardle'
            ? ['weight_grams', 'body_fat_percentage', 'baseline_activity']
            : ['date_of_birth', 'sex', 'height_meters', 'weight_grams', 'baseline_activity'];

        return array_values(array_filter($required, function (string $field): bool {
            $value = $this->getAttribute($field);

            return $value === null || $value === '' || ($field === 'sex' && $value === 'unspecified');
        }));
    }

    public function calculationReady(): bool
    {
        return $this->missingCalculationFields() === [];
    }
}
