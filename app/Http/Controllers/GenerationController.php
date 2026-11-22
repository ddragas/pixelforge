<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGeneration;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\Pricing;
use App\Services\GenerationOrders;
use App\Services\InsufficientCredit;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly GenerationOrders $orders,
    ) {}

    /** The page with the form. */
    public function create(Request $request): Response
    {
        // Fetch the prices now, so the first price the user sees does not wait for Pixelforge.
        rescue(fn (): Pricing => $this->pixelforge->pricing(), report: false);

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
        $estimate = $this->orders->estimate(
            $request->validated('prompt'),
            $request->validated('size'),
            $request->validated('quality'),
            count($request->referenceImages()),
        );

        return response()->json([
            'lowest' => Money::toDecimal($estimate->lowest),
            'expected' => Money::toDecimal($estimate->expected),
            'highest' => Money::toDecimal($estimate->highest),
            'affordable' => $this->orders->affordable($this->currentUser($request), $estimate),
        ]);
    }

    public function store(GenerationRequest $request): RedirectResponse
    {
        try {
            $generation = $this->orders->place(
                $this->currentUser($request),
                $request->validated('prompt'),
                $request->validated('size'),
                $request->validated('quality'),
                $request->referenceImages(),
            );
        } catch (InsufficientCredit $exception) {
            throw ValidationException::withMessages([
                'credit' => 'You need at least $'.Money::toDecimal($exception->needed).' of free credit for this image. Buy credits to continue.',
            ]);
        }

        dispatch(new SubmitGeneration($generation));

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
}
