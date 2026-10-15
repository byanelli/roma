<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\AutoDiscriminated;
use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Data\ArrayPayload;
use BYanelli\Roma\Data\ArrayPolicy;
use BYanelli\Roma\Data\Discriminator;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Discovery\DiscriminatedMembers;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\JsonSchema\ClassSchema;
use BYanelli\Roma\Serialization\Profile;
use BYanelli\Roma\Tests\Fixtures\AutoDiscriminated\Animals\Animal;
use BYanelli\Roma\Tests\Fixtures\AutoDiscriminated\Animals\Cat;
use BYanelli\Roma\Tests\Fixtures\AutoDiscriminated\Animals\Dog;
use BYanelli\Roma\Tests\Fixtures\AutoDiscriminated\Duplicates\Vehicle;
use BYanelli\Roma\Tests\Fixtures\AutoDiscriminated\Lonely\Lonely;
use BYanelli\Roma\Tests\Fixtures\AutoDiscriminated\Shapes\Circle;
use BYanelli\Roma\Tests\Fixtures\AutoDiscriminated\Shapes\Drawing;
use BYanelli\Roma\Tests\Fixtures\AutoDiscriminated\Shapes\Shape;
use BYanelli\Roma\Tests\Fixtures\AutoDiscriminated\Shapes\Square;
use BYanelli\Roma\Tests\Fixtures\AutoDiscriminated\Shapes\Triangle;
use BYanelli\Roma\Tests\TestCase;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;
use Illuminate\Support\ServiceProvider;

function autoFixtures(string ...$directories): array
{
    return array_map(fn (string $directory) => dirname(__DIR__)."/Fixtures/AutoDiscriminated/{$directory}", $directories);
}

function autoMembers(): DiscriminatedMembers
{
    return app(DiscriminatedMembers::class);
}

/**
 * Drops the members already read in this application, as a new process would.
 */
function freshAutoMembers(): DiscriminatedMembers
{
    app()->forgetInstance(DiscriminatedMembers::class);

    return autoMembers();
}

function writeAutoCache(array $members): void
{
    file_put_contents(autoMembers()->cachePath(), '<?php return '.var_export(['auto_discriminated' => $members], true).';');
}

/**
 * @template T
 *
 * @param  class-string<T>  $class
 * @return T
 */
function mapAuto(string $class, array $data): mixed
{
    return app(ObjectMapper::class)->map($class, new ArrayPayload($data), new ArrayPolicy);
}

beforeEach(function () {
    config()->set('roma.discover', autoFixtures('Shapes', 'Animals'));
    autoMembers()->clear();
});

afterEach(function () {
    autoMembers()->clear();
});

it('finds the concrete members of each #[AutoDiscriminated] contract in the discover directories', function () {
    expect(autoMembers()->all())->toBe([
        Animal::class => [Cat::class, Dog::class],
        Shape::class => [Circle::class, Square::class, Triangle::class],
    ]);
});

it('builds the same union as #[Discriminated], with #[DiscriminatorValue] values', function () {
    $discriminator = Discriminator::ofContract(Shape::class);

    expect($discriminator->key)->toBe('type')
        ->and($discriminator->classes)->toBe(['Circle' => Circle::class, 'sq' => Square::class, 'Triangle' => Triangle::class])
        ->and(Discriminator::ofContract(Animal::class)->key)->toBe('kind');
});

it('maps and serializes discovered members', function () {
    $data = [
        'main' => ['type' => 'sq', 'side' => 2.0],
        'pet' => ['kind' => 'Cat', 'indoor' => false, 'name' => 'Tom'],
        'extras' => [['type' => 'Circle', 'radius' => 1.5], ['type' => 'Triangle', 'sides' => 3]],
    ];

    $drawing = mapAuto(Drawing::class, $data);

    expect($drawing)->toEqual(new Drawing(new Square(2.0), new Cat('Tom', false), [new Circle(1.5), new Triangle(3)]))
        ->and(Serializer::toArray($drawing, Profile::storage()))->toBe($data);
});

it('reports an unknown discriminator value as a validation error', function () {
    try {
        mapAuto(Drawing::class, ['main' => ['type' => 'Hexagon'], 'pet' => ['kind' => 'Dog', 'name' => 'Rex']]);
    } catch (MappingValidationException $e) {
        expect($e->errors())->toBe(['data.main.type' => ['The data.main.type field must be one of: Circle, sq, Triangle.']]);

        return;
    }

    $this->fail('Expected a MappingValidationException');
});

it('emits discovered members in TypeScript and JSON Schema', function () {
    $typescript = new TypeScriptGenerator([Drawing::class])->generate();

    expect($typescript)
        ->toContain("export type Shape = ({ type: 'Circle' } & Circle) | ({ type: 'sq' } & Square) | ({ type: 'Triangle' } & Triangle);")
        ->toContain("export type Animal = ({ kind: 'Cat' } & Cat) | ({ kind: 'Dog' } & Dog);")
        ->toContain('  main: Shape;');

    $values = array_map(
        fn (array $member) => $member['properties']['type']['enum'],
        ClassSchema::properties(Drawing::class)['main']->toArray()['anyOf'],
    );

    expect($values)->toBe([['Circle'], ['sq'], ['Triangle']]);
});

