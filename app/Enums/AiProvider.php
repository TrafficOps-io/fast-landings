<?php

namespace App\Enums;

enum AiProvider: string
{
    case OpenAi = 'openai';
    case OpenRouter = 'openrouter';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';
    case DeepSeek = 'deepseek';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::OpenAi => 'OpenAI',
            self::OpenRouter => 'OpenRouter',
            self::Anthropic => 'Anthropic',
            self::Gemini => 'Google Gemini',
            self::DeepSeek => 'DeepSeek',
            self::Custom => 'Other provider',
        };
    }

    public function defaultApiUrl(): ?string
    {
        return match ($this) {
            self::OpenAi => 'https://api.openai.com/v1',
            self::OpenRouter => 'https://openrouter.ai/api/v1',
            self::Anthropic => 'https://api.anthropic.com/v1',
            self::Gemini => 'https://generativelanguage.googleapis.com/v1beta',
            self::DeepSeek => 'https://api.deepseek.com',
            self::Custom => null,
        };
    }
}
