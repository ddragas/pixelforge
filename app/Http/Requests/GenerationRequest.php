<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a user may ask for. The same rules serve the cost preview and the
 * real submission, so the price shown is always for a request we would send.
 */
class GenerationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:'.config('pixelforge.max_prompt_length')],
            'size' => ['required', Rule::in(config('pixelforge.sizes'))],
            'quality' => ['required', Rule::in(config('pixelforge.qualities'))],
            'reference_images' => ['array', 'max:'.config('pixelforge.max_reference_images')],
            'reference_images.*' => ['required', 'url:http,https', 'max:2000'],
        ];
    }

    /** @return list<string> */
    public function referenceImages(): array
    {
        return array_values($this->validated('reference_images', []));
    }
}
