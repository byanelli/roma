<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\DateFormat;
use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Attributes\Guard;
use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Attributes\Limit;
use BYanelli\Roma\Attributes\MapFromInput;
use BYanelli\Roma\Attributes\MapFromValue;
use BYanelli\Roma\Attributes\Message;
use BYanelli\Roma\Attributes\OptionalOnUpdate;
use BYanelli\Roma\Attributes\RejectUnknownKeys;
use BYanelli\Roma\Attributes\RequiredOnCreate;
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Attributes\RulePerItem;
use BYanelli\Roma\Attributes\SnakeCaseKeys;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Data\Operation;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Http\Attributes\Header;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Http\Enums\AuthScheme;
use BYanelli\Roma\Http\Request\Injection\Request as RomaRequest;
use BYanelli\Roma\Http\Values\Authorization;
use BYanelli\Roma\Missing;
use BYanelli\Roma\Tests\TestCase;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

enum ValidatedStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}

readonly class ValidatedPosting
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        #[Rule('max:255')]
        public string $title,

        #[Rule('between:0,100')]
        public float $rate = 0.0,

        #[RulePerItem('max:5')]
        public array $tags = [],

        #[DateFormat('Y-m-d')]
        public ?CarbonImmutable $postedOn = null,

        public ValidatedStatus $status = ValidatedStatus::Open,
    ) {}
}

readonly class ValidatedContact
{
    public function __construct(
        #[RequiredOnCreate]
        public string|Missing $email,

        #[OptionalOnUpdate]
        public string|Missing $name,

        public string|Missing $note = 'none',
    ) {}
}

#[SnakeCaseKeys]
readonly class ValidatedCompany
{
    public function __construct(
        #[Rule('max:3')]
        public string $companyName,

        #[Key('title')]
        #[Rule('max:3')]
        public string $jobTitle,

        #[Key('location.city')]
        #[Rule('max:3')]
        public string $city,
    ) {}
}

#[RejectUnknownKeys]
readonly class ValidatedLine
{
    public function __construct(
        #[Rule('max:3')]
        public string $code,

        #[Rule('integer', 'min:1')]
        public int $quantity = 1,
    ) {}
}

readonly class ValidatedOrder
{
    /**
     * @param  list<ValidatedLine>  $lines
     */
    public function __construct(
        public ValidatedLine $first,
        public array $lines = [],
    ) {}
}

readonly class ValidatedCard
{
    public function __construct(
        #[Rule('digits:4')]
        public string $last4,
    ) {}
}

readonly class ValidatedVoucher
{
    public function __construct(
        #[Rule('max:3')]
        public string $code,
    ) {}
}

readonly class ValidatedPayment
{
    /**
     * @param  list<ValidatedCard|ValidatedVoucher>  $refunds
     */
    public function __construct(
        #[Discriminated([ValidatedCard::class, ValidatedVoucher::class])]
        public ValidatedCard|ValidatedVoucher $method,

        #[Discriminated([ValidatedCard::class, ValidatedVoucher::class])]
        public array $refunds = [],
    ) {}
}

readonly class ValidatedArrays
{
    /**
     * @param  list<int>  $counts
     * @param  list<ValidatedStatus>  $statuses
     * @param  list<bool>  $flags
     * @param  list<CarbonInterval>  $waits
     * @param  list<Authorization>  $credentials
     */
    public function __construct(
        public array $counts = [],
        public array $statuses = [],
        public array $flags = [],
        public array $waits = [],
        public array $credentials = [],
    ) {}
}

readonly class ValidatedWindow
{
    /**
     * @param  array{at: CarbonImmutable}  $window
     */
    public function __construct(
        public array $window,
    ) {}
}

readonly class ValidatedTransforms
{
    public function __construct(
        #[Rule('max:3')]
        #[MapFromValue(strtoupper(...))]
        public string $code,

        #[MapFromInput(static function (Request $request): string {
            return (string) $request->ip();
        })]
        #[Rule('max:1')]
        public string $ip = '127.0.0.1',
    ) {}
}

