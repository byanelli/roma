<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Delimited;
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Http\Request\HandlesFailedValidation;
use BYanelli\Roma\Http\Request\Injection\Request as RomaRequest;
use BYanelli\Roma\Http\Request\Injection\RequestQuery;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

enum QuerySort: string
{
    case Newest = 'newest';
    case Oldest = 'oldest';
}

readonly class ProductFilters
{
    /**
     * @param  list<int>  $ids
     * @param  list<string>  $tags
     */
    public function __construct(
        public int $page = 1,
        public float $minPrice = 0.0,
        public bool $inStock = false,
        public QuerySort $sort = QuerySort::Newest,
        public ?int $limit = null,
        public array $ids = [],
        #[Delimited]
        public array $tags = [],
    ) {}
}

/**
 * ProductFilters with #[Query] properties, mapped with #[Request].
 */
readonly class ProductFiltersFromQuery
{
    /**
     * @param  list<int>  $ids
     * @param  list<string>  $tags
     */
    public function __construct(
        #[Query]
        public int $page = 1,

        #[Query]
        public float $minPrice = 0.0,

        #[Query]
        public bool $inStock = false,

        #[Query]
        public QuerySort $sort = QuerySort::Newest,

        #[Query]
        public ?int $limit = null,

        #[Query]
        public array $ids = [],

        #[Query]
        #[Delimited]
        public array $tags = [],
    ) {}
}

readonly class HandledProductFilters implements HandlesFailedValidation
{
    public function __construct(
        #[Rule('min:1')]
        public int $page,
    ) {}

    public static function failedValidation(ValidationException $exception): SymfonyResponse
    {
        return response()->json(['error' => collect($exception->errors())->flatten()->first()], 400);
    }
}

readonly class SearchQuery
{
    public function __construct(
        #[Rule('min:3')]
        public string $q,
    ) {}
}

beforeEach(function () {
    Route::get('/query/filters', fn (
        #[RequestQuery]
        ProductFilters $filters,
    ) => response()->json($filters));

    Route::match(['GET', 'POST'], '/query/from-query', fn (
        #[RomaRequest]
        ProductFiltersFromQuery $filters,
    ) => response()->json($filters));

    Route::post('/query/search', fn (
        #[RequestQuery]
        SearchQuery $search,
    ) => response()->json($search));

    Route::get('/query/handled', fn (
        #[RequestQuery]
        HandledProductFilters $filters,
    ) => response()->json($filters));

    Route::get('/query/precognitive', fn (
        #[RequestQuery]
        SearchQuery $search,
    ) => response()->json($search))->middleware(HandlePrecognitiveRequests::class);
});

it('coerces query string values as #[Query] does', function (string $query, array $expected) {
    /** @var TestCase $this */
    $this->getJson("/query/filters?{$query}")
        ->assertOk()
        ->assertJson($expected);

    $this->getJson("/query/from-query?{$query}")
        ->assertOk()
        ->assertJson($expected);
})->with([
    'an int' => ['page=2', ['page' => 2]],
    'a float' => ['minPrice=2.5', ['minPrice' => 2.5]],
    'a whole float' => ['minPrice=2', ['minPrice' => 2.0]],
    'true' => ['inStock=true', ['inStock' => true]],
    '1' => ['inStock=1', ['inStock' => true]],
    'on' => ['inStock=on', ['inStock' => true]],
    'false' => ['inStock=false', ['inStock' => false]],
    '0' => ['inStock=0', ['inStock' => false]],
    'an enum' => ['sort=oldest', ['sort' => 'oldest']],
    // Laravel's ConvertEmptyStringsToNull middleware turns the empty value into null.
    'an empty nullable int' => ['limit=', ['limit' => null]],
    'a list of ints' => ['ids[]=1&ids[]=2', ['ids' => [1, 2]]],
    'a delimited list' => ['tags=a,b', ['tags' => ['a', 'b']]],
]);

it('rejects invalid query string values as #[Query] does, under bare keys', function (string $query, array $errors, array $prefixedErrors) {
    /** @var TestCase $this */
    $this->getJson("/query/filters?{$query}")
        ->assertUnprocessable()
        ->assertJsonPath('errors', $errors);

    $this->getJson("/query/from-query?{$query}")
        ->assertUnprocessable()
        ->assertJsonPath('errors', $prefixedErrors);
})->with([
    'an int' => [
        'page=2.5',
        ['page' => ['The page field must be an integer.']],
        ['query.page' => ['The query.page field must be an integer.']],
    ],
    'a bool' => [
        'inStock[]=1',
        ['inStock' => ['The in stock field must be true or false.']],
        ['query.inStock' => ['The query.inStock field must be true or false.']],
    ],
    'an enum' => [
        'sort=random',
        ['sort' => ['The sort field must be one of: newest, oldest.']],
        ['query.sort' => ['The query.sort field must be one of: newest, oldest.']],
    ],
    'a list member' => [
        'ids[]=1&ids[]=x',
        ['ids.1' => ['The ids.1 field must be an integer.']],
        ['query.ids.1' => ['The query.ids.1 field must be an integer.']],
    ],
]);

it('ignores the body', function () {
    /** @var TestCase $this */
    $this->postJson('/query/search?q=lamp', ['q' => 'x'])
        ->assertOk()
        ->assertExactJson(['q' => 'lamp']);

    $this->postJson('/query/search', ['q' => 'lamp'])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['q' => ['The q field is required.']]);
});

it('answers a failed validation with the response of HandlesFailedValidation', function () {
    /** @var TestCase $this */
    $this->getJson('/query/handled?page=0')
        ->assertStatus(400)
        ->assertExactJson(['error' => 'The page field must be at least 1.']);
});

it('answers a valid precognitive request with a 204', function () {
    /** @var TestCase $this */
    $this->withPrecognition()
        ->getJson('/query/precognitive?q=lamp')
        ->assertNoContent()
        ->assertHeader('Precognition-Success', 'true');
});

it('returns bare keys for a failing precognitive request', function () {
    /** @var TestCase $this */
    $this->withPrecognition()
        ->getJson('/query/precognitive?q=x')
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['q' => ['The q field must be at least 3 characters.']]);
});

it('maps the same class from an array', function () {
    expect(Mapper::fromArray(SearchQuery::class, ['q' => 'lamp']))->toEqual(new SearchQuery('lamp'));
});
