<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Repositories\CreditTransactionRepository;
use App\Repositories\GenerationRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;

/**
 * A user's credit, in nanodollars. Every change locks the user's row first,
 * and leaves a ledger line, so the balance can always be explained.
 */
class Credits
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly GenerationRepository $generations,
        private readonly CreditTransactionRepository $ledger,
    ) {}

    /** A fake purchase: credit appears, no payment is taken. */
    public function purchase(User $user, int $amount): void
    {
        DB::transaction(function () use ($user, $amount): void {
            $this->move($this->users->lock($user), CreditTransaction::PURCHASE, $amount);
        });
    }

    /**
     * Take what a finished generation cost. Call inside the transaction that
     * settles the generation. The unique index on generation_id is the last
     * line of defence against charging the same generation twice.
     */
    public function charge(Generation $generation, int $amount): void
    {
        $this->move($this->users->lock($generation->user_id), CreditTransaction::GENERATION, -$amount, $generation);
    }

    public function balance(User $user): int
    {
        return $this->users->balance($user);
    }

    /** Balance minus what is set aside for generations still running. */
    public function available(User $user): int
    {
        return $this->users->balance($user) - $this->generations->reservedFor($user);
    }

    /** Hold the user's row until the transaction ends; see UserRepository::lock(). */
    public function lock(User $user): User
    {
        return $this->users->lock($user);
    }

    private function move(User $user, string $type, int $amount, ?Generation $generation = null): void
    {
        $user->balance += $amount;

        $this->users->save($user);
        $this->ledger->record($user, $type, $amount, $generation);
    }
}