readonly class ValidatedLimited
{
    public function __construct(
        #[Limit(5, end: '')]
        #[Rule('alpha')]
        public string $code,

        #[Limit(5)]
        public string $summary = 'short',

        #[Limit(5, end: '')]
        #[Rule('string', 'max:10')]
        public string $name = 'abc',

        #[Limit(10, end: '')]
        #[Rule('max:4')]
        public string $tag = 'ab',
    ) {}
}

class ValidatedNode
{
    public ?ValidatedNode $next = null;
}

class ValidatedRequiredNode
{
    public ValidatedRequiredNode $parent;
}

class ValidatedTree
{
    /** @var list<ValidatedTree> */
    public array $children = [];
}

class ValidatedBranch
{
    #[Discriminated([ValidatedBranch::class, ValidatedVoucher::class])]
    public ValidatedBranch|ValidatedVoucher|null $child = null;
}

readonly class ValidatedAddress
{
    public function __construct(
        #[Rule('max:3')]
        public string $code,
    ) {}
}

readonly class ValidatedShipment
{
    public function __construct(
        public ValidatedAddress $billing,
        public ValidatedAddress $shipping,
    ) {}
}

class ValidatedGuardLog
{
    /** @var list<string> */
    public array $calls = [];
}

readonly class ValidatedGuarded
{
    public function __construct(
        #[Rule('max:3')]
        public string $code,
    ) {}

    #[Guard]
    public function record(ValidatedGuardLog $log): void
    {
        $log->calls[] = $this->code;
    }

    #[Guard]
    public function reject(): void
    {
        if ($this->code === 'no') {
            throw ValidationException::withMessages(['code' => 'rejected by guard']);
        }
    }
}

#[RomaRequest]
readonly class ValidatedSearch
{
    public function __construct(
        #[Query]
        #[Rule('max:5')]
        public string $q,

        #[Query('per_page')]
        #[Rule('between:1,50')]
        public int $perPage = 10,

        #[Header('X-Tenant')]
        #[Rule('max:3')]
        public string $tenant = 'abc',
    ) {}
}

readonly class ValidatedMessage
{
    public function __construct(
        #[Message(max: 'Keep the :attribute short.')]
        #[Rule('max:3')]
        public string $code,
    ) {}
}

/**
 * @return array<string, list<string>>
 */
function objectErrors(object $object, Operation $operation = Operation::None): array
{
    try {
        Mapper::validate($object, $operation);
    } catch (MappingValidationException $e) {
        return $e->errors();
    }

    return [];
}

it('returns the same instance when the object is valid', function () {
    $posting = new ValidatedPosting('Driver', 12.5, ['cdl'], new CarbonImmutable('2026-01-02'), ValidatedStatus::Closed);

    expect(Mapper::validate($posting))->toBe($posting);
});

it('works in a pipe', function () {
    $posting = new ValidatedPosting('Driver') |> Mapper::validate(...);

    expect($posting)->toBeInstanceOf(ValidatedPosting::class);
    expect(fn () => new ValidatedPosting(str_repeat('x', 256)) |> Mapper::validate(...))
        ->toThrow(MappingValidationException::class);
});

it('checks a string\'s length and a float\'s value', function () {
    expect(objectErrors(new ValidatedPosting(str_repeat('x', 256), 1000.0)))->toBe([
        'title' => ['The title field must not be greater than 255 characters.'],
        'rate' => ['The rate field must be between 0 and 100.'],
    ]);
});

it('applies #[RulePerItem]', function () {
    expect(objectErrors(new ValidatedPosting('Driver', tags: ['cdl', 'hazmat'])))->toBe([
        'tags.1' => ['The tags.1 field must not be greater than 5 characters.'],
    ]);
});

