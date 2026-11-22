<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;

/**
 * Everything the application reads from or writes to the users table,
 * apart from signing in and registering, which the starter kit does.
 */
class UserRepository
{
    /**
     * Read the user afresh and hold their row until the transaction ends.
     * Whoever changes a balance does this first, so two changes at once
     * happen one after the other rather than on top of each other.
     */
    public function lock(User|int $user): User
    {
        return User::query()->whereKey($user instanceof User ? $user->getKey() : $user)->lockForUpdate()->firstOrFail();
    }

    /** The balance as the database has it now, not as a model loaded earlier remembers it. */
    public function balance(User $user): int
    {
        return (int) User::query()->whereKey($user->getKey())->value('balance');
    }

    public function save(User $user): void
    {
        $user->save();
    }

    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    public function makeOperator(User $user): void
    {
        $user->forceFill(['is_operator' => true])->save();
    }
}
