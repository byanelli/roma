<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\MapUsing;
use BYanelli\Roma\Attributes\SerializeUsing;
use BYanelli\Roma\Data\Attributes\MapTransform;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Missing;
use BYanelli\Roma\Serialization\Profile;
use BYanelli\Roma\Serialization\SerializeTransform;
use BYanelli\Roma\Tests\TestCase;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;
use Illuminate\Http\Request;
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
        #[MapUsing(trim(...)), MapUsing(strtolower(...))]
        public string $email,

        #[MapUsing(strrev(...))]
        public string|Missing $nickname,

        #[MapUsing(strtoupper(...))]
        public string $country = 'us',

        #[MapUsing(self::double(...))]
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
        #[MapUsing(trim(...))]
        public string $name,
    ) {}

    #[MapUsing(strtoupper(...))]
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

        #[SerializeUsing(strtoupper(...)), SerializeUsing(self::exclaim(...))]
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

it('rejects #[MapUsing] on a virtual property', function () {
    $class = new class
    {
        #[MapUsing(trim(...))]
        public string $name {
            get => 'Ada';
        }
    };

    expect(fn () => Mapper::fromArray($class::class, []))->toThrow(RuntimeException::class, '#[MapUsing] has no effect');
});

readonly class TransformRequestLead
{
    public function __construct(
        public string $first_name,
        public string $last_name,
    ) {}

    #[MapUsing(static function (Request $request): bool {
        return $request->boolean('current_cdl');
    })]
    public bool $current_cdl;

    #[MapUsing(static function (Request $request): string {
        return $request->input('full_name') ?: "{$request->input('first_name')} {$request->input('last_name')}";
    }), MapUsing(strtoupper(...))]
    public string $full_name;

    #[MapUsing(static function (Request $request): bool {
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
    #[MapUsing(static function (Request $request) {
        return $request->input('count');
    })]
    public string $fromRequest;

    #[MapUsing(strlen(...))]
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

it('rejects a callable that takes the request outside a request', function () {
    expect(fn () => Mapper::fromArray(TransformRequestLead::class, ['first_name' => 'Pat', 'last_name' => 'Lee']))
        ->toThrow(RuntimeException::class, 'takes Request, which is neither the property\'s type nor the input this mapping reads (ArrayInput)');
});

readonly class TransformLateRequest
{
    #[MapUsing(trim(...)), MapUsing(static function (Request $request): string {
        return 'x';
    })]
    public string $name;
}

readonly class TransformSourcedRequest
{
    #[Query, MapUsing(static function (Request $request): string {
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
    #[MapUsing(static function (Request $request): string {
        return 'x';
    })]
    public string $name;
}

it('rejects a misplaced callable that takes the request', function (string $class, string $message) {
    /** @var TestCase $this */
    $this->setRequest(query: ['name' => 'Ada', 'member' => []]);

    expect(fn () => $this->mapRequest($class))->toThrow(RuntimeException::class, $message);
})->with([
    [TransformLateRequest::class, 'takes the input after another map transform'],
    [TransformSourcedRequest::class, 'so it cannot map from a source or be inside a nested object'],
    [TransformNestedRequest::class, 'so it cannot map from a source or be inside a nested object'],
]);
