<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Http\Requests\GenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGeneration;
use App\Pixelforge\CostCalculator;
use App\Pixelforge\Estimate;
use App\Pixelforge\PixelforgeClient;
use App\Services\Credits;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function __construct(
        private PixelforgeClient $pixelforge,
        private CostCalculator $calculator,
        private Credits $credits,
    ) {}

    /** The page with the form. */
    public function create(Request $request): Response
    {
        // Fetch the prices now, so the first price the user sees does not wait for Pixelforge.
        rescue(fn () => $this->pixelforge->pricing(), report: false);

        return Inertia::render('Generate', [
            'sizes' => config('pixelforge.sizes'),
            'qualities' => config('pixelforge.qualities'),
            'maxReferenceImages' => config('pixelforge.max_reference_images'),
            'maxPromptLength' => config('pixelforge.max_prompt_length'),
            'creditPacks' => config('pixelforge.credit_packs'),
            'markupPercent' => config('pixelforge.markup_percent'),
            'recent' => GenerationResource::collection($this->currentUser($request)->generations()->latest()->limit(3)->get())->resolve(),
        ]);
    }

    /** What the request in the form would cost, shown before the user submits. */
    public function estimate(GenerationRequest $request): JsonResponse
    {
        $estimate = $this->estimateFor($request);

        return response()->json([
            'lowest' => Money::toDecimal($estimate->lowest),
            'expected' => Money::toDecimal($estimate->expected),
            'highest' => Money::toDecimal($estimate->highest),
            'affordable' => $this->credits->available($this->currentUser($request)) >= $estimate->highest,
        ]);
    }

    public function store(GenerationRequest $request): RedirectResponse
    {
        $estimate = $this->estimateFor($request);

        $generation = DB::transaction(function () use ($request, $estimate) {
            // Locking the user makes two submissions at once queue up here,
            // so both cannot spend the same credit.
            $user = $this->credits->lock($this->currentUser($request));

            if ($this->credits->available($user) < $estimate->highest) {
                throw ValidationException::withMessages([
                    'credit' => 'You need at least $'.Money::toDecimal($estimate->highest).' of free credit for this image. Buy credits to continue.',
                ]);
            }

            // The most it can cost is set aside now and settled to the real cost when it finishes.
            return $user->generations()->create([
                'prompt' => $request->validated('prompt'),
                'size' => $request->validated('size'),
                'quality' => $request->validated('quality'),
                'reference_images' => $request->referenceImages(),
                'status' => GenerationStatus::Pending,
                'estimated_lowest' => $estimate->lowest,
                'estimated_expected' => $estimate->expected,
                'estimated_highest' => $estimate->highest,
                'reserved' => $estimate->highest,
            ]);
        });

        SubmitGeneration::dispatch($generation);

        return to_route('generations.index');
    }

    /** History, with live status: the page asks again every few seconds while anything is running. */
    public function index(Request $request): Response
    {
        $generations = $this->currentUser($request)->generations()->latest()->paginate(12);

        return Inertia::render('History', [
            'generations' => GenerationResource::collection($generations->items())->resolve(),
            'pagination' => [
                'current' => $generations->currentPage(),
                'last' => $generations->lastPage(),
                'total' => $generations->total(),
            ],
            'running' => $this->currentUser($request)->generations()->unfinished()->exists(),
        ]);
    }

    private function estimateFor(GenerationRequest $request): Estimate
    {
        return $this->calculator->estimate(
            $request->validated('prompt'),
            $request->validated('size'),
            $request->validated('quality'),
            count($request->referenceImages()),
            $this->pixelforge->pricing(),
        );
    }
}