describe('Missing', function () {
    it('fails a required property in a Create', function () {
        expect(objectErrors(new ValidatedContact(Missing::instance(), Missing::instance(), Missing::instance()), Operation::Create))->toBe([
            'email' => ['The email field is required.'],
            'name' => ['The name field is required.'],
        ]);
    });

    it('fails an #[OptionalOnUpdate] property with no operation', function () {
        expect(objectErrors(new ValidatedContact(Missing::instance(), Missing::instance())))->toBe([
            'name' => ['The name field is required.'],
        ]);
    });

    it('passes in an Update', function () {
        expect(objectErrors(new ValidatedContact(Missing::instance(), Missing::instance()), Operation::Update))->toBe([]);
    });
});

it('reports errors under the input keys', function () {
    expect(array_keys(objectErrors(new ValidatedCompany('Acme', 'Driver', 'Boston'))))->toBe(['company_name', 'title', 'location.city']);
});

it('validates nested objects and lists of objects', function () {
    $order = new ValidatedOrder(new ValidatedLine('long'), [new ValidatedLine('ok'), new ValidatedLine('long', 0)]);

    expect(objectErrors($order))->toBe([
        'first.code' => ['The first.code field must not be greater than 3 characters.'],
        'lines.1.code' => ['The lines.1.code field must not be greater than 3 characters.'],
        'lines.1.quantity' => ['The lines.1.quantity field must be at least 1.'],
    ]);
});

it('validates the member of a discriminated union', function () {
    expect(objectErrors(new ValidatedPayment(new ValidatedVoucher('abc'), [new ValidatedCard('1234')])))->toBe([]);

    expect(objectErrors(new ValidatedPayment(new ValidatedVoucher('long'), [new ValidatedCard('12')])))->toBe([
        'method.code' => ['The method.code field must not be greater than 3 characters.'],
        'refunds.0.last4' => ['The refunds.0.last4 field must be 4 digits.'],
    ]);
});

describe('array contents, which PHP doesn\'t check', function () {
    it('rejects a string in a list<int>', function (string $value) {
        expect(objectErrors(new ValidatedArrays(counts: [1, $value])))->toBe([
            'counts.1' => ['The counts.1 field must be an integer.'],
        ]);
    })->with(['abc', '5']);

    it('rejects a string in a list of enums', function () {
        expect(objectErrors(new ValidatedArrays(statuses: [ValidatedStatus::Open, 'closed'])))->toBe([
            'statuses.1' => ['The statuses.1 field must be an instance of ValidatedStatus.'],
        ]);
    });

    it('rejects a string for a date in a shape', function () {
        expect(objectErrors(new ValidatedWindow(['at' => '2026-01-02'])))->toBe([
            'window.at' => ['The window.at field must be an instance of Carbon\CarbonImmutable.'],
        ]);
    });

    it('rejects a string in a list<bool>', function () {
        expect(array_keys(objectErrors(new ValidatedArrays(flags: [true, '1']))))->toBe(['flags.1']);
    });

    it('rejects strings for an interval and a string-parsed value object', function () {
        expect(array_keys(objectErrors(new ValidatedArrays(waits: ['90 seconds'], credentials: ['Bearer abc']))))->toBe(['waits.0', 'credentials.0']);
    });

    it('accepts values of the declared types', function () {
        $arrays = new ValidatedArrays([1, 2], [ValidatedStatus::Closed], [false], [CarbonInterval::seconds(90)], [new Authorization(AuthScheme::Bearer, 'abc')]);

        expect(objectErrors($arrays))->toBe([]);
        expect(objectErrors(new ValidatedWindow(['at' => CarbonImmutable::now()])))->toBe([]);
    });
});

it('skips #[MapFromValue] and #[MapFromInput] properties', function () {
    $transforms = new ValidatedTransforms('longer than three');

    expect(Mapper::validate($transforms))->toBe($transforms);
});

