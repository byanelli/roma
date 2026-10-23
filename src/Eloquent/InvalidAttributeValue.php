<?php

namespace BYanelli\Roma\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Throwable;
use UnexpectedValueException;

/**
 * A stored attribute value that an AsRoma cast can't read.
 */
class InvalidAttributeValue extends UnexpectedValueException
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly mixed $modelKey,
        public readonly string $attribute,
        string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(self::describe($modelClass, $modelKey)." has an invalid {$attribute}: {$reason}", 0, $previous);
    }

    public static function on(Model $model, string $attribute, string $reason, ?Throwable $previous = null): self
    {
        return new self($model::class, $model->getKey(), $attribute, $reason, $previous);
    }

    /**
     * The model's class and key, such as "App\Models\Feed 12", for exception messages.
     *
     * @internal
     */
    public static function describeModel(Model $model): string
    {
        return self::describe($model::class, $model->getKey());
    }

    private static function describe(string $modelClass, mixed $modelKey): string
    {
        return is_scalar($modelKey) ? "{$modelClass} {$modelKey}" : "An unsaved {$modelClass}";
    }
}
