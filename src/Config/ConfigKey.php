<?php

namespace BYanelli\Roma\Config;

use Attribute;
use BYanelli\Roma\Data\Attributes\ExplicitKeyAttribute;
use BYanelli\Roma\Data\Attributes\SourceAttribute;
use BYanelli\Roma\Data\Source;
use RuntimeException;

/**
 * An absolute config key, such as "articles.scrapfly_timeout", in place of a key under the
 * config object's prefix. Only a top-level property may have one.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class ConfigKey implements ExplicitKeyAttribute, SourceAttribute
{
    /**
     * The key within its source: the last segment.
     */
    private string $last;

    /**
     * The source reads the array that contains the key, so its error key is the full config key.
     */
    private string $parent;

    public function __construct(public string $key)
    {
        $segments = explode('.', $key);

        if (count($segments) < 2 || in_array('', $segments, true)) {
            throw new RuntimeException(
                "Roma #[ConfigKey('{$key}')] needs a key within a config file, such as \"app.name\"."
            );
        }

        $this->last = (string) array_pop($segments);
        $this->parent = implode('.', $segments);
    }

    public function getKey(): string
    {
        return $this->last;
    }

    public function getSource(): Source
    {
        return new ConfigSource($this->parent);
    }
}
