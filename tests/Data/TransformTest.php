<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Limit;
use BYanelli\Roma\Attributes\MapFromInput;
use BYanelli\Roma\Attributes\MapFromValue;
use BYanelli\Roma\Attributes\SerializeUsing;
use BYanelli\Roma\Config\ConfigObject;
use BYanelli\Roma\Data\Attributes\MapTransform;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Http\Client\Response\ResponseInput;
use BYanelli\Roma\Http\Client\Response\ResponsePolicy;
use BYanelli\Roma\Http\Request\Injection\RequestBody;
use BYanelli\Roma\Missing;
use BYanelli\Roma\Serialization\Profile;
use BYanelli\Roma\Serialization\SerializeTransform;
use BYanelli\Roma\Tests\TestCase;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Shorten implements MapTransform, SerializeTransform
{
    public function __construct(public int $length) {}

    public function mapValue(mixed $value): mixed
    {
        return is_string($value) ? substr($value, 0, $this->length) : $value;
    }

    public function serializeValue(mixed $value, object $object): mixed
    {
        return $this->mapValue($value);
    }
}

readonly class TransformPoint
{
    public function __construct(public int $x) {}
}

readonly class TransformContact
{
    public function __construct(
        #[MapFromValue(trim(...))]
        #[MapFromValue(strtolower(...))]
        public string $email,

        #[MapFromValue(strrev(...))]
        public string|Missing $nickname,

        #[MapFromValue(strtoupper(...))]
        public string $country = 'us',

        #[MapFromValue(self::double(...))]
        public ?TransformPoint $point = null,

        #[Shorten(3)]
        public ?string $code = null,
    ) {}

    public static function double(?TransformPoint $point): ?TransformPoint
    {
        return $point === null ? null : new TransformPoint($point->x * 2);
    }
}

readonly class TransformValidated
{
    public function __construct(
        #[MapFromValue(trim(...))]
        public string $name,
    ) {}

    #[MapFromValue(strtoupper(...))]
    public string $tag;
}

it('passes mapped values through the callables in order', function () {
    $contact = Mapper::fromArray(TransformContact::class, [
        'email' => ' Pat@Example.TEST ',
        'point' => ['x' => '2'],
        'code' => 'ABCDEF',
    ]);

    expect($contact->email)->toBe('pat@example.test')
        ->and($contact->country)->toBe('US')
        ->and($contact->point)->toEqual(new TransformPoint(4))
        ->and($contact->nickname)->toBeInstanceOf(Missing::class)
        ->and($contact->code)->toBe('ABC');
});

it('transforms after validation', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['name' => ['not', 'a', 'string'], 'tag' => 'x']);

    expect(fn () => $this->mapRequest(TransformValidated::class))->toThrow(ValidationException::class);

    $this->setRequest(query: ['name' => ' Ada ', 'tag' => 'x']);

    $request = $this->mapRequest(TransformValidated::class);

    expect($request->name)->toBe('Ada')
        ->and($request->tag)->toBe('X');
});

readonly class TransformLead
{
    public function __construct(
        public string $firstName,
        public string $lastName,

        #[SerializeUsing(self::fullName(...))]
        public ?string $fullName = null,

        #[SerializeUsing(strtoupper(...))]
        #[SerializeUsing(self::exclaim(...))]
        public string $status = 'new',

        #[SerializeUsing(intval(...))]
        public ?bool $active = null,

        #[Shorten(2)]
        public string $state = 'Alabama',
    ) {}

    public static function fullName(?string $fullName, self $lead): string
    {
        return $fullName ?? "{$lead->firstName} {$lead->lastName}";
    }

    public static function exclaim(string $status): string
    {
        return "{$status}!";
    }
}

it('passes serialized values through the callables in order', function () {
    expect(Serializer::toArray(new TransformLead('Pat', 'Lee'), Profile::storage()))->toBe([
        'firstName' => 'Pat',
        'lastName' => 'Lee',
        'fullName' => 'Pat Lee',
        'status' => 'NEW!',
        'active' => 0,
        'state' => 'Al',
    ])
        ->and(Serializer::toArray(new TransformLead('Pat', 'Lee', 'Patricia Lee', active: true))['fullName'])->toBe('Patricia Lee');
});

it('rejects #[MapFromValue] on a virtual property', function () {
    $class = new class
    {
        #[MapFromValue(trim(...))]
        public string $name {
            get => 'Ada';
        }
    };

    expect(fn () => Mapper::fromArray($class::class, []))->toThrow(RuntimeException::class, '#[MapFromValue] has no effect');
});

