<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;

/**
 * The ledger: one line for every change to a balance, never changed afterwards.
 */
class CreditTransactionRepository
{
    /**
     * Write the line for a change that has just been made to the user's balance.
     *
     * generation_id is unique, so the database itself refuses a second
     * charge for the same generation.
     */
    public function record(User $user, string $type, int $amount, ?Generation $generation = null): CreditTransaction
    {
        return $user->creditTransactions()->create([
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $user->balance,
            'generation_id' => $generation?->getKey(),
        ]);
    }
}
