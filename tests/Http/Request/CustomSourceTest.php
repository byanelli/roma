<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Data\Attributes\ExplicitKeyAttribute;
use BYanelli\Roma\Data\Attributes\SourceAttribute;
use BYanelli\Roma\Data\Source;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Http\Attributes\Accessors\Ajax;
use BYanelli\Roma\Http\Attributes\Query;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * @extends Source<Request>
 */
final readonly class Tenant extends Source
{
    public function getKey(): string
    {
        return 'tenant';
    }

    public function inputType(): string
    {
        return Request::class;
    }

    /**
     * @param  Request  $input
     * @return array<string, mixed>
     */
    protected function read(object $input): array
    {
        return $input->attributes->all();
    }
}

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class FromTenant implements ExplicitKeyAttribute, SourceAttribute
{
    public function __construct(public ?string $key = null) {}

    public function getSource(): Source
    {
        return new Tenant;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }
}

/**
 * A source under a key that #[Query] already uses.
 *
 * @extends Source<Request>
 */
final readonly class QueryLookalike extends Source
{
    public function getKey(): string
    {
        return 'query';
    }

    public function inputType(): string
    {
        return Request::class;
    }

    /**
     * @param  Request  $input
     */
    protected function read(object $input): array
    {
        return [];
    }
}

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class FromQueryLookalike implements SourceAttribute
{
    public function getSource(): Source
    {
        return new QueryLookalike;
    }
}

readonly class TenantRequest
{
    public function __construct(
        #[FromTenant('tenant_id')]
        #[Rule('min:1')]
        public int $tenantId,
        #[FromTenant]
        public string $plan,
        public string $name,
    ) {}
}

readonly class CollidingSourcesRequest
{
    public function __construct(
        #[Query]
        public string $page,
        #[FromQueryLookalike]
        public string $other,
    ) {}
}

readonly class AjaxTwiceRequest
{
    public function __construct(
        #[Ajax]
        public bool $ajax,
        #[Ajax(mustBe: true)]
        public bool $alsoAjax,
    ) {}
}

#[Ajax]
readonly class AjaxOnClassAndPropertyRequest
{
    public function __construct(
        #[Ajax]
        public bool $ajax,
    ) {}
}

function tenantRequest(array $attributes, array $query = []): Request
{
    $request = Request::create('/', 'GET', $query);
    $request->attributes->add($attributes);

    return $request;
}

it('maps a property from a custom source', function () {
    /** @var TestCase $this */
    $this->app->instance('request', tenantRequest(['tenant_id' => '7', 'plan' => 'pro'], ['name' => 'Acme']));

    $request = $this->mapRequest(TenantRequest::class);

    expect($request->tenantId)->toBe(7)
        ->and($request->plan)->toBe('pro')
        ->and($request->name)->toBe('Acme');
});

it('validates a custom source under its own prefix', function () {
    /** @var TestCase $this */
    $this->app->instance('request', tenantRequest(['tenant_id' => '0'], ['name' => 'Acme']));

    try {
        $this->mapRequest(TenantRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe([
            'tenant.tenant_id' => ['The tenant.tenant_id field must be at least 1.'],
            'tenant.plan' => ['The tenant.plan field is required.'],
        ]);
    }
});

it('rejects a source whose input type the policy does not provide', function () {
    expect(fn () => Mapper::fromArray(TenantRequest::class, ['name' => 'Acme']))
        ->toThrow(RuntimeException::class, 'maps from the "tenant" source, which ArrayPolicy does not allow: the source reads from Request, and ArrayPolicy maps from ArrayInput.');
});

it('rejects two sources with the same key', function () {
    /** @var TestCase $this */
    $this->setRequest(query: ['page' => '1']);

    expect(fn () => $this->mapRequest(CollidingSourcesRequest::class))
        ->toThrow(RuntimeException::class, 'Roma class CollidingSourcesRequest maps $page and $other from two different sources with the key "query" (Query and QueryLookalike)');
});

it('reads one accessor for several properties and the class', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['X-Requested-With' => 'XMLHttpRequest']);

    $request = $this->mapRequest(AjaxTwiceRequest::class);

    expect($request->ajax)->toBeTrue()
        ->and($request->alsoAjax)->toBeTrue()
        ->and($this->mapRequest(AjaxOnClassAndPropertyRequest::class)->ajax)->toBeTrue();

    $this->setRequest();

    expect(fn () => $this->mapRequest(AjaxOnClassAndPropertyRequest::class))
        ->toThrow(ValidationException::class, 'The request.ajax field must be accepted.');
});