readonly class TransformRequestLead
{
    public function __construct(
        public string $first_name,
        public string $last_name,
    ) {}

    #[MapFromInput(static function (Request $request): bool {
        return $request->boolean('current_cdl');
    })]
    public bool $current_cdl;

    #[MapFromInput(static function (Request $request): string {
        return $request->input('full_name') ?: "{$request->input('first_name')} {$request->input('last_name')}";
    })]
    #[MapFromValue(strtoupper(...))]
    public string $full_name;

    #[MapFromInput(static function (Request $request): bool {
        return $request->boolean('is_test') || self::looksLikeTest($request->input('first_name'));
    })]
    public bool $is_test;

    private static function looksLikeTest(string $name): bool
    {
        return str_contains($name, 'test');
    }
}

readonly class TransformWrongType
{
    #[MapFromInput(static function (Request $request) {
        return $request->input('count');
    })]
    public string $fromRequest;

    #[MapFromValue(strlen(...))]
    public string $length;
}

it('rejects a transform result the property type does not accept', function (array $query, string $message) {
    /** @var TestCase $this */
    $this->setRequest(query: $query);

    expect(fn () => $this->mapRequest(TransformWrongType::class))->toThrow(RuntimeException::class, $message);
})->with([
    [['count' => ['a'], 'length' => 'abc'], 'TransformWrongType::$fromRequest" is typed string, but its map transforms returned array'],
    [['count' => 'a', 'length' => 'abc'], 'TransformWrongType::$length" is typed string, but its map transforms returned int'],
]);

it('sets a property from a callable that takes the request, without reading or validating its input', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['first_name' => 'Pat', 'last_name' => 'Lee', 'current_cdl' => 'yes']);

    $lead = $this->mapRequest(TransformRequestLead::class);

    expect($lead->current_cdl)->toBeTrue()
        ->and($lead->full_name)->toBe('PAT LEE')
        ->and($lead->is_test)->toBeFalse();

    $this->setRequest(query: ['first_name' => 'tester', 'last_name' => 'Lee', 'current_cdl' => 'Y', 'full_name' => 'Pat']);

    $lead = $this->mapRequest(TransformRequestLead::class);

    expect($lead->current_cdl)->toBeFalse()
        ->and($lead->full_name)->toBe('PAT')
        ->and($lead->is_test)->toBeTrue();
});

it('leaves a property set from the request out of TypeScript', function () {
    expect(new TypeScriptGenerator(requests: [TransformRequestLead::class])->generate())
        ->toContain('first_name: string;')
        ->not->toContain('current_cdl');
});

it('rejects a #[MapFromInput] callable whose parameter does not accept the input', function () {
    expect(fn () => Mapper::fromArray(TransformRequestLead::class, ['first_name' => 'Pat', 'last_name' => 'Lee']))
        ->toThrow(RuntimeException::class, 'Roma property "TransformRequestLead::$current_cdl" has #[MapFromInput] with a callable that takes '
            .'Illuminate\Http\Request, but under ArrayPolicy it receives array. Type the parameter as array or leave it untyped.');
});

readonly class TransformLateRequest
{
    #[MapFromValue(trim(...))]
    #[MapFromInput(static function (Request $request): string {
        return 'x';
    })]
    public string $name;
}

readonly class TransformSourcedRequest
{
    #[Query]
    #[MapFromInput(static function (Request $request): string {
        return 'x';
    })]
    public string $name;
}

readonly class TransformNestedRequest
{
    public function __construct(public TransformSourcedMember $member) {}
}

readonly class TransformSourcedMember
{
    #[MapFromInput(static function (Request $request): string {
        return 'x';
    })]
    public string $name;
}

it('rejects a misplaced callable that takes the request', function (string $class, string $message) {
    /** @var TestCase $this */
    $this->setRequest(query: ['name' => 'Ada', 'member' => []]);

    expect(fn () => $this->mapRequest($class))->toThrow(RuntimeException::class, $message);
})->with([
    [TransformLateRequest::class, 'has #[MapFromInput] after another map transform'],
    [TransformSourcedRequest::class, 'so it cannot map from a source or be inside a nested object'],
    [TransformNestedRequest::class, 'so it cannot map from a source or be inside a nested object'],
]);

readonly class LimitedNote
{
    public function __construct(
        #[Limit(5)]
        public string $title,
        #[Limit(3, end: '')]
        public ?string $code = null,
        #[Limit(3)]
        public ?string $tag = null,
    ) {}
}

