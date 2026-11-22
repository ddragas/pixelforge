<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A user's credit, in nanodollars. Every change locks the user's row first,
 * and leaves a ledger line, so the balance can always be explained.
 */
class Credits
{
    /** A fake purchase: credit appears, no payment is taken. */
    public function purchase(User $user, int $amount): void
    {
        DB::transaction(function () use ($user, $amount) {
            $this->move($this->lock($user), CreditTransaction::PURCHASE, $amount);
        });
    }

    /**
     * Take what a finished generation cost. Call inside the transaction that
     * settles the generation. The unique index on generation_id is the last
     * line of defence against charging the same generation twice.
     */
    public function charge(Generation $generation, int $amount): void
    {
        $this->move($this->lock($generation->user), CreditTransaction::GENERATION, -$amount, $generation);
    }

    /** Balance minus what is set aside for generations still running. */
    public function available(User $user): int
    {
        return $user->balance - (int) $user->generations()->unfinished()->sum('reserved');
    }

    public function lock(User $user): User
    {
        return User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    private function move(User $user, string $type, int $amount, ?Generation $generation = null): void
    {
        $user->balance += $amount;
        $user->save();

        $user->creditTransactions()->create([
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $user->balance,
            'generation_id' => $generation?->getKey(),
        ]);
    }
}
