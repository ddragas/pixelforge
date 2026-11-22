<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Everything the application reads from or writes to the generations table.
 */
class GenerationRepository
{
    /** @param  array<string, mixed>  $attributes */
    public function create(User $user, array $attributes): Generation
    {
        return $user->generations()->create($attributes);
    }

    public function save(Generation $generation): void
    {
        $generation->save();
    }

    /** The generation as the database has it now. */
    public function fresh(Generation $generation): Generation
    {
        return $generation->refresh();
    }

    /** Read the generation afresh and hold its row until the transaction ends. */
    public function lock(Generation $generation): Generation
    {
        return Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Find a generation by Pixelforge's id or, failing that, by our own id,
     * and hold its row until the transaction ends.
     *
     * Both are needed: a webhook can arrive before the answer to our own
     * create request has told us Pixelforge's id.
     */
    public function lockByProviderIdOrOwnId(string $providerId, ?string $ownId): ?Generation
    {
        return Generation::query()
            ->where(fn ($query) => $query
                ->where('provider_id', $providerId)
                ->when($ownId, fn ($query) => $query->orWhere('id', $ownId)))
            ->lockForUpdate()
            ->first();
    }

    /** Nanodollars set aside for the user's generations that are still running. */
    public function reservedFor(User $user): int
    {
        return (int) $user->generations()->unfinished()->sum('reserved');
    }

    public function hasUnfinished(User $user): bool
    {
        return $user->generations()->unfinished()->exists();
    }

    /** @return Collection<int, Generation> */
    public function latestFor(User $user, int $limit): Collection
    {
        return $user->generations()->latest()->limit($limit)->get();
    }

    /** @return LengthAwarePaginator<int, Generation> */
    public function pageFor(User $user, int $perPage): LengthAwarePaginator
    {
        return $user->generations()->latest()->paginate($perPage);
    }

    /**
     * Generations Pixelforge has accepted but that we have heard nothing
     * about for a while, longest silence first.
     *
     * @return Collection<int, Generation>
     */
    public function stale(int $silentForSeconds, int $limit): Collection
    {
        return Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where('checked_at', '<', now()->subSeconds($silentForSeconds))
            ->oldest('checked_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Finished, paid-for generations completed since the given moment, with their users.
     *
     * @return Collection<int, Generation>
     */
    public function succeededSince(DateTimeInterface $since): Collection
    {
        return Generation::query()
            ->with('user:id,name,email')
            ->where('status', GenerationStatus::Succeeded)
            ->where('completed_at', '>=', $since)
            ->get(['id', 'user_id', 'provider_cost', 'charged', 'completed_at', 'updated_at']);
    }
}
