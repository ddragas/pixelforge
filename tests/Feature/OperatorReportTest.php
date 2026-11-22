<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Pixelforge;
use Tests\TestCase;

class OperatorReportTest extends TestCase
{
    use Pixelforge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPixelforge();
        Carbon::setTestNow('2026-11-05 10:00:00');
    }

    public function test_it_shows_charges_provider_cost_and_profit_per_day_and_compares_with_pixelforge(): void
    {
        $operator = User::factory()->create(['is_operator' => true]);
        $customer = User::factory()->create(['name' => 'Ana']);

        Generation::factory()->for($customer)->queued('gen_a')->create([
            'status' => GenerationStatus::Succeeded, 'provider_cost' => 62_790_000, 'charged' => 78_487_500, 'reserved' => 0,
            // Created on the 3rd, finished just after midnight UTC: billed on the 4th.
            'created_at' => '2026-11-03 23:59:50', 'completed_at' => '2026-11-04 00:00:10',
        ]);
        Generation::factory()->for($customer)->queued('gen_b')->create([
            'status' => GenerationStatus::Failed, 'provider_cost' => 0, 'charged' => 0, 'reserved' => 0, 'completed_at' => '2026-11-04 12:00:00',
        ]);

        Http::fake(['*/v1/usage*' => Http::response(['object' => 'usage', 'data' => [
            ['date' => '2026-11-04', 'generations' => 1, 'cost_usd' => '0.062790000'],
        ]])]);

        $this->actingAs($operator)->get('/admin/report')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Report')
            ->has('days', 1)
            ->where('days.0.date', '2026-11-04')
            ->where('days.0.generations', 1)
            ->where('days.0.charged', '0.078487500')
            ->where('days.0.provider_cost', '0.062790000')
            ->where('days.0.profit', '0.015697500')
            ->where('days.0.reported_cost', '0.062790000')
            ->where('days.0.matches', true)
            ->where('days.0.users.0.name', 'Ana')
            ->where('totals.profit', '0.015697500'));
    }

    public function test_a_day_that_differs_from_pixelforges_own_figure_is_flagged(): void
    {
        $operator = User::factory()->create(['is_operator' => true]);

        Generation::factory()->queued('gen_a')->create([
            'status' => GenerationStatus::Succeeded, 'provider_cost' => 62_790_000, 'charged' => 78_487_500, 'reserved' => 0, 'completed_at' => '2026-11-04 09:00:00',
        ]);

        Http::fake(['*/v1/usage*' => Http::response(['object' => 'usage', 'data' => [
            ['date' => '2026-11-04', 'generations' => 2, 'cost_usd' => '0.125580000'],
        ]])]);

        $this->actingAs($operator)->get('/admin/report')->assertInertia(fn (AssertableInertia $page) => $page->where('days.0.matches', false));
    }

    public function test_ordinary_users_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
    }
}
