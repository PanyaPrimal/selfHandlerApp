<?php

namespace App\Models;

use App\Support\UserOwned;
use App\ValueObjects\BodyMetric;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** One observation of one metric on one calendar day. */
class BodyMeasurement extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = ['user_id', 'metric', 'measured_on', 'value', 'note'];

    protected function casts(): array
    {
        return [
            'metric' => BodyMetric::class,
            'measured_on' => 'date:Y-m-d',
            'value' => 'decimal:4',
        ];
    }
}
