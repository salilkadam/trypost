<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['trypost.self_hosted' => true]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->token = mcpBearerToken($this->user, $this->workspace)['plain_token'];
});

function mcpInitialize(object $test, string $token): TestResponse
{
    // Each call is a fresh request: drop the guard's memoized user from the last one.
    app('auth')->forgetGuards();

    return $test->withHeaders([
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json, text/event-stream',
    ])->postJson(route('mcp.trypost'), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'Pest', 'version' => '1.0'],
        ],
    ]);
}

test('initialize reports the build sha as serverInfo.version', function () {
    config(['trypost.mcp.version' => '1a2b3c4d']);

    mcpInitialize($this, $this->token)
        ->assertSuccessful()
        ->assertJsonPath('result.serverInfo.name', 'TryPost')
        ->assertJsonPath('result.serverInfo.version', '1a2b3c4d');
});

test('serverInfo.version falls back to dev when no build sha is set', function (?string $configured) {
    config(['trypost.mcp.version' => $configured]);

    expect(TryPostServer::buildVersion())->toBe('dev');

    mcpInitialize($this, $this->token)
        ->assertSuccessful()
        ->assertJsonPath('result.serverInfo.version', 'dev');
})->with([
    'empty (ARG unset in the image build)' => [''],
    'whitespace' => ['  '],
    'null' => [null],
]);

test('the mcp route is rate limited per workspace', function () {
    config(['trypost.mcp.rate_limit_per_minute' => 2]);

    mcpInitialize($this, $this->token)->assertSuccessful();
    mcpInitialize($this, $this->token)->assertSuccessful();
    mcpInitialize($this, $this->token)->assertTooManyRequests();

    // A second token of the same workspace shares the bucket.
    $second = mcpBearerToken($this->user, $this->workspace)['plain_token'];
    mcpInitialize($this, $second)->assertTooManyRequests();

    // Another workspace has its own bucket.
    $other = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['account_id' => $other->account_id, 'user_id' => $other->id]);
    $otherWorkspace->members()->attach($other->id, membershipPivot('admin'));
    $other->update(['current_workspace_id' => $otherWorkspace->id]);
    mcpInitialize($this, mcpBearerToken($other, $otherWorkspace)['plain_token'])->assertSuccessful();
});

test('a limit of 0 disables the mcp rate limit', function () {
    config(['trypost.mcp.rate_limit_per_minute' => 0]);

    foreach (range(1, 3) as $attempt) {
        mcpInitialize($this, $this->token)->assertSuccessful();
    }
});
