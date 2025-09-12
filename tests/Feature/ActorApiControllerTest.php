<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\OpenAIService;
use App\Services\OpenAIParser;
use Mockery;

class ActorApiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_prompt_validation_returns_success_when_heuristics_find_all_fields_and_openai_is_not_called()
    {
        // Bind a mock OpenAIService but assert it is NOT called
        $openaiMock = \Mockery::mock(OpenAIService::class);
        $openaiMock->shouldNotReceive('chatCompletions');
        $this->app->instance(OpenAIService::class, $openaiMock);

        // Parser won't be used either
        $parserMock = \Mockery::mock(OpenAIParser::class);
        $parserMock->shouldNotReceive('parse');
        $this->app->instance(OpenAIParser::class, $parserMock);

        $payload = [
            'description' => 'John Doe, 742 Evergreen Terrace, Springfield. Male, 180 cm, 75 kg, 32 years old.'
        ];

        $response = $this->postJson('/api/actors/prompt-validation', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'All required fields present.',
                'missing_keys' => []
            ]);

        // Assert extracted names exactly
        $response->assertJsonPath('extracted.first_name', 'John');
        $response->assertJsonPath('extracted.last_name', 'Doe');

        // Assert the returned address contains our expected substring (robust to extra city/country)
        $address = $response->json('extracted.address');
        $this->assertIsString($address);
        $this->assertStringContainsString('742 Evergreen Terrace', $address);
    }


    public function test_prompt_validation_falls_back_to_openai_when_heuristics_missing_and_merges_results()
    {
        // Heuristics will detect only first name; controller should call OpenAI
        $openaiMock = \Mockery::mock(OpenAIService::class);
        $openaiMock->shouldReceive('chatCompletions')->once()->andReturn([
            'choices' => [
                ['message' => ['content' => '{"first_name":"Maria","last_name":"Gonzalez","address":"Calle Mayor 123, Madrid, Spain","height":"165 cm","weight":"58 kg","gender":"Female","age":"28"}']]
            ]
        ]);
        $this->app->instance(OpenAIService::class, $openaiMock);

        // Parser should parse and return one record
        $parserMock = \Mockery::mock(OpenAIParser::class);
        $parserMock->shouldReceive('parse')->once()->andReturn([
            [
                'first_name' => 'Maria',
                'last_name' => 'Gonzalez',
                'address' => 'Calle Mayor 123, Madrid, Spain',
                'height' => '165 cm',
                'weight' => '58 kg',
                'gender' => 'Female',
                'age' => '28',
            ]
        ]);
        $this->app->instance(OpenAIParser::class, $parserMock);

        $payload = [
            'description' => 'Maria, 165 cm, 58 kg, 28 years old.'
        ];

        $response = $this->postJson('/api/actors/prompt-validation', $payload);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'first_name' => 'Maria',
                'last_name' => 'Gonzalez',
                'address' => 'Calle Mayor 123, Madrid, Spain'
            ]);

        $json = $response->json();
        $this->assertTrue($json['success']);
        $this->assertEmpty($json['missing_keys']);
    }

    public function test_prompt_validation_returns_503_when_openai_fails()
    {
        $openaiMock = \Mockery::mock(OpenAIService::class);
        $openaiMock->shouldReceive('chatCompletions')->once()->andThrow(new \RuntimeException('service down'));
        $this->app->instance(OpenAIService::class, $openaiMock);

        // parser not needed but bind dummy
        $parserMock = \Mockery::mock(OpenAIParser::class);
        $this->app->instance(OpenAIParser::class, $parserMock);

        $payload = [
            'description' => 'Maria, 165 cm, 58 kg, 28 years old.'
        ];

        $response = $this->postJson('/api/actors/prompt-validation', $payload);

        $response->assertStatus(503)
            ->assertJson([
                'success' => false,
            ]);
    }
}
