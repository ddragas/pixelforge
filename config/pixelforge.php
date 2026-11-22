<?php

return [

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    // One key per environment: each has its own webhook URL, signing secret and usage report.
    'api_key' => env('PIXELFORGE_API_KEY'),
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Seconds a signed webhook stays acceptable, to stop replays.
    'webhook_tolerance' => 300,

    // What we add on top of the provider's cost, in percent.
    'markup_percent' => 25,

    // How long a generation may wait to be accepted by Pixelforge before we give up on it.
    'submit_within_minutes' => 15,

    'sizes' => ['1024x1024', '1536x1024', '1024x1536'],
    'qualities' => ['low', 'medium', 'high'],
    'max_reference_images' => 4,
    'max_prompt_length' => 4000,

    // Fake purchases offered by the "Buy credits" button, in whole dollars.
    'credit_packs' => [5, 20, 50],

];