it('throws when two members have the same discriminator value', function () {
    config()->set('roma.discover', autoFixtures('Duplicates'));

    expect(fn () => Discriminator::ofContract(Vehicle::class))
        ->toThrow(RuntimeException::class, 'both have the discriminator value "Car"');
});

it('throws when a contract has no members', function () {
    config()->set('roma.discover', autoFixtures('Lonely'));

    expect(fn () => Discriminator::ofContract(Lonely::class))
        ->toThrow(RuntimeException::class, 'Roma #[AutoDiscriminated] on '.Lonely::class.' found no concrete class');
});

it('throws for a contract with both attributes', function () {
    $contract = new #[Discriminated([Circle::class]), AutoDiscriminated] class {};

    expect(fn () => Discriminator::ofContract($contract::class))
        ->toThrow(RuntimeException::class, 'has both #[Discriminated] and #[AutoDiscriminated]');
});

it('writes the members to the cache file and reads them from it', function () {
    /** @var TestCase $this */
    $this->artisan('roma:cache')->assertSuccessful();

    $path = autoMembers()->cachePath();

    expect($path)->toEndWith('bootstrap/cache/roma.php')
        ->and((require $path)['auto_discriminated'])->toBe(autoMembers()->scan());

    config()->set('roma.discover', []);

    expect(freshAutoMembers()->of(Shape::class))->toBe([Circle::class, Square::class, Triangle::class]);
});

readonly class AutoStaleHolder
{
    public function __construct(public Shape $shape) {}
}

it('uses a stale cache as it is', function () {
    writeAutoCache([Shape::class => [Circle::class]]);

    expect(freshAutoMembers()->of(Shape::class))->toBe([Circle::class])
        ->and(Discriminator::ofContract(Shape::class)->classes)->toBe(['Circle' => Circle::class]);

    expect(fn () => mapAuto(AutoStaleHolder::class, ['shape' => ['type' => 'sq', 'side' => 1]]))
        ->toThrow(fn (MappingValidationException $e) => expect($e->errors())
            ->toBe(['data.shape.type' => ['The data.shape.type field must be one of: Circle.']]));

    expect(fn () => Discriminator::ofContract(Animal::class))
        ->toThrow(RuntimeException::class, 'found no concrete class that extends or implements it in the roma.discover directories. '
            .'The members are read from');
});

it('adds a roma:cache hint to definition errors when the cache file exists', function () {
    writeAutoCache([Shape::class => ['App\\Shapes\\Deleted']]);
    freshAutoMembers();

    expect(fn () => Discriminator::ofContract(Shape::class))
        ->toThrow(RuntimeException::class, 'which is not a concrete class. The members are read from '.autoMembers()->cachePath()
            .'; if you added or removed an implementation, run php artisan roma:cache.');
});

it('deletes the cache file with roma:clear', function () {
    /** @var TestCase $this */
    writeAutoCache([]);

    $this->artisan('roma:clear')->assertSuccessful();

    expect(autoMembers()->isCached())->toBeFalse();
});

it('runs with optimize and optimize:clear', function () {
    /** @var TestCase $this */
    expect(ServiceProvider::$optimizeCommands['roma'] ?? null)->toBe('roma:cache')
        ->and(ServiceProvider::$optimizeClearCommands['roma'] ?? null)->toBe('roma:clear');

    $this->artisan('optimize', ['--except' => 'config,events,routes,views'])->assertSuccessful();

    expect(autoMembers()->isCached())->toBeTrue();

    $this->artisan('optimize:clear', ['--except' => 'config,cache,compiled,events,routes,views'])->assertSuccessful();

    expect(autoMembers()->isCached())->toBeFalse();
});

class AutoOnProperty
{
    public function __construct(
        #[AutoDiscriminated] public ?Shape $shape = null,
    ) {}
}

it('rejects #[AutoDiscriminated] on a property', function () {
    $message = 'Roma property "AutoOnProperty::$shape" has #[AutoDiscriminated], which applies only to an interface or abstract class';

    expect(fn () => mapAuto(AutoOnProperty::class, []))->toThrow(RuntimeException::class, $message)
        ->and(fn () => Serializer::toArray(new AutoOnProperty))->toThrow(RuntimeException::class, $message)
        ->and(fn () => ClassSchema::object(AutoOnProperty::class))->toThrow(RuntimeException::class, $message)
        ->and(fn () => new TypeScriptGenerator(responses: [AutoOnProperty::class])->generate())
        ->toThrow(RuntimeException::class, 'list the classes on the property with #[Discriminated([...])]');
});
