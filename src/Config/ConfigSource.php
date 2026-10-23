<?php

namespace BYanelli\Roma\Config;

use BYanelli\Roma\Data\Source;
use Illuminate\Contracts\Config\Repository;

/**
 * The config array under a key, such as "services.scrapfly". The key is the prefix of the
 * properties' error keys, so they are full config keys: "services.scrapfly.api_key".
 *
 * @extends Source<Repository>
 */
final readonly class ConfigSource extends Source
{
    /**
     * `$checksKeys` is true for the config object's prefix, whose keys #[RejectUnknownKeys]
     * checks, and false for the array of a #[ConfigKey], which has other keys.
     */
    public function __construct(private string $key, private bool $checksKeys = false) {}

    public function getKey(): string
    {
        return $this->key;
    }

    public function inputType(): string
    {
        return Repository::class;
    }

    public function checkedKeys(object $input): ?array
    {
        return $this->checksKeys ? null : [];
    }

    /**
     * @param  Repository  $input
     * @return array<array-key, mixed>
     */
    protected function read(object $input): array
    {
        $data = $input->get($this->key);

        return is_array($data) ? self::withoutBlanks($data) : [];
    }

    /**
     * Removes null and blank strings, since an unset variable copied from .env.example is
     * `KEY=`, and the arrays that are empty once they are removed. A list keeps its elements,
     * so that the indexes in error keys match the config.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function withoutBlanks(array $data): array
    {
        $isList = array_is_list($data);

        foreach ($data as $key => $value) {
            $emptied = false;

            if (is_array($value) && $value !== []) {
                $value = $data[$key] = self::withoutBlanks($value);
                $emptied = $value === [];
            }

            if (! $isList && ($emptied || $value === null || (is_string($value) && trim($value) === ''))) {
                unset($data[$key]);
            }
        }

        return $data;
    }
}
