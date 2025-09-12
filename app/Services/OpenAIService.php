<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\RequestException;

class OpenAIService
{
    protected string $apiKey;
    protected array $options = [];

    public function __construct()
    {
        $this->apiKey = config('services.openai.key', env('OPENAI_API_KEY'));

        if (!app()->environment('production')) {
            // developer convenience (mirrors your previous approach)
            $this->options['verify'] = false;
        }
    }

    /**
     * Perform chat completion and return the raw response array.
     *
     * @param string $prompt
     * @param string $model
     * @return array
     * @throws RequestException
     */
    public function chatCompletions(string $prompt, string $model = 'gpt-4o-mini'): array
    {
        if (empty($this->apiKey)) {
            Log::info('OPENAI_API_KEY missing — development fallback used.');
            return $this->devFallback();
        }

        $makeRequest = function ($p) use ($model) {
            $request = Http::withToken($this->apiKey)
                ->timeout(20)
                ->retry(2, 150);

            if (!empty($this->options)) {
                $request = $request->withOptions($this->options);
            }

            $response = $request->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a strict JSON extractor. Output valid JSON only.'],
                    ['role' => 'user', 'content' => $p],
                ],
                'temperature' => 0,
                'max_tokens' => 500,
            ]);

            $response->throw();

            return $response->json();
        };

        try {
            $body = $makeRequest($prompt);

            // If content looks "all-empty" we may want to retry with clarification.
            // But decision to interpret "all-empty" is left to parser (separation of concerns).
            return $body;
        } catch (RequestException $e) {
            Log::error('OpenAI HTTP error', [
                'message' => $e->getMessage(),
                'status' => optional($e->response)->status(),
                'body' => optional($e->response)->body(),
            ]);
            throw $e;
        }
    }

    /**
     * Extract the textual content string from an OpenAI body (safe)
     */
    public function extractContent(array $body): string
    {
        return $body['choices'][0]['message']['content'] ?? '';
    }

    protected function devFallback(): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'first_name' => '',
                            'last_name' => '',
                            'address' => '',
                            'height' => '',
                            'weight' => '',
                            'gender' => '',
                            'age' => ''
                        ])
                    ]
                ]
            ]
        ];
    }
}
