<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;

/*
 * The MCP annotations of every tool must match tests/fixtures/mcp-tool-annotations.json,
 * which is derived from the Connect Hub pin table (risk class per tool):
 *   read                                => readOnlyHint (openWorldHint too for reads that call a network)
 *   write                               => no hints
 *   publish, write_to_publish, destructive => destructiveHint
 * MCP has no publish hint, so publish-class tools carry destructiveHint: a client that
 * reads only the standard hints gates them like a delete. Changing a tool's hints, or
 * adding or removing a tool, means updating the fixture in the same change.
 */

/**
 * @return array{counts: array<string, int>, tools: array<string, array{pin_class: string, annotations: array<string, bool>}>}
 */
function mcpAnnotationFixture(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../fixtures/mcp-tool-annotations.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, array<string, mixed>> tool name => annotations the server sends
 */
function mcpServerAnnotations(): array
{
    $tools = (new ReflectionClass(TryPostServer::class))->getProperty('tools')->getDefaultValue();

    return collect($tools)
        ->map(fn (string $class): array => app($class)->toArray())
        ->mapWithKeys(fn (array $tool): array => [$tool['name'] => (array) $tool['annotations']])
        ->all();
}

test('the server registers exactly the tools the annotation fixture lists', function () {
    $fixture = array_keys(mcpAnnotationFixture()['tools']);
    $server = array_keys(mcpServerAnnotations());
    sort($server);

    expect(array_values(array_diff($server, $fixture)))->toBe([], 'tools without a fixture row')
        ->and(array_values(array_diff($fixture, $server)))->toBe([], 'fixture rows without a tool')
        ->and($server)->toHaveCount(83);
});

test('every tool sends exactly the annotations its pin class requires', function () {
    $fixture = mcpAnnotationFixture()['tools'];
    $mismatches = [];

    foreach (mcpServerAnnotations() as $name => $annotations) {
        ksort($annotations);
        $expected = $fixture[$name]['annotations'] ?? null;

        if (is_array($expected)) {
            ksort($expected);
        }

        if ($annotations !== $expected) {
            $mismatches[$name] = ['expected' => $expected, 'actual' => $annotations];
        }
    }

    expect($mismatches)->toBe([]);
});

test('the fixture follows the pin-class rule and the pin table counts', function () {
    $fixture = mcpAnnotationFixture();
    $counts = [];

    foreach ($fixture['tools'] as $name => $row) {
        $class = $row['pin_class'];
        $annotations = $row['annotations'];
        $counts[$class] = ($counts[$class] ?? 0) + 1;

        match ($class) {
            'read' => expect(data_get($annotations, 'readOnlyHint'))->toBeTrue("{$name} is read")
                ->and($annotations)->not->toHaveKey('destructiveHint'),
            'write' => expect($annotations)->toBe([], "{$name} is write"),
            'publish', 'write_to_publish', 'destructive' => expect(data_get($annotations, 'destructiveHint'))->toBeTrue("{$name} is {$class}")
                ->and($annotations)->not->toHaveKey('readOnlyHint'),
            'excluded' => expect($annotations)->toBeArray(),
        };
    }

    ksort($counts);

    expect($counts)->toBe([
        'destructive' => 7,
        'excluded' => 14,
        'publish' => 16,
        'read' => 28,
        'write' => 13,
        'write_to_publish' => 5,
    ])->and($fixture['counts'])->toBe($counts);
});
