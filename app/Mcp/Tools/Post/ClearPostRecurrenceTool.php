<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\UpdatePostRecurrence;
use App\Exceptions\Post\PostStatusMismatchException;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\ChecksExpectedStatus;
use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Stop a post from repeating: removes its recurrence rule. The post itself and its next scheduled time stay as they are.')]
#[IsDestructive]
class ClearPostRecurrenceTool extends Tool
{
    use AuthorizesMcpTool;
    use ChecksExpectedStatus;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->currentWorkspace($request);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $postId = $request->get('post_id');
        $post = is_string($postId) && Str::isUuid($postId)
            ? Post::where('workspace_id', $workspace->id)->find($postId)
            : null;

        if (! $post) {
            return Response::error('Post not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'update', $post, 'Post not found.')) {
            return $denied;
        }

        $expectedStatus = $this->expectedStatus($request);

        try {
            DB::transaction(function () use ($post, $expectedStatus): void {
                $locked = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

                PostStatusMismatchException::assertMatches($expectedStatus, $locked->status);

                UpdatePostRecurrence::execute($locked, null);
            });
        } catch (PostStatusMismatchException $exception) {
            return $this->statusMismatch($exception);
        }

        $post = Post::query()->with(['socialAccount', 'labels'])->findOrFail($post->id);

        return Response::structured((new PostResource($post))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('UUID of the post.'),
            'expected_status' => $this->expectedStatusSchema($schema),
        ];
    }
}
