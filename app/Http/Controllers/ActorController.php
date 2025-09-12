<?php

namespace App\Http\Controllers;

use App\Models\Actor;
use Illuminate\Http\Request;
use App\Http\Requests\ActorStoreRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\RequestException;
use Exception;
use GuzzleHttp\Client;
use App\Services\OpenAIService;
use App\Services\OpenAIParser;


class ActorController extends Controller
{

    protected OpenAIService $openai;
    protected OpenAIParser $parser;

    public function __construct(OpenAIService $openai, OpenAIParser $parser)
    {
        $this->openai = $openai;
        $this->parser = $parser;
    }
    public function create()
    {
        return view('actors.create');
    }

    public function store(ActorStoreRequest $request)
    {
        $data = $request->validated();

        // Build prompt
        $prompt = $this->buildPrompt($data['description']);

        try {
            // Get raw OpenAI response (may throw RequestException)
            $openaiBody = $this->openai->chatCompletions($prompt);

            // Parse into normalized records (array of records)
            $parsedRecords = $this->parser->parse($openaiBody, $prompt);

            if (empty($parsedRecords)) {
                Log::warning('OpenAI returned no parsable records', [
                    'email' => $data['email'] ?? null,
                    'description_snippet' => Str::limit($data['description'], 120),
                ]);

                return back()
                    ->withInput()
                    ->withErrors(['description' => 'Could not extract actor data. Please make sure the description includes names and addresses.']);
            }

            // If you expect a single actor per request, pick the first record and validate:
            $first = $parsedRecords[0];

            if (empty($first['first_name']) || empty($first['last_name']) || empty($first['address'])) {
                Log::warning('OpenAI returned missing mandatory fields', [
                    'email' => $data['email'] ?? null,
                    'description_snippet' => Str::limit($data['description'], 120),
                    'parsed' => $first,
                ]);

                return back()
                    ->withInput()
                    ->withErrors(['description' => 'Please add first name, last name, and address to your description.']);
            }

            // Save the first record
            $actor = Actor::create([
                'email' => $data['email'],
                'description' => $data['description'],
                'first_name' => $first['first_name'] ?? null,
                'last_name' => $first['last_name'] ?? null,
                'address' => $first['address'] ?? null,
                'height' => $first['height'] ?? null,
                'weight' => $first['weight'] ?? null,
                'gender' => $first['gender'] ?? null,
                'age' => isset($first['age']) && $first['age'] !== '' ? (int) $first['age'] : null,
            ]);

            Log::info('Actor created successfully', [
                'actor_id' => $actor->id,
                'email' => $actor->email,
                'first_name' => $actor->first_name,
                'last_name' => $actor->last_name,
            ]);

            return redirect()->route('actors.index')->with('success', 'Actor saved successfully.');

        } catch (RequestException $e) {
            Log::error('OpenAI HTTP error', [
                'email' => $data['email'] ?? null,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return back()
                ->withInput()
                ->withErrors(['description' => 'Sorry — the external service is temporarily unavailable. Please try again in a moment.']);
        } catch (Exception $e) {
            Log::error('Unexpected error saving actor', [
                'email' => $data['email'] ?? null,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            report($e);

            return back()
                ->withInput()
                ->withErrors(['description' => 'An unexpected error occurred. Please try again or contact support.']);
        }
    }

    public function index()
    {
        $actors = Actor::latest()->get();
        return view('actors.index', compact('actors'));
    }

    protected function buildPrompt(string $description): string
    {
        // Few-shot examples + strict instructions
        return <<<PROMPT
            You are a strict data extractor. Given an actor description, return ONLY a single VALID JSON object (no commentary, no markdown) with these exact keys:
            first_name, last_name, address, height, weight, gender, age.

            Rules:
            - Always return valid JSON with these keys.
            - If you can extract a value, return it (do not invent). If not present, return an empty string.
            - Do NOT include extra fields or surrounding text.
            - Keep values concise (no long explanations).

            Examples:
            Description:
            """John Smith, 221B Baker Street, London"""
            JSON:
            {"first_name":"John","last_name":"Smith","address":"221B Baker Street, London","height":"","weight":"","gender":"","age":""}

            Description:
            """Maria Garcia, 45 Calle Mayor, Madrid, female, 165 cm, 60 kg, 32 years old"""
            JSON:
            {"first_name":"Maria","last_name":"Garcia","address":"45 Calle Mayor, Madrid","height":"165 cm","weight":"60 kg","gender":"Female","age":"32"}

            Now extract from this description (do NOT include the examples):

            Description:
            \"\"\" 
            {$description}
            \"\"\"

            Return JSON only:
        PROMPT;
    }


}
