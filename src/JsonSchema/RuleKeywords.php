<?php

namespace BYanelli\Roma\JsonSchema;

use Closure;
use DateTimeInterface;
use Illuminate\JsonSchema\Types as Schema;
use Illuminate\Validation\ValidationRuleParser;
use Stringable;

/**
 * Adds the JSON Schema keywords that match a property's validation rules. A rule with no
 * equivalent is left out of the schema; the mapper still enforces it.
 *
 * @internal
 */
final readonly class RuleKeywords
{
    /**
     * PHP date formats with a JSON Schema format.
     */
    private const array DATE_FORMATS = [
        'Y-m-d' => 'date',
        DateTimeInterface::ATOM => 'date-time',
    ];

    private const array STRING_FORMATS = [
        'Email' => 'email',
        'Url' => 'uri',
        'Uuid' => 'uuid',
        'Date' => 'date-time',
    ];

    /**
     * @param  array<int, mixed>  $rules
     */
    public function apply(Schema\Type $schema, array $rules): void
    {
        foreach ($rules as $rule) {
            if ($rule instanceof Closure || ! (is_string($rule) || $rule instanceof Stringable)) {
                continue;
            }

            [$name, $parameters] = ValidationRuleParser::parse((string) $rule);

            if (is_string($name) && is_array($parameters)) {
                $this->applyRule($schema, $name, array_values(array_filter($parameters, is_string(...))));
            }
        }
    }

    /**
     * @param  list<string>  $parameters
     */
    private function applyRule(Schema\Type $schema, string $name, array $parameters): void
    {
        [$min, $max] = match ($name) {
            'Min' => [$parameters[0] ?? null, null],
            'Max' => [null, $parameters[0] ?? null],
            'Between' => [$parameters[0] ?? null, $parameters[1] ?? null],
            'Size' => [$parameters[0] ?? null, $parameters[0] ?? null],
            default => [null, null],
        };

        $this->bound($schema, 'min', $min);
        $this->bound($schema, 'max', $max);

        match ($name) {
            'Required', 'Present' => $schema->required(),
            'In' => $this->in($schema, $parameters),
            'Email', 'Url', 'Uuid', 'Date' => $schema instanceof Schema\StringType ? $schema->format(self::STRING_FORMATS[$name]) : null,
            'DateFormat' => $schema instanceof Schema\StringType ? $this->dateFormat($schema, $parameters[0] ?? null) : null,
            'Regex' => $this->regex($schema, $parameters[0] ?? ''),
            default => null,
        };
    }

    /**
     * A string's length, a number's value or an array's item count, as Laravel's size rules measure it.
     *
     * @param  'min'|'max'  $method
     */
    private function bound(Schema\Type $schema, string $method, ?string $parameter): void
    {
        $number = $parameter !== null ? $this->number($parameter) : null;

        match (true) {
            $number === null => null,
            $schema instanceof Schema\NumberType => $schema->{$method}($number),
            $schema instanceof Schema\StringType, $schema instanceof Schema\ArrayType, $schema instanceof Schema\IntegerType => is_int($number) ? $schema->{$method}($number) : null,
            default => null,
        };
    }

    private function number(string $value): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);

        return $int !== false ? $int : (float) $value;
    }

    /**
     * @param  list<string>  $values
     */
    private function in(Schema\Type $schema, array $values): void
    {
        match (true) {
            $schema instanceof Schema\StringType => $schema->enum($values),
            $schema instanceof Schema\IntegerType, $schema instanceof Schema\NumberType => $this->numericEnum($schema, $values),
            default => null,
        };
    }

    /**
     * @param  list<string>  $values
     */
    private function numericEnum(Schema\IntegerType|Schema\NumberType $schema, array $values): void
    {
        $numbers = array_map($this->number(...), $values);

        if (! in_array(null, $numbers, true) && ($schema instanceof Schema\NumberType || array_filter($numbers, is_float(...)) === [])) {
            $schema->enum($numbers);
        }
    }

    /**
     * A format JSON Schema has no keyword for is added to the description, since JSON Schema
     * has no strftime-style formats.
     */
    public function dateFormat(Schema\StringType $schema, ?string $format): Schema\StringType
    {
        if ($format === null) {
            return $schema->format('date-time');
        }

        if (isset(self::DATE_FORMATS[$format])) {
            return $schema->format(self::DATE_FORMATS[$format]);
        }

        $note = "Format (PHP date): {$format}";
        $description = (fn () => $this->description)->call($schema);

        return $schema->description(is_string($description) ? "{$description} {$note}" : $note);
    }

    /**
     * Removes the PHP delimiters. A pattern with modifiers other than `u` is left out, since
     * JSON Schema patterns have no flags.
     */
    private function regex(Schema\Type $schema, string $regex): void
    {
        if (! $schema instanceof Schema\StringType || strlen($regex) < 2 || ctype_alnum($regex[0]) || $regex[0] === '\\') {
            return;
        }

        $open = $regex[0];
        $close = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$open] ?? $open;
        $end = strrpos($regex, $close);

        if ($end === false || $end === 0 || trim(substr($regex, $end + 1), 'u') !== '') {
            return;
        }

        $body = substr($regex, 1, $end - 1);

        if (! str_contains('^$\\.*+?()[]{}|', $open)) {
            $body = str_replace('\\'.$open, $open, $body);
        }

        $schema->pattern($body);
    }
}