it('cuts a string with #[Limit] and appends the end marker', function () {
    $note = Mapper::fromArray(LimitedNote::class, ['title' => 'Hello world', 'code' => 'ABCDEF', 'tag' => 'abc']);

    expect($note->title)->toBe('Hello...')
        ->and($note->code)->toBe('ABC')
        ->and($note->tag)->toBe('abc')
        ->and(Mapper::fromArray(LimitedNote::class, ['title' => 'Hi'])->code)->toBeNull();
});

it('rejects a value #[Limit] cannot cut', function () {
    expect(fn () => new Limit(3)->mapValue(12345))->toThrow(InvalidArgumentException::class, '#[Limit] applies to strings, but the value is int');
});

readonly class TransformArrayLead
{
    public function __construct(
        public string $first_name,
        public string $last_name,
    ) {}

    #[MapFromInput(static function (array $data): string {
        return "{$data['first_name']} {$data['last_name']}";
    })]
    #[MapFromValue(strtoupper(...))]
    public string $full_name;

    #[MapFromInput(static function ($data): int {
        return count($data);
    })]
    public int $key_count;
}

it('passes the plain array to a #[MapFromInput] callable under Mapper::fromArray()', function () {
    $lead = Mapper::fromArray(TransformArrayLead::class, ['first_name' => 'Pat', 'last_name' => 'Lee']);

    expect($lead->full_name)->toBe('PAT LEE')
        ->and($lead->key_count)->toBe(2);
});

it('passes the body, without the form fields, to a #[MapFromInput] callable under #[RequestBody]', function () {
    /** @var TestCase $this */
    Route::post('/transform/lead', fn (
        #[RequestBody]
        TransformArrayLead $lead,
    ) => response()->json(['full_name' => $lead->full_name, 'key_count' => $lead->key_count]));

    $this->post('/transform/lead', ['first_name' => 'Pat', 'last_name' => 'Lee', '_token' => 'abc'])
        ->assertOk()
        ->assertExactJson(['full_name' => 'PAT LEE', 'key_count' => 2]);
});

readonly class TransformArrayInput
{
    #[MapFromInput(static function (array $data): string {
        return 'x';
    })]
    public string $name;
}

it('rejects a #[MapFromInput] callable that takes an array when mapping a request', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['name' => 'Ada']);

    expect(fn () => $this->mapRequest(TransformArrayInput::class))
        ->toThrow(RuntimeException::class, 'Roma property "TransformArrayInput::$name" has #[MapFromInput] with a callable that takes array, '
            .'but under RequestSourcePolicy it receives Illuminate\Http\Request. Type the parameter as Illuminate\Http\Request or leave it untyped.');
});

readonly class TransformValueTypedRequest
{
    #[MapFromValue(static function (Request $request): bool {
        return true;
    })]
    public bool $flag;
}

readonly class TransformValueUnionRequest
{
    #[MapFromValue(static function (Request|string $value): string {
        return $value instanceof Request ? 'request' : "value {$value}";
    })]
    public string $name;
}

it('never passes the request to a #[MapFromValue] callable', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['flag' => '1', 'name' => 'Ada']);

    expect(fn () => $this->mapRequest(TransformValueTypedRequest::class))
        ->toThrow(RuntimeException::class, 'Roma property "TransformValueTypedRequest::$flag" has a #[MapFromValue] callable that takes Request, '
            .'which is not the property\'s type. The callable receives the property\'s value; to receive the input, use #[MapFromInput].')
        ->and($this->mapRequest(TransformValueUnionRequest::class)->name)->toBe('value Ada');
});

#[ConfigObject('services.transform')]
readonly class TransformConfig
{
    public function __construct(public string $key) {}

    #[MapFromInput(static function (Repository $config): string {
        return $config->get('app.name');
    })]
    public string $appName;
}

it('passes the config repository to a #[MapFromInput] callable under Mapper::fromConfig()', function () {
    config(['services.transform' => ['key' => 'k'], 'app.name' => 'Roma']);

    expect(Mapper::fromConfig(TransformConfig::class)->appName)->toBe('Roma');
});

readonly class TransformResponse
{
    public function __construct(public string $id) {}

    #[MapFromInput(static function (Response $response): ?string {
        return $response->header('X-Rate');
    })]
    public ?string $rate;
}

it('passes the client response to a #[MapFromInput] callable under ResponsePolicy', function () {
    $response = new Response(new Psr7Response(200, ['X-Rate' => '5'], '{"id": "1"}'));

    $mapped = app(ObjectMapper::class)->map(TransformResponse::class, new ResponseInput($response, new RequestException($response)), new ResponsePolicy);

    expect($mapped->rate)->toBe('5');
});
