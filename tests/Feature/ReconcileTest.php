<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\Pixelforge;
use Tests\TestCase;

class ReconcileTest extends TestCase
{
    use Pixelforge, RefreshDatabase;

    public function test_a_generation_whose_webhook_never_came_is_settled_by_asking_pixelforge(): void
    {
        $this->setUpPixelforge();

        $user = User::factory()->create(['balance' => 1_000_000_000]);
        $quiet = Generation::factory()->for($user)->queued()->create(['checked_at' => now()->subMinutes(5)]);
        $recent = Generation::factory()->for($user)->queued('gen_02RECENT')->create(['checked_at' => now()]);

        Http::fake(['*/v1/generations/gen_01TEST' => Http::response($this->remoteGeneration('succeeded'))]);

        $this->artisan('pixelforge:reconcile')->assertSuccessful();

        $this->assertSame(GenerationStatus::Succeeded, $quiet->refresh()->status);
        $this->assertSame(78_487_500, $quiet->charged);
        $this->assertSame(GenerationStatus::Queued, $recent->refresh()->status);
        Http::assertSentCount(1);
    }
}
