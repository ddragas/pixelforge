<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Pixelforge;
use Tests\TestCase;

class GenerateTest extends TestCase
{
    use Pixelforge, RefreshDatabase;

    /** @var array<string, mixed> */
    private array $request = [
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPixelforge();
        $this->fakePricing();
        Queue::fake();
    }

    public function test_the_cost_is_shown_before_submitting_as_a_range(): void
    {
        $user = User::factory()->create(['balance' => 5_000_000_000]);

        $this->actingAs($user)->postJson('/generations/estimate', $this->request)
            ->assertOk()
            ->assertExactJson([
                // 45 characters -> 12 text tokens (10 to 14), one reference image, 6,450 output tokens, 1,000 to 8,000 reasoning tokens.
                'lowest' => '0.347750000',
                'expected' => '0.402450000',
                'highest' => '0.457150000',
                'affordable' => true,
            ]);
    }

    public function test_submitting_sets_aside_the_most_it_can_cost_and_queues_the_request(): void
    {
        $user = User::factory()->create(['balance' => 5_000_000_000]);

        $this->actingAs($user)->post('/generations', $this->request)->assertRedirect('/history');

        $generation = Generation::sole();

        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertSame(457_150_000, $generation->reserved);
        $this->assertSame(457_150_000, $generation->estimated_highest);
        $this->assertSame(5_000_000_000, $user->refresh()->balance);
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($generation));
    }

    public function test_a_user_who_cannot_afford_the_most_it_can_cost_is_stopped(): void
    {
        $user = User::factory()->create(['balance' => 400_000_000]);

        $this->actingAs($user)->post('/generations', $this->request)->assertSessionHasErrors('credit');

        $this->assertSame(0, Generation::count());
        Queue::assertNothingPushed();
    }

    public function test_credit_set_aside_for_a_running_generation_cannot_be_spent_twice(): void
    {
        $user = User::factory()->create(['balance' => 500_000_000]);

        $this->actingAs($user)->post('/generations', $this->request)->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/generations', $this->request)->assertSessionHasErrors('credit');

        $this->assertSame(1, Generation::count());
    }

    public function test_buying_credits_adds_them_and_leaves_a_ledger_line(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits', ['dollars' => 20])->assertRedirect();

        $this->assertSame(20_000_000_000, $user->refresh()->balance);
        $this->assertDatabaseHas('credit_transactions', ['user_id' => $user->id, 'type' => 'purchase', 'amount' => 20_000_000_000, 'balance_after' => 20_000_000_000]);
    }

    public function test_only_the_offered_packs_can_be_bought(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits', ['dollars' => 1_000_000])->assertSessionHasErrors('dollars');

        $this->assertSame(0, $user->refresh()->balance);
    }

    public function test_an_invalid_request_is_refused_before_it_reaches_pixelforge(): void
    {
        $user = User::factory()->create(['balance' => 5_000_000_000]);

        $this->actingAs($user)->post('/generations', ['prompt' => '', 'size' => '2048x2048', 'quality' => 'ultra', 'reference_images' => ['not a url']])
            ->assertSessionHasErrors(['prompt', 'size', 'quality', 'reference_images.0']);

        $this->assertSame(0, Generation::count());
    }

    public function test_history_shows_only_the_users_own_generations_with_estimate_and_actual_cost(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->queued()->create(['status' => GenerationStatus::Succeeded, 'charged' => 78_487_500, 'provider_cost' => 62_790_000, 'reserved' => 0]);
        Generation::factory()->create();

        $this->actingAs($user)->get('/history')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('History')
            ->has('generations', 1)
            ->where('generations.0.status', 'succeeded')
            ->where('generations.0.charged', '0.078487500')
            ->where('generations.0.estimate.highest', '0.086250000')
            ->where('running', false));
    }

    public function test_guests_cannot_generate(): void
    {
        $this->post('/generations', $this->request)->assertRedirect('/login');
        $this->get('/history')->assertRedirect('/login');
    }
}
