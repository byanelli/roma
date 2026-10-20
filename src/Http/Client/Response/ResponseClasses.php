<?php

namespace BYanelli\Roma\Http\Client\Response;

use BYanelli\Roma\Http\Attributes\Status;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * The response classes of one call, keyed by the status each goes with: a code ("404"), a range
 * ("4xx") or "default". A class without a class-level #[Status] goes with "2xx", and so does
 * Laravel's Response.
 *
 * @internal
 */
final readonly class ResponseClasses
{
    /**
     * @param  array<string, class-string>  $byStatus
     */
    private function __construct(private array $byStatus) {}

    /**
     * Merges lists by status. A status in two lists goes with the class in the earlier one, and a
     * status twice in one list throws.
     *
     * @param  array<string, list<class-string>>  $lists  keyed by where each list is declared, most specific first
     */
    public static function merge(array $lists): self
    {
        $byStatus = $declaredIn = [];

        foreach ($lists as $where => $classes) {
            foreach ($classes as $class) {
                foreach (self::statusesOf($class) as $status) {
                    if (! isset($byStatus[$status])) {
                        $byStatus[$status] = $class;
                        $declaredIn[$status] = $where;
                    } elseif ($declaredIn[$status] === $where && $byStatus[$status] !== $class) {
                        throw new RuntimeException(sprintf(
                            'Roma response classes %s and %s both go with status %s in %s. Give one of them another #[Status].',
                            class_basename($byStatus[$status]),
                            class_basename($class),
                            $status,
                            $where,
                        ));
                    }
                }
            }
        }

        return new self($byStatus);
    }

    /**
     * The class for a status, and whether it matched as the fallback: an exact code first, then
     * its range, then "default".
     *
     * @return ?array{class-string, bool}
     */
    public function match(int $status): ?array
    {
        foreach ([(string) $status, intdiv($status, 100).'xx'] as $key) {
            if (isset($this->byStatus[$key])) {
                return [$this->byStatus[$key], false];
            }
        }

        return isset($this->byStatus['default']) ? [$this->byStatus['default'], true] : null;
    }

    /**
     * e.g. "2xx: Video, 404: NotFound"
     */
    public function describe(): string
    {
        if ($this->byStatus === []) {
            return 'none';
        }

        return implode(', ', array_map(
            fn (string $status, string $class) => "{$status}: ".class_basename($class),
            array_keys($this->byStatus),
            $this->byStatus,
        ));
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    private static function statusesOf(string $class): array
    {
        if (! class_exists($class)) {
            throw new RuntimeException("Roma response class {$class} does not exist.");
        }

        if ($class === Response::class) {
            return ['2xx'];
        }

        $statuses = array_map(fn (Status $status) => (string) $status->pattern, Status::ofClass($class));

        return $statuses === [] ? ['2xx'] : $statuses;
    }
}