describe('#[Limit]', function () {
    it('fails a string longer than the limit', function () {
        expect(objectErrors(new ValidatedLimited('abcdef')))->toBe([
            'code' => ['The code field must not be greater than 5 characters.'],
        ]);
        expect(objectErrors(new ValidatedLimited('abcde')))->toBe([]);
    });

    it('still runs the other rules', function () {
        expect(objectErrors(new ValidatedLimited('ab1')))->toBe([
            'code' => ['The code field must only contain letters.'],
        ]);
    });

    it('allows the end that Str::limit() appends', function () {
        expect(objectErrors(new ValidatedLimited('abc', summary: 'abcde...')))->toBe([]);
        expect(objectErrors(new ValidatedLimited('abc', summary: 'abcdef...')))->toBe([
            'summary' => ['The summary field must not be greater than 8 characters.'],
        ]);
    });

    it('reports one message with a declared max', function () {
        expect(objectErrors(new ValidatedLimited('abc', name: 'abcdefghijkl', tag: 'abcde')))->toBe([
            'name' => ['The name field must not be greater than 5 characters.'],
            'tag' => ['The tag field must not be greater than 4 characters.'],
        ]);
    });
});

describe('an object that contains itself', function () {
    it('fails at a direct reference', function () {
        $node = new ValidatedNode;
        $node->next = $node;

        expect(objectErrors($node))->toBe([
            'next' => ['The next field refers to an object that contains it.'],
        ]);
    });

    it('fails at a cycle through a list, with the other errors', function () {
        $root = new ValidatedTree;
        $child = new ValidatedTree;
        $root->children = [$child];
        $child->children = [$root, 'leaf'];

        expect(objectErrors($root))->toBe([
            'children.0.children.0' => ['The children.0.children.0 field refers to an object that contains it.'],
            'children.0.children.1' => ['The children.0.children.1 field must be an array.'],
        ]);
    });

    it('fails at a cycle through a union member', function () {
        $branch = new ValidatedBranch;
        $branch->child = new ValidatedBranch;
        $branch->child->child = $branch;

        expect(objectErrors($branch))->toBe([
            'child.child' => ['The child.child field refers to an object that contains it.'],
        ]);
    });

    it('reports only the cycle for a required property', function () {
        $line = new ValidatedRequiredNode;
        $line->parent = $line;

        expect(objectErrors($line))->toBe([
            'parent' => ['The parent field refers to an object that contains it.'],
        ]);
    });

    it('validates an instance that appears at two paths', function () {
        $address = new ValidatedAddress('long');

        expect(objectErrors(new ValidatedShipment($address, $address)))->toBe([
            'billing.code' => ['The billing.code field must not be greater than 3 characters.'],
            'shipping.code' => ['The shipping.code field must not be greater than 3 characters.'],
        ]);

        $address = new ValidatedAddress('abc');

        expect(objectErrors(new ValidatedShipment($address, $address)))->toBe([]);
    });
});

describe('guards', function () {
    it('runs them after validation passes', function () {
        /** @var TestCase $this */
        $this->app->instance(ValidatedGuardLog::class, $log = new ValidatedGuardLog);

        Mapper::validate(new ValidatedGuarded('yes'));

        expect($log->calls)->toBe(['yes']);
    });

    it('lets a guard reject the object', function () {
        expect(fn () => Mapper::validate(new ValidatedGuarded('no')))->toThrow(ValidationException::class, 'rejected by guard');
    });

    it('doesn\'t run them when validation fails', function () {
        /** @var TestCase $this */
        $this->app->instance(ValidatedGuardLog::class, $log = new ValidatedGuardLog);

        expect(fn () => Mapper::validate(new ValidatedGuarded('long')))->toThrow(MappingValidationException::class);
        expect($log->calls)->toBe([]);
    });
});

it('validates a request class\'s properties from any source', function () {
    expect(objectErrors(new ValidatedSearch('trucks', 100, 'acme')))->toBe([
        'q' => ['The q field must not be greater than 5 characters.'],
        'per_page' => ['The per page field must be between 1 and 50.'],
        'x_tenant' => ['The x tenant field must not be greater than 3 characters.'],
    ]);
});

it('uses #[Message]', function () {
    expect(objectErrors(new ValidatedMessage('long')))->toBe([
        'code' => ['Keep the code short.'],
    ]);
});
