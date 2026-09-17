<?php

return [
    'timeout' => (int) env('AI_REQUEST_TIMEOUT', 120),
    'image_timeout' => (int) env('AI_IMAGE_REQUEST_TIMEOUT', 180),
    'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 10),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 8192),

    // Custom endpoints must implement the OpenAI Chat Completions API.
    // Match vision to the selected model when overriding a provider's defaults.
    'providers' => [
        'openai' => [
            'text_model' => env('AI_OPENAI_TEXT_MODEL', 'gpt-4.1-mini'),
            'image_model' => env('AI_OPENAI_IMAGE_MODEL', 'gpt-image-2.5-flare'),
            'vision' => env('AI_OPENAI_VISION', true),
        ],
        'openrouter' => [
            'text_model' => env('AI_OPENROUTER_TEXT_MODEL', 'openai/gpt-4.1-mini'),
            'vision' => env('AI_OPENROUTER_VISION', true),
        ],
        'anthropic' => [
            'text_model' => env('AI_ANTHROPIC_TEXT_MODEL', 'claude-haiku-4-5-20251001'),
            'vision' => env('AI_ANTHROPIC_VISION', true),
        ],
        'gemini' => [
            'text_model' => env('AI_GEMINI_TEXT_MODEL', 'gemini-3.8-flash'),
            'image_model' => env('AI_GEMINI_IMAGE_MODEL', 'gemini-3.1-flash-image'),
            'vision' => env('AI_GEMINI_VISION', true),
        ],
        'deepseek' => [
            'text_model' => env('AI_DEEPSEEK_TEXT_MODEL', 'deepseek-flash'),
            'vision' => env('AI_DEEPSEEK_VISION', true),
        ],
        'custom' => [
            'text_model' => env('AI_CUSTOM_TEXT_MODEL'),
            'vision' => env('AI_CUSTOM_VISION', false),
        ],
    ],
];
