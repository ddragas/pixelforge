<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property int $amount
 * @property int $balance_after
 * @property string|null $generation_id
 */
class CreditTransaction extends Model
{
    public const PURCHASE = 'purchase';

    public const GENERATION = 'generation';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
