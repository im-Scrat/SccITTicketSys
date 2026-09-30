<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    // SccIT (WP-H): Gemini is this application's only configured provider —
    // OpenAI/Cohere/TypeSafe/etc. below have no key set and are never called.
    // Every call site still passes provider: 'gemini' explicitly, resolved
    // through AiSettings from the ai_models / ai_system_settings tables
    // (never hard-coded) — these two defaults are belt-and-braces only, for
    // the rare call that omits an explicit provider.
    'default' => 'gemini',
    'default_for_images' => 'gemini',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'gemini',
    'default_for_reranking' => 'cohere',
    'default_for_classification' => 'typesafe',

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
            'individually' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],

        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2025-04-01-preview'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
            'image_deployment' => env('AZURE_OPENAI_IMAGE_DEPLOYMENT', 'gpt-image-1'),
            'store' => env('AZURE_OPENAI_STORE', true),
        ],

        'bedrock' => [
            'driver' => 'bedrock',
            'region' => env('AWS_BEDROCK_REGION', 'us-east-1'),
            'key' => env('AWS_BEARER_TOKEN_BEDROCK'),
            'access_key_id' => env('AWS_ACCESS_KEY_ID'),
            'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
            'session_token' => env('AWS_SESSION_TOKEN'),
            'use_default_credential_provider' => env('AWS_USE_DEFAULT_CREDENTIALS', true),
            'assume_role' => [
                'arn' => env('AWS_BEDROCK_ASSUME_ROLE_ARN'),
                'session_name' => env('AWS_BEDROCK_ASSUME_ROLE_SESSION_NAME'),
                'duration_seconds' => env('AWS_BEDROCK_ASSUME_ROLE_DURATION_SECONDS'),
                'external_id' => env('AWS_BEDROCK_ASSUME_ROLE_EXTERNAL_ID'),
            ],
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            // Deliberately NOT env('GEMINI_API_KEY'): production keeps the key
            // out of environment variables and out of `config:cache`. The real
            // value is applied at runtime by GeminiCredential::apply() from a
            // secret file (and, in local development only, from GEMINI_API_KEY).
            'key' => null,
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => env('OPENAI_STORE', true),
        ],

        'openai-compatible' => [
            'driver' => 'openai-compatible',
            'url' => env('OPENAI_COMPATIBLE_URL'),
            'key' => env('OPENAI_COMPATIBLE_API_KEY'),
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],

        'typesafe' => [
            'driver' => 'typesafe',
            'key' => env('TYPESAFE_API_KEY'),
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SccIT application settings (WP-H)
    |--------------------------------------------------------------------------
    |
    | Not part of the laravel/ai package's own config schema — kept in its own
    | top-level key so a future `vendor:publish --tag=ai-config --force` shows
    | a clean diff against everything above instead of silently reappearing
    | inside it. WHICH model to call is never read from here: that is always
    | resolved at call time from the `ai_models` / `ai_system_settings` tables
    | via App\Domains\KnowledgeBase\Services\AiSettings. This section only
    | holds safety-net numbers that apply regardless of which model is active.
    |
    */

    'sccit' => [
        // Hard ceiling on a single agent invocation, in seconds. Passed as
        // Promptable::prompt()'s $timeout argument. A prompt that would
        // otherwise hang indefinitely against a provider outage is cut short
        // instead of tying up a request or queue worker.
        'agent_timeout_seconds' => (int) env('AI_AGENT_TIMEOUT_SECONDS', 30),

        // Upper bound on the untrusted text SccIT will ever send to a
        // provider in one call (ticket bodies, knowledge-article content,
        // etc.). Not a token count — a cheap, pre-request character guard
        // that fails fast on an oversized or pathological input before any
        // network call, cost, or provider-side truncation happens.
        'max_input_chars' => (int) env('AI_MAX_INPUT_CHARS', 20000),

        // WP-O — where the Gemini API key lives in production: the PATH of a
        // Docker Compose secret file, never the key itself. The default is where
        // Compose mounts a secret named `gemini_api_key`. The key is read from
        // this file at runtime by GeminiCredential (not here), so it is never
        // written into the `config:cache` output. See docs/OPERATIONS.md.
        // WP-Q — the assistant's knowledge lookup. Top-k passages, and the cosine
        // floor below which a passage is not offered as context at all (an
        // irrelevant passage is worse than none: the model is told to rely on it).
        // Tunable without a deploy once real Gemini embeddings are in use.
        'assistant_top_k' => (int) env('AI_ASSISTANT_TOP_K', 4),
        'assistant_min_similarity' => (float) env('AI_ASSISTANT_MIN_SIMILARITY', 0.55),

        'gemini_key_file' => env('GEMINI_API_KEY_FILE', '/run/secrets/gemini_api_key'),

        // Local-development convenience only. Null whenever APP_ENV=production,
        // so an inline key can never be written into the production config cache
        // (and GeminiCredential refuses to use one there anyway).
        'gemini_inline_key' => env('APP_ENV') === 'production' ? null : env('GEMINI_API_KEY'),
    ],

];
