<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Http\Attributes\Body;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Path;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

readonly class UnprefixedImportRequest
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        #[Key('file_name')]
        public string $fileName,
        #[Query]
        public int $page,
        #[Body]
        public string $notes,
        #[Path]
        public int $id,
        #[Header('X-Count')]
        public int $count,
        public array $tags = [],
    ) {}
}

function unprefixedErrors(TestCase $test): array
{
    $test->setRequest(
        query: ['page' => 'first', 'tags' => ['a', ['b']]],
        headers: ['X-Count' => 'many'],
        routeParams: ['id' => 'x'],
    );

    try {
        $test->mapRequest(UnprefixedImportRequest::class);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a ValidationException.');
}

it('keys and words input errors as Laravel does when source prefixes are off', function () {
    /** @var TestCase $this */
    config()->set('roma.source_prefixed_errors', false);

    expect(unprefixedErrors($this))->toBe([
        'file_name' => ['The file name field is required.'],
        'page' => ['The page field must be an integer.'],
        'notes' => ['The notes field is required.'],
        'path.id' => ['The path.id field must be an integer.'],
        'header.X-Count' => ['The header.X-Count field must be an integer.'],
        'tags.1' => ['The tags.1 field must be a string.'],
    ]);
});

it('prefixes errors with their source by default', function () {
    /** @var TestCase $this */
    expect(array_keys(unprefixedErrors($this)))->toBe([
        'input.file_name', 'query.page', 'body.notes', 'path.id', 'header.X-Count', 'input.tags.1',
    ]);
});
