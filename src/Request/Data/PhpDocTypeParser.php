<?php

namespace BYanelli\Roma\Request\Data;

use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
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
     * @return array{elementTypeName: string, isList: bool}
     */
    private function parseArrayTypeFromPhpDocNode(TypeNode $node): array
    {
        $generic = $this->findArrayGenericNode($node);
        $elementType = $generic?->genericTypes[0] ?? null;

        if ($generic === null || count($generic->genericTypes) !== 1 || ! $elementType instanceof IdentifierTypeNode) {
            throw new RuntimeException("Error parsing array element type from type declaration: $node");
        }

        return [
            'elementTypeName' => ltrim($elementType->name, '\\'),
            'isList' => in_array($generic->type->name, ['list', 'non-empty-list'], true),
        ];
    }

    /**
     * Accepts `array<T>` and `list<T>` (and their non-empty- forms), also when
     * nullable or in a union such as `list<T>|null`.
     */
    private function findArrayGenericNode(TypeNode $node): ?GenericTypeNode
    {
        if ($node instanceof NullableTypeNode) {
            return $this->findArrayGenericNode($node->type);
        }

        if ($node instanceof UnionTypeNode) {
            foreach ($node->types as $member) {
                if ($found = $this->findArrayGenericNode($member)) {
                    return $found;
                }
            }

            return null;
        }

        if ($node instanceof GenericTypeNode
            && in_array($node->type->name, ['array', 'non-empty-array', 'list', 'non-empty-list'], true)) {
            return $node;
        }

        return null;
    }

    /**
     * @return array{elementTypeName: string, isList: bool}
     */
    public function getArrayType(ReflectionParameter|ReflectionProperty $obj): array
    {
        $parsed = $this->parseArrayTypeFromPhpDocNode($this->getArrayTypePhpDocNode($obj));

        return [
            'elementTypeName' => $this->resolveTypeName($parsed['elementTypeName'], $obj),
            'isList' => $parsed['isList'],
        ];
    }

    /**
     * Resolve a parsed element type name to something loadable. Scalars and
     * names that already resolve (FQCNs) are used as-is; a bare short name is
     * resolved against the declaring class's namespace. Full use-statement
     * resolution is out of scope — FQCN or same-namespace is the story.
     */
    private function resolveTypeName(string $name, ReflectionParameter|ReflectionProperty $obj): string
    {
        if (in_array($name, ['int', 'string', 'bool', 'float'], true)
            || class_exists($name)
            || enum_exists($name)) {
            return $name;
        }

        $namespace = $obj->getDeclaringClass()?->getNamespaceName() ?? '';

        return $namespace === '' ? $name : $namespace.'\\'.$name;
    }
}
