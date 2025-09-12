<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\RequestException;

class OpenAIParser
{
    protected OpenAIService $openai;

    public function __construct(OpenAIService $openai)
    {
        $this->openai = $openai;
    }

    /**
     * Parse the OpenAI response body into either:
     *  - an array of normalized records (each record = associative array with expected keys)
     *  - an empty array on failure
     *
     * This method will attempt one clarification retry via OpenAIService if initial JSON is "all empty".
     *
     * @param array $openaiBody
     * @param string|null $originalPrompt Optional — used when performing a clarification retry
     * @return array  array of records (each record has expected keys). Returns [] on parse failure.
     * @throws RequestException When OpenAIService fails on clarification call.
     */
    public function parse(array $openaiBody, ?string $originalPrompt = null): array
    {
        $content = $this->openai->extractContent($openaiBody);
        $content = trim($content);

        if ($this->isAllEmptyJson($content) && $originalPrompt !== null) {
            // Ask OpenAI to clarify once (keeps parity with previous behavior)
            Log::info('OpenAI returned all-empty JSON — requesting clarification');
            $clarifyPrompt = $originalPrompt . "\n\nIf any fields appear in the description, please extract them and return JSON only.";
            $clarifiedBody = $this->openai->chatCompletions($clarifyPrompt);
            $content = trim($this->openai->extractContent($clarifiedBody));
        }

        // Extract pure JSON block (object or array) from content
        $json = null;
        if (Str::startsWith($content, '{') || Str::startsWith($content, '[')) {
            // attempt to match complete block; fallback to content directly
            if (preg_match('/^(\[.*\]|\{.*\})$/s', $content, $m)) {
                $json = $m[0];
            } elseif (preg_match('/(\[.*\]|\{.*\})/s', $content, $m)) {
                $json = $m[0];
            } else {
                $json = $content;
            }
        } elseif (preg_match('/(\[.*\]|\{.*\})/s', $content, $m)) {
            $json = $m[0];
        }

        if (!$json) {
            Log::warning('Failed to find JSON block in OpenAI content', [
                'content_snippet' => Str::limit($content, 500),
            ]);
            return [];
        }

        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('JSON decode error from OpenAI content', [
                'json_error' => json_last_error_msg(),
                'json_snippet' => Str::limit($json, 500),
            ]);
            return [];
        }

        // Normalize into an array of items
        if ($this->isAssoc($decoded)) {
            // Single object -> make it an array for unified return
            $items = [$decoded];
        } else {
            $items = $decoded;
        }

        // Expected keys
        $expected = ['first_name', 'last_name', 'address', 'height', 'weight', 'gender', 'age'];

        $results = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = [];
            foreach ($expected as $k) {
                $v = $item[$k] ?? '';
                $row[$k] = $v === null ? '' : $v;
            }
            $results[] = $row;
        }

        return $results;
    }

    /**
     * Detect "all-empty" JSON content (object or array) where all expected fields are empty strings/null.
     * This returns true only when JSON exists and every expected field is empty.
     *
     * @param string|null $content
     * @return bool
     */
    protected function isAllEmptyJson(?string $content): bool
    {
        if (empty($content)) {
            return false;
        }

        $json = null;

        if (Str::startsWith($content, '{') || Str::startsWith($content, '[')) {
            $json = $content;
        } elseif (preg_match('/(\[.*\]|\{.*\})/s', $content, $m)) {
            $json = $m[0];
        }

        if (!$json) {
            return false;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return false;
        }

        // Normalize to array of objects
        $items = $decoded;
        if ($this->isAssoc($decoded)) {
            $items = [$decoded];
        }

        $expectedKeys = ['first_name', 'last_name', 'address', 'height', 'weight', 'gender', 'age'];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ($expectedKeys as $k) {
                $v = $item[$k] ?? null;
                if (is_string($v) && trim($v) !== '') {
                    return false; // found a non-empty string
                }
                if (is_numeric($v) && $v !== 0 && $v !== '0') {
                    return false;
                }
            }
        }

        return true;
    }

    protected function isAssoc(array $arr): bool
    {
        if (empty($arr))
            return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
