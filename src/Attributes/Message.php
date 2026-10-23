<?php

namespace BYanelli\Roma\Attributes;

use Attribute;
use BYanelli\Roma\Validation\Rules\EnumCase;
use BYanelli\Roma\Validation\Rules\ObjectValue;
use BYanelli\Roma\Validation\Rules\RequiredWithinObject;
use BYanelli\Roma\Validation\Rules\ScalarBoolean;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Custom validation messages for a property, keyed by Laravel rule name:
 * `#[Message(required: 'Send a name.')]`. A message without a name applies to all of the
 * property's rules. Messages may use Laravel's placeholders, such as `:attribute`.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Message
{
    /**
     * Roma checks these rules with rule objects, whose messages Laravel looks up by the snake
     * case of the class name. "object" is Roma's own rule, which rejects a list for an object.
     */
    private const array RULE_OBJECTS = [
        'required' => RequiredWithinObject::class,
        'boolean' => ScalarBoolean::class,
        'enum' => EnumCase::class,
        'object' => ObjectValue::class,
    ];

    /**
     * @var array<int|string, string>
     */
    public array $messages;

    public function __construct(string ...$messages)
    {
        if ($messages === []) {
            throw new InvalidArgumentException('Roma #[Message] needs at least one message.');
        }

        $this->messages = $messages;
    }

    /**
     * Laravel's custom messages for the field with rule key `$key`, e.g.
     * `["email.required" => "Send an email address."]`.
     *
     * @return array<string, string>
     */
    public function forKey(string $key): array
    {
        $messages = [];

        foreach ($this->messages as $rule => $message) {
            if (is_int($rule)) {
                $messages[$key] = $message;

                continue;
            }

            $messages["{$key}.{$rule}"] = $message;

            if (isset(self::RULE_OBJECTS[$rule])) {
                $messages["{$key}.".Str::snake(class_basename(self::RULE_OBJECTS[$rule]))] = $message;
            }
        }

        return $messages;
    }
}
