<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Actor;
use App\Services\OpenAIService;
use App\Services\OpenAIParser;
use Mockery;

class ActorControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_store_saves_actor_when_openai_returns_valid_record()
    {
        // Arrange: mock OpenAIService to return a body (raw content used by parser mock)
        $openaiMock = \Mockery::mock(OpenAIService::class);
        $openaiMock->shouldReceive('chatCompletions')->once()->andReturn([
            'choices' => [
                ['message' => ['content' => '{"first_name":"John","last_name":"Doe","address":"742 Evergreen Terrace, Springfield","height":"180 cm","weight":"75 kg","gender":"Male","age":"32"}']]
            ]
        ]);
        $this->app->instance(OpenAIService::class, $openaiMock);

        // Mock parser to return normalized records
        $parserMock = \Mockery::mock(OpenAIParser::class);
        $parserMock->shouldReceive('parse')->once()->andReturn([
            [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'address' => '742 Evergreen Terrace, Springfield',
                'height' => '180 cm',
                'weight' => '75 kg',
                'gender' => 'Male',
                'age' => '32',
            ]
        ]);
        $this->app->instance(OpenAIParser::class, $parserMock);

        // Act: call the store route (adjust route if different)
        $response = $this->post('/actors', [
            'email' => 'test@example.com',
            'description' => 'John Doe, 742 Evergreen Terrace, Springfield. Male, 180 cm, 75 kg, 32 years old'
        ]);

        // Assert
        $response->assertRedirect(route('actors.index'));
        $this->assertDatabaseHas('actors', [
            'email' => 'test@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address' => '742 Evergreen Terrace, Springfield',
        ]);
        $this->assertEquals(1, Actor::count());
    }

    public function test_store_handles_openai_http_exception_gracefully()
    {
        // Arrange: mock OpenAIService to throw a generic exception (controller catches Exception)
        $openaiMock = \Mockery::mock(OpenAIService::class);
        $openaiMock->shouldReceive('chatCompletions')->once()->andThrow(new \RuntimeException('openai error'));
        $this->app->instance(OpenAIService::class, $openaiMock);

        // Parser should not be called in this scenario but bind a dummy to satisfy DI
        $parserMock = \Mockery::mock(OpenAIParser::class);
        $this->app->instance(OpenAIParser::class, $parserMock);

        // Act
        $response = $this->from('/actors/create')->post('/actors', [
            'email' => 'err@example.com',
            'description' => 'John Doe, 742 Evergreen Terrace'
        ]);

        // Assert: should redirect back to create with an error
        $response->assertRedirect('/actors/create');
        $response->assertSessionHasErrors('description');
        $this->assertDatabaseCount('actors', 0);
    }
}
