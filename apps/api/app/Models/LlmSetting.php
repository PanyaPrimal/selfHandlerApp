<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LlmSetting extends Model
{
    use UserOwned;

    protected $fillable = ['user_id', 'active_connection_id'];

    protected static function booted(): void
    {
        static::saving(function (LlmSetting $setting): void {
            if ($setting->active_connection_id === null) {
                return;
            }
            $valid = LlmConnection::query()
                ->whereKey($setting->active_connection_id)
                ->where('user_id', $setting->user_id)
                ->where('status', LlmConnection::STATUS_READY)
                ->exists();
            if (! $valid) {
                throw new LogicException('Active LLM connection must be ready and have the same owner.');
            }
        });
    }

    public function activeConnection(): BelongsTo
    {
        return $this->belongsTo(LlmConnection::class, 'active_connection_id');
    }
}
