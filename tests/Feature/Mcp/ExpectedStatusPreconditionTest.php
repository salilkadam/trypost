<?php

declare(strict_types=1);

use App\Actions\Post\AppendPostMedia;
use App\Actions\Post\CreatePosts;
use App\Actions\Post\UpdatePost;
use App\Dto\MediaItem;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Exceptions\Post\PostStatusMismatchException;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\AttachMediaFromUploadTool;
use App\Mcp\Tools\Post\AttachMediaFromUrlTool;
use App\Mcp\Tools\Post\ClearPostRecurrenceTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake();
    Storage::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC'));

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id, 'timezone' => 'UTC']);

    $this->scheduled = CreatePosts::execute($this->workspace, $this->owner, [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'content' => 'Scheduled text',
        'media' => [],
        'destinations' => [[
            'social_account_id' => $this->channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
    ])->sole();

    Http::fake([
        'example.com/photo.png' => Http::response(
            file_get_contents(__DIR__.'/../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);
});

/**
 * The post's observable state, to prove a refused call changed nothing.
 *
 * @return array<string, mixed>
 */
function expectedStatusSnapshot(Post $post): array
{
    $fresh = Post::query()->findOrFail($post->id);

    return [
        'content' => $fresh->content,
        'status' => $fresh->status,
        'scheduled_at' => $fresh->scheduled_at?->toIso8601String(),
        'schedule_mode' => $fresh->schedule_mode,
        'media' => $fresh->media ?? [],
        'recurrence_interval' => $fresh->recurrence_interval,
        'recurrence_frequency' => $fresh->recurrence_frequency,
        'recurrence_remaining' => $fresh->recurrence_remaining,
        'updated_at' => $fresh->updated_at?->toIso8601String(),
    ];
}

function expectedStatusUpload(object $test): string
{
    $token = (string) Str::uuid();
    Media::factory()->stored()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $test->workspace->id,
        'collection' => 'uploads',
        'upload_token' => $token,
    ]);

    return $token;
}

test('update-post-tool refuses a mismatched expected_status with a typed error and changes nothing', function () {
    $before = expectedStatusSnapshot($this->scheduled);

    TryPostServer::actingAs($this->owner)->tool(UpdatePostTool::class, [
        'post_id' => $this->scheduled->id,
        'expected_status' => 'draft',
        'status' => 'draft',
        'content' => 'Edited by an agent',
    ])
        ->assertHasErrors(['expected_status_mismatch: the post status is scheduled, not draft. Nothing was changed.'])
        ->assertStructuredContent([
            'error' => 'expected_status_mismatch',
            'expected_status' => 'draft',
            'actual_status' => 'scheduled',
        ]);

    expect(expectedStatusSnapshot($this->scheduled))->toBe($before)
        ->and($before['status'])->toBe(PostStatus::Scheduled);
});

test('update-post-tool applies the change when expected_status matches', function () {
    TryPostServer::actingAs($this->owner)->tool(UpdatePostTool::class, [
        'post_id' => $this->scheduled->id,
        'expected_status' => 'scheduled',
        'content' => 'Edited while scheduled',
    ])->assertOk();

    expect($this->scheduled->fresh()->content)->toBe('Edited while scheduled')
        ->and($this->scheduled->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('update-post-tool without expected_status behaves as before', function () {
    TryPostServer::actingAs($this->owner)->tool(UpdatePostTool::class, [
        'post_id' => $this->scheduled->id,
        'content' => 'No precondition',
    ])->assertOk();

    expect($this->scheduled->fresh()->content)->toBe('No precondition');
});

test('update-post-tool rejects an expected_status that is not a post status', function () {
    TryPostServer::actingAs($this->owner)->tool(UpdatePostTool::class, [
        'post_id' => $this->scheduled->id,
        'expected_status' => 'live',
        'content' => 'Nope',
    ])->assertHasErrors();

    expect($this->scheduled->fresh()->content)->toBe('Scheduled text');
});

test('UpdatePost checks expected_status against the row re-read under its lock, not the loaded model', function () {
    $draft = CreatePosts::execute($this->workspace, $this->owner, [
        'status' => 'draft',
        'content' => 'Draft text',
        'media' => [],
        'destinations' => [[
            'social_account_id' => $this->channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
    ])->sole();
    $stale = Post::query()->findOrFail($draft->id);

    // Another actor schedules the post after this caller loaded it as a draft.
    Post::query()->whereKey($draft->id)->update(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->addDays(3)]);
    $before = expectedStatusSnapshot($draft);

    expect($stale->status)->toBe(PostStatus::Draft)
        ->and(fn () => UpdatePost::execute($this->workspace, $stale, ['content' => 'Late edit', 'status' => 'draft'], $this->owner, PostStatus::Draft))
        ->toThrow(PostStatusMismatchException::class, 'expected_status_mismatch');

    expect(expectedStatusSnapshot($draft))->toBe($before);
});

test('attaching media to a scheduled post with expected_status draft is refused and adds nothing', function (string $tool) {
    $arguments = ['post_id' => $this->scheduled->id, 'expected_status' => 'draft'];
    $token = null;

    if ($tool === AttachMediaFromUploadTool::class) {
        $token = expectedStatusUpload($this);
        $arguments['upload_token'] = $token;
    } else {
        $arguments['urls'] = [['url' => 'https://example.com/photo.png']];
    }

    $before = expectedStatusSnapshot($this->scheduled);

    TryPostServer::actingAs($this->owner)->tool($tool, $arguments)
        ->assertHasErrors(['expected_status_mismatch'])
        ->assertStructuredContent(['error' => 'expected_status_mismatch', 'expected_status' => 'draft', 'actual_status' => 'scheduled']);

    expect(expectedStatusSnapshot($this->scheduled))->toBe($before)
        ->and($before['media'])->toBe([]);

    if ($token !== null) {
        // The single-use upload was not consumed by the refused call.
        expect(Media::query()->where('upload_token', $token)->where('collection', 'uploads')->exists())->toBeTrue();
    } else {
        Http::assertNothingSent();
    }
})->with([
    'from url' => [AttachMediaFromUrlTool::class],
    'from upload' => [AttachMediaFromUploadTool::class],
]);

test('attaching media with a matching expected_status attaches it', function (string $tool) {
    $draft = CreatePosts::execute($this->workspace, $this->owner, [
        'status' => 'draft',
        'content' => 'Draft text',
        'media' => [],
        'destinations' => [[
            'social_account_id' => $this->channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
    ])->sole();
    $arguments = ['post_id' => $draft->id, 'expected_status' => 'draft'];

    if ($tool === AttachMediaFromUploadTool::class) {
        $arguments['upload_token'] = expectedStatusUpload($this);
    } else {
        $arguments['urls'] = [['url' => 'https://example.com/photo.png']];
    }

    TryPostServer::actingAs($this->owner)->tool($tool, $arguments)->assertOk();

    expect($draft->fresh()->media)->toHaveCount(1)
        ->and($draft->fresh()->status)->toBe(PostStatus::Draft);
})->with([
    'from url' => [AttachMediaFromUrlTool::class],
    'from upload' => [AttachMediaFromUploadTool::class],
]);

test('AppendPostMedia checks expected_status under the row lock, not on the loaded model', function () {
    $stale = Post::query()->findOrFail($this->scheduled->id);
    Post::query()->whereKey($this->scheduled->id)->update(['status' => PostStatus::Draft, 'schedule_mode' => null]);
    $before = expectedStatusSnapshot($this->scheduled);

    $media = Media::factory()->stored()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'collection' => 'uploads',
        'upload_token' => (string) Str::uuid(),
    ]);

    expect($stale->status)->toBe(PostStatus::Scheduled)
        ->and(fn () => AppendPostMedia::execute($stale, [MediaItem::fromMedia($media)->toArray()], $this->owner, PostStatus::Scheduled))
        ->toThrow(PostStatusMismatchException::class);

    expect(expectedStatusSnapshot($this->scheduled))->toBe($before)
        ->and($before['media'])->toBe([]);
});

test('clear-post-recurrence-tool refuses a mismatched expected_status and keeps the recurrence', function () {
    $this->scheduled->update(['recurrence_interval' => 1, 'recurrence_frequency' => 'week', 'recurrence_remaining' => 3]);
    $before = expectedStatusSnapshot($this->scheduled);

    TryPostServer::actingAs($this->owner)->tool(ClearPostRecurrenceTool::class, [
        'post_id' => $this->scheduled->id,
        'expected_status' => 'draft',
    ])
        ->assertHasErrors(['expected_status_mismatch'])
        ->assertStructuredContent(['error' => 'expected_status_mismatch', 'expected_status' => 'draft', 'actual_status' => 'scheduled']);

    expect(expectedStatusSnapshot($this->scheduled))->toBe($before)
        ->and($before['recurrence_interval'])->toBe(1);
});

test('clear-post-recurrence-tool clears the recurrence when expected_status matches', function () {
    $this->scheduled->update(['recurrence_interval' => 1, 'recurrence_frequency' => 'week', 'recurrence_remaining' => 3]);

    TryPostServer::actingAs($this->owner)->tool(ClearPostRecurrenceTool::class, [
        'post_id' => $this->scheduled->id,
        'expected_status' => 'scheduled',
    ])->assertOk();

    expect($this->scheduled->fresh()->recurrence_interval)->toBeNull();
});
