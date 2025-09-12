<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PromptValidationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Client\RequestException;
use App\Services\OpenAIService;
use App\Services\OpenAIParser;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ActorApiController extends Controller
{
    protected OpenAIService $openai;
    protected OpenAIParser $parser;

    public function __construct(OpenAIService $openai, OpenAIParser $parser)
    {
        $this->openai = $openai;
        $this->parser = $parser;
    }

    /**
     * Validate prompt: try heuristics first, then fall back to OpenAI if required fields are missing.
     */
    public function promptValidation(PromptValidationRequest $request): JsonResponse
    {
        $text = (string) $request->input('description', '');

        // 1) run fast local heuristics
        $name = $this->extractName($text);
        $address = $this->extractAddress($text);

        $extracted = [
            'first_name' => $name['first_name'] ?? '',
            'last_name' => $name['last_name'] ?? '',
            'address' => $address ?? '',
        ];

        // 2) determine what's missing
        $required = ['first_name', 'last_name', 'address'];
        $missing = $this->missingKeys($extracted, $required);

        // 3) If anything missing, call OpenAI (one attempt) and merge results
        if (!empty($missing)) {
            try {
                // Build a concise prompt for the model
                $prompt = $this->buildPrompt($text);

                // Request OpenAI (returns raw body array)
                $openaiBody = $this->openai->chatCompletions($prompt);

                // Parse with parser
                $parsedRecords = $this->parser->parse($openaiBody, $prompt);

                // Take first record (we're validating a single description)
                if (!empty($parsedRecords) && is_array($parsedRecords[0])) {
                    $first = $parsedRecords[0];

                    // Merge: keep local heuristics when non-empty and valid; otherwise take parser value.
                    foreach ($required as $k) {
                        $heuristicVal = trim((string) ($extracted[$k] ?? ''));
                        $parserVal = isset($first[$k]) ? trim((string) $first[$k]) : '';

                        if ($k === 'address') {
                            // If local heuristic address is empty OR looks invalid (e.g. contains "cm", "kg", "years", or only measurements),
                            // prefer parser value when available.
                            if ($heuristicVal === '' || $this->isLikelyInvalidAddress($heuristicVal)) {
                                $extracted[$k] = $parserVal;
                            } else {
                                // keep heuristic (it looks valid)
                                $extracted[$k] = $heuristicVal;
                            }
                        } else {
                            // For name fields: use heuristic if present, otherwise parser
                            if ($heuristicVal === '') {
                                $extracted[$k] = $parserVal;
                            } else {
                                $extracted[$k] = $heuristicVal;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Treat ANY error as "external service unavailable"
                Log::error('OpenAI extraction error during promptValidation', [
                    'message' => $e->getMessage(),
                    'exception' => $e,
                    'description_snippet' => Str::limit($text, 200),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'External extraction service temporarily unavailable. Please try again later.',
                ], 503);
            }


            // Recompute missing after merge
            $missing = $this->missingKeys($extracted, $required);
        }

        $message = empty($missing)
            ? 'All required fields present.'
            : 'Missing required fields: ' . implode(', ', $missing) . '.';

        return response()->json([
            'success' => true,
            'message' => $message,
            'extracted' => $extracted,
            'missing_keys' => $missing,
        ]);
    }


    /**
     * Build a short prompt to instruct the model to extract fields in exact JSON.
     * Use a compact prompt to keep token costs low.
     */
    protected function buildPrompt(string $description): string
    {
        // Keep it short and strict: return only JSON with exact keys.
        return <<<PROMPT
You are a strict JSON extractor. Given the description below, return ONLY a single JSON object with keys:
first_name, last_name, address, height, weight, gender, age.
Do not add commentary or extra fields. If a field cannot be extracted, return an empty string.

Description:
\"\"\" 
{$description}
\"\"\"
PROMPT;
    }

    /**
     * Helper: find which required keys are missing / empty.
     */
    protected function missingKeys(array $data, array $required): array
    {
        $missing = [];
        foreach ($required as $k) {
            if (!isset($data[$k]) || trim((string) $data[$k]) === '') {
                $missing[] = $k;
            }
        }
        return $missing;
    }

    //
    // -------------------------
    // Heuristic extractors (same as your current methods)
    // -------------------------
    //

    protected function extractName(string $text): array
    {
        $text = trim($text);

        if (preg_match('/(?:My name is|I am|This is|Name[:\s])\s*([A-Z][a-z\'-]+(?:\s+[A-Z][a-z\'-]+)+)/i', $text, $m)) {
            $full = trim($m[1]);
            return $this->splitName($full);
        }

        if (preg_match('/\b([A-Z][a-z\'-]{1,})\s+([A-Z][a-z\'-]{1,})\b/', $text, $m)) {
            return [
                'first_name' => $m[1],
                'last_name' => $m[2],
            ];
        }

        // single capitalized word at start -> treat as first name
        if (preg_match('/^([A-Z][a-z\'-]+)\b/', $text, $m)) {
            return ['first_name' => $m[1], 'last_name' => ''];
        }

        return ['first_name' => '', 'last_name' => ''];
    }

    protected function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', $full);
        if (count($parts) === 1) {
            return ['first_name' => $parts[0], 'last_name' => ''];
        }
        $first = array_shift($parts);
        $last = implode(' ', $parts);
        return ['first_name' => $first, 'last_name' => $last];
    }

    protected function extractAddress(string $text): string
    {
        $text = trim($text);

        if (preg_match('/(?:lives at|resides at|address[:\s]*)\s*([0-9A-Za-zÁÉÍÓÚáéíóúÑñ\.\'’\-\s\,]+(?:Street|St|Road|Rd|Avenue|Ave|Terrace|Lane|Boulevard|Blvd|Calle|Queen|Baker|Central|Square|Place|Court|Terrace|Terr)\b[0-9A-Za-z\,\s\-]*)/i', $text, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/\b\d{1,5}\s+[A-Za-z0-9\'’\-\.\s]+(?:Street|St|Road|Rd|Avenue|Ave|Terrace|Lane|Boulevard|Blvd|Calle|Court|Place|Square|Central|Terr)\b[\,\sA-Za-z0-9\-]*/i', $text, $m)) {
            return trim($m[0]);
        }

        if (preg_match('/([A-Za-z0-9\.\'’\-\s]+,\s*[A-Za-z\.\s]+,\s*[A-Za-z\.\s]{2,})/u', $text, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/([A-Za-z0-9\.\'’\-\s]+,\s*[A-Za-z0-9\.\'’\-\s]+,\s*[A-Za-z0-9\.\'’\-\s]+)/u', $text, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * Heuristic check: determine if the extracted "address" is probably not an address
     * but noise (measurements, age, or just the name + measurements).
     */
    protected function isLikelyInvalidAddress(string $addr): bool
    {
        $addr = trim($addr);
        if ($addr === '') {
            return true;
        }

        // If it contains measurement units or words typically not in addresses
        if (preg_match('/\b(cm|kg|kgs|lbs|lb|years?|yo|yrs?)\b/i', $addr)) {
            return true;
        }

        // If it contains patterns like "165 cm", "58 kg", or mostly numeric tokens with units
        if (preg_match('/\d+\s*(cm|kg|kgs|mm|in|ft|kg\b)/i', $addr)) {
            return true;
        }

        // If it's too short (single word or 1–2 tokens) and contains no street-like words,
        // it's unlikely to be a full address.
        $tokens = preg_split('/\s+/', $addr);
        if (count($tokens) <= 2) {
            // but if tokens include words like Street, St, Road, Ave, Calle, Terrace, Lane, etc., treat as valid
            if (!preg_match('/\b(Street|St|Road|Rd|Avenue|Ave|Terrace|Lane|Boulevard|Blvd|Calle|Court|Place|Square|Central|Terr)\b/i', $addr)) {
                return true;
            }
        }

        // otherwise assume it's probably a valid address
        return false;
    }

}
