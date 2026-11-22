<?php

namespace App\Http\Controllers;

use App\Services\Credits;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CreditController extends Controller
{
    /** The "Buy credits" button. No payment is taken: this is a pretend purchase. */
    public function store(Request $request, Credits $credits): RedirectResponse
    {
        $validated = $request->validate([
            'dollars' => ['required', 'integer', Rule::in(config('pixelforge.credit_packs'))],
        ]);

        $credits->purchase($this->currentUser($request), $validated['dollars'] * Money::NANOS_PER_DOLLAR);

        return back();
    }
}
