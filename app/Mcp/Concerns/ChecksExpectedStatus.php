<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Enums\Post\Status as PostStatus;
use App\Exceptions\Post\PostStatusMismatchException;
use App\Models\Post;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Optional optimistic precondition for tools that change a post: when the caller
 * sends expected_status and the post's current status differs, the tool refuses
 * with a typed error (structured `error: expected_status_mismatch`) and changes
 * nothing. The authoritative comparison runs against the post re-read under its
 * row lock in the same transaction as the write; the early check here only avoids
 * side effects (downloads, hosted media) in the common case.
 */
trait ChecksExpectedStatus
{
    protected function expectedStatusSchema(JsonSchema $schema): Type
    {
        return $schema->string()
            ->enum(array_column(PostStatus::cases(), 'value'))
            ->description('Optional precondition: the status the post must have right now (for example "draft"). When it differs, the call is refused with error expected_status_mismatch and nothing is changed. The check runs under the post row lock, in the same transaction as the change.');
    }

    protected function expectedStatus(Request $request): ?PostStatus
    {
        $validated = $request->validate([
            'expected_status' => ['nullable', 'string', Rule::enum(PostStatus::class)],
        ]);

        $value = data_get($validated, 'expected_status');

        return is_string($value) ? PostStatus::from($value) : null;
    }

    /**
     * Early, unlocked refusal; the locked re-check still decides.
     */
    protected function refuseUnlessStatus(?PostStatus $expected, Post $post): Response|ResponseFactory|null
    {
        if ($expected === null || $expected === $post->status) {
            return null;
        }

        return $this->statusMismatch(new PostStatusMismatchException($expected, $post->status));
    }

    protected function statusMismatch(PostStatusMismatchException $exception): ResponseFactory
    {
        return Response::make(Response::error($exception->getMessage()))->withStructuredContent([
            'error' => PostStatusMismatchException::CODE,
            'expected_status' => $exception->expected->value,
            'actual_status' => $exception->actual->value,
        ]);
    }
}
