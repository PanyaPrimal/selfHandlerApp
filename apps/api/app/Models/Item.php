<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

/**
 * One captured thing: a task or an idea.
 *
 * A single table plus `type`, per `docs/design/data-conventions.md` section 2.
 * The two types differ in what the user means by them, not in what they store,
 * so there is no detail table to carry.
 *
 * The point of the inbox is that capture costs one field. Everything else here
 * is optional and is filled in later, during triage.
 */
class Item extends Model
{
    use HasFactory, UserOwned;

    public const TYPE_TASK = 'task';

    public const TYPE_IDEA = 'idea';

    public const TYPE_PURCHASE = 'purchase';

    public const STATUS_INBOX = 'inbox';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DONE = 'done';

    public const STATUS_DROPPED = 'dropped';

    /** @var list<string> */
    public const TYPES = [self::TYPE_TASK, self::TYPE_IDEA, self::TYPE_PURCHASE];

    /** @var list<string> */
    public const STATUSES = [self::STATUS_INBOX, self::STATUS_ACTIVE, self::STATUS_DONE, self::STATUS_DROPPED];

    /** @var list<string> */
    public const PRIORITIES = ['low', 'normal', 'high'];

    /** Statuses that still need attention, and therefore still block a parent. */
    public const OPEN_STATUSES = [self::STATUS_INBOX, self::STATUS_ACTIVE];

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'description',
        'status',
        'priority',
        'estimated_amount',
        'estimated_currency_code',
        'due_on',
        'project_id',
        'parent_id',
        'is_blocker',
    ];

    protected $attributes = [
        'type' => self::TYPE_TASK,
        'status' => self::STATUS_INBOX,
        'is_blocker' => false,
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date:Y-m-d',
            'estimated_amount' => 'decimal:4',
            'is_blocker' => 'boolean',
            'completed_at' => 'datetime',
            'dropped_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Item $item): void {
            if (blank($item->user_id)) {
                return;
            }

            // A relationship that crosses accounts would leak one user's work
            // into another's list, so it is refused at the model rather than
            // trusted to every controller.
            $item->assertSameOwner(Project::class, $item->project_id, 'project');
            $item->assertSameOwner(Item::class, $item->parent_id, 'parent');

            $hasAmount = $item->estimated_amount !== null;
            $hasCurrency = $item->estimated_currency_code !== null;
            if ($hasAmount !== $hasCurrency
                || ($hasAmount && ($item->type !== self::TYPE_PURCHASE
                    || bccomp((string) $item->estimated_amount, '0', 4) <= 0))) {
                throw new RuntimeException('A purchase estimate must be one positive Money pair.');
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Item::class, 'parent_id');
    }

    public function purchaseDebt(): HasOne
    {
        return $this->hasOne(FinanceDebt::class, 'purchase_item_id');
    }

    public function purchaseTransactions(): HasMany
    {
        return $this->hasMany(FinanceTransactionGroup::class, 'source_id')
            ->where('source_type', FinanceTransactionGroup::SOURCE_PURCHASE_ITEM);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withPivot('user_id')->withTimestamps();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Apply a status change and derive its timestamp on the server.
     *
     * The client never supplies these: a completion time it chose would be a
     * claim rather than a record.
     */
    public function applyStatus(string $status): void
    {
        $this->status = $status;
        $this->completed_at = $status === self::STATUS_DONE ? ($this->completed_at ?? now()) : null;
        $this->dropped_at = $status === self::STATUS_DROPPED ? ($this->dropped_at ?? now()) : null;
    }

    /**
     * @param  class-string<Model>  $related
     */
    private function assertSameOwner(string $related, mixed $key, string $label): void
    {
        if (blank($key)) {
            return;
        }

        $ownerId = $related::query()->whereKey($key)->value('user_id');

        if ((int) $ownerId !== (int) $this->user_id) {
            throw new RuntimeException("An item's {$label} must have the same owner as the item.");
        }
    }
}
