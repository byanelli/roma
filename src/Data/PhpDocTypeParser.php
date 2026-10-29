<?php

namespace BYanelli\Roma\Data;

use Closure;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;

class PhpDocTypeParser
{
    public function __construct(private readonly ClassNameResolver $classNames = new ClassNameResolver) {}

    private const array ARRAY_TYPES = ['array', 'non-empty-array', 'list', 'non-empty-list'];

    /**
     * PHPDoc type keywords, which are never class names.
     */
    private const array KEYWORDS = [
        'int', 'integer', 'string', 'bool', 'boolean', 'float', 'double', 'mixed', 'object', 'true', 'false',
        'null', 'callable', 'iterable', 'void', 'never', 'scalar', 'numeric', 'resource', 'parent',
    ];

    private function parsePhpDoc(string $phpDoc): PhpDocNode
    {
        $config = new ParserConfig(usedAttributes: []);
        $lexer = new Lexer($config);
        $constExprParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constExprParser);
        $parser = new PhpDocParser($config, $typeParser, $constExprParser);

        $tokens = new TokenIterator($lexer->tokenize($phpDoc));

        return $parser->parse($tokens);
    }

    private function getArrayTypePhpDocNode(ReflectionParameter|ReflectionProperty $obj): TypeNode
    {
        if ($obj instanceof ReflectionParameter) {
            $constructorPhpDoc = $obj->getDeclaringClass()
                ?->getConstructor()
                ?->getDocComment();

            if ($constructorPhpDoc === null) {
                throw new RuntimeException("{$obj->getName()} must be declared in a constructor");
            }

            if ($constructorPhpDoc === false) {
                throw new RuntimeException('Constructor params with array types must be documented in PHPDoc');
            }

            $parsedPhpDoc = $this->parsePhpDoc($constructorPhpDoc);

            /** @var ParamTagValueNode $phpDocPropertyNode */
            $phpDocPropertyNode = collect($parsedPhpDoc->getParamTagValues())
                ->first(fn (ParamTagValueNode $param) => $param->parameterName == '$'.$obj->getName())
                ?: throw new RuntimeException("Constructor param {$obj->getName()} not found in PHPDoc");

            return $phpDocPropertyNode->type;
        } else {
            $propertyPhpDoc = $obj->getDocComment();

            if ($propertyPhpDoc === false) {
                throw new RuntimeException('Properties with array type must be documented by @var in PHPDoc');
            }

            $parsedPhpDoc = $this->parsePhpDoc($propertyPhpDoc);

            /** @var VarTagValueNode $phpDocPropertyNode */
            $phpDocPropertyNode = collect($parsedPhpDoc->getVarTagValues())
                ->first()
                ?: throw new RuntimeException("Property {$obj->getName()} @var tag not found in PHPDoc");

            return $phpDocPropertyNode->type;
        }
    }

    /**
     * The type of an array-typed property or constructor parameter, read from its PHPDoc.
     * `$namedType` returns the type for a resolved scalar or class name, or for the
     * resolved names of a union such as `Card|Voucher`.
     *
     * @param  Closure(list<string>): Type  $namedType
     */
    public function getArrayType(ReflectionParameter|ReflectionProperty $obj, Closure $namedType): Types\Array_|Types\Shape
    {
        $node = $this->getArrayTypePhpDocNode($obj);

        $arrayNode = $this->findArrayNode($node)
            ?? throw new RuntimeException("Error parsing array element type from type declaration: $node");

        return $arrayNode instanceof ArrayShapeNode
            ? $this->shape($arrayNode, $obj, $namedType)
            : $this->array($arrayNode, $obj, $namedType);
    }

    /**
     * Finds the array node of a declared type, also when it is nullable or in
     * a union such as `list<T>|null`.
     */
    private function findArrayNode(TypeNode $node): GenericTypeNode|ArrayShapeNode|null
    {
        if ($node instanceof NullableTypeNode) {
            return $this->findArrayNode($node->type);
        }

        if ($node instanceof UnionTypeNode) {
            foreach ($node->types as $member) {
                if ($found = $this->findArrayNode($member)) {
                    return $found;
                }
            }

            return null;
        }

        if ($node instanceof ArrayShapeNode
            || ($node instanceof GenericTypeNode && in_array((string) $node->type, self::ARRAY_TYPES, true))) {
            return $node;
        }

        return null;
    }

    /**
     * @param  Closure(list<string>): Type  $namedType
     */
    private function typeFromNode(TypeNode $node, ReflectionParameter|ReflectionProperty $obj, Closure $namedType): Type
    {
        return match (true) {
            $node instanceof ArrayShapeNode => $this->shape($node, $obj, $namedType),
            $node instanceof GenericTypeNode && in_array((string) $node->type, self::ARRAY_TYPES, true) => $this->array($node, $obj, $namedType),
            $node instanceof IdentifierTypeNode && ! in_array($node->name, [...self::ARRAY_TYPES, 'iterable'], true) => $namedType([$this->resolveTypeName($node->name, $obj)]),
            $node instanceof UnionTypeNode && $this->isUnionOfNames($node) => $namedType(array_values(array_map(
                fn (TypeNode $member) => $this->resolveTypeName((string) $member, $obj),
                $node->types,
            ))),
            default => throw new RuntimeException("Unsupported array element type: $node"),
        };
    }

    private function isUnionOfNames(UnionTypeNode $node): bool
    {
        foreach ($node->types as $member) {
            if (! $member instanceof IdentifierTypeNode || strtolower($member->name) === 'null') {
                return false;
            }
        }

        return true;
    }

    /**
     * `array<T>`, `list<T>` or `array<K, T>`.
     *
     * @param  Closure(list<string>): Type  $namedType
     */
    private function array(GenericTypeNode $node, ReflectionParameter|ReflectionProperty $obj, Closure $namedType): Types\Array_
    {
        $isList = in_array((string) $node->type, ['list', 'non-empty-list'], true);

        return match (count($node->genericTypes)) {
            1 => new Types\Array_($this->typeFromNode($node->genericTypes[0], $obj, $namedType), $isList),
            2 => new Types\Array_(
                $this->typeFromNode($node->genericTypes[1], $obj, $namedType),
                keyType: $isList ? throw new RuntimeException("A list has no key type: $node") : $this->keyType($node),
            ),
            default => throw new RuntimeException("Error parsing array element type from type declaration: $node"),
        };
    }

    private function keyType(GenericTypeNode $node): Types\Integer|Types\String_
    {
        $key = $node->genericTypes[0];

        return match ($key instanceof IdentifierTypeNode ? $key->name : null) {
            'int' => new Types\Integer,
            'string' => new Types\String_,
            default => throw new RuntimeException("Array keys must be int or string: $node"),
        };
    }

    /**
     * @param  Closure(list<string>): Type  $namedType
     */
    private function shape(ArrayShapeNode $node, ReflectionParameter|ReflectionProperty $obj, Closure $namedType): Types\Shape
    {
        $items = [];

        foreach ($node->items as $position => $item) {
            $key = match (true) {
                $item->keyName === null => (string) $position,
                $item->keyName instanceof ConstExprStringNode, $item->keyName instanceof ConstExprIntegerNode => $item->keyName->value,
                $item->keyName instanceof IdentifierTypeNode => $item->keyName->name,
                default => throw new RuntimeException("Unsupported array shape key: $item"),
            };

            [$valueNode, $nullable] = $this->withoutNull($item->valueType);

            $items[] = new Types\ShapeItem($key, $this->typeFromNode($valueNode, $obj, $namedType), $item->optional, $nullable);
        }

        return new Types\Shape($items, $node->sealed);
    }

    /**
     * Splits `?T` or `T|null` into T and whether null is allowed.
     *
     * @return array{TypeNode, bool}
     */
    private function withoutNull(TypeNode $node): array
    {
        if ($node instanceof NullableTypeNode) {
            return [$node->type, true];
        }

        if ($node instanceof UnionTypeNode && count($node->types) === 2) {
            foreach ($node->types as $i => $member) {
                if ($member instanceof IdentifierTypeNode && strtolower($member->name) === 'null') {
                    return [$node->types[1 - $i], true];
                }
            }
        }

        return [$node, false];
    }

    /**
     * Resolves a class name as PHP would in the source that declares the property.
     */
    private function resolveTypeName(string $name, ReflectionParameter|ReflectionProperty $obj): string
    {
        $class = $obj->getDeclaringClass();

        if (in_array(strtolower($name), ['self', 'static'], true) && $class !== null) {
            return $class->getName();
        }

        if (in_array(strtolower($name), self::KEYWORDS, true) || preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*$/', $name) !== 1) {
            return $name;
        }

        $scope = $this->classNames->declaringScope($obj);
        $candidates = array_unique([
            $scope === null ? ltrim($name, '\\') : $this->classNames->resolve($name, $scope),
            // Not a PHP rule: also try the name as written, e.g. `App\Card` or `DateTimeImmutable` without an import.
            ltrim($name, '\\'),
        ]);

        foreach ($candidates as $candidate) {
            if ($this->classExists($candidate)) {
                return $candidate;
            }
        }

        throw $this->unresolvable("the class name {$name}; tried ".implode(' and ', $candidates)
            .(count($candidates) === 1 ? ', which does not exist' : ', and neither exists'), $obj);
    }

    private function classExists(string $name): bool
    {
        return class_exists($name) || interface_exists($name);
    }

    private function unresolvable(string $problem, ReflectionParameter|ReflectionProperty $obj): UnresolvableClassName
    {
        $printableName = ($obj->getDeclaringClass()?->getName() ?? '').'::$'.$obj->getName();

        return new UnresolvableClassName(
            "Roma cannot read the PHPDoc type {$this->getArrayTypePhpDocNode($obj)} of {$printableName}: it contains {$problem}."
        );
    }
}
