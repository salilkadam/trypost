<?php

declare(strict_types=1);

namespace App\Exceptions\Post;

use App\Enums\Post\Status as PostStatus;
use RuntimeException;

/**
 * Thrown when a caller's expected_status precondition does not match the post's
 * status re-read under its row lock. Thrown inside the write transaction, so the
 * transaction rolls back and the post is left exactly as it was.
 */
class PostStatusMismatchException extends RuntimeException
{
    public const string CODE = 'expected_status_mismatch';

    public function __construct(public readonly PostStatus $expected, public readonly PostStatus $actual)
    {
        parent::__construct(sprintf(
            '%s: the post status is %s, not %s. Nothing was changed.',
            self::CODE,
            $actual->value,
            $expected->value,
        ));
    }

    /**
     * Refuses when an expected status was given and the locked post differs.
     */
    public static function assertMatches(?PostStatus $expected, PostStatus $actual): void
    {
        if ($expected !== null && $expected !== $actual) {
            throw new self($expected, $actual);
        }
    }
}
