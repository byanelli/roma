<?php

namespace BYanelli\Roma\Config;

use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Discovery\RomaCache;
use Illuminate\Console\Command;
use Throwable;

/**
 * Maps each class in roma.discover that has a class-level #[ConfigObject]. A class used only
 * with a parameter-level prefix has no prefix of its own, so it is not checked.
 */
class ConfigValidateCommand extends Command
{
    protected $signature = 'roma:config:validate';

    protected $description = 'Validate the Roma config objects against the current config';

    public function handle(RomaCache $cache, ConfigMapper $mapper): int
    {
        $classes = $cache->configObjects();

        if ($classes === []) {
            $this->components->info('No config objects found in the roma.discover directories.');

            return self::SUCCESS;
        }

        $this->newLine();

        $failed = 0;

        foreach ($classes as $class) {
            $errors = $this->errorsOf($mapper, $class);

            $this->components->twoColumnDetail($class, $errors === [] ? '<fg=green;options=bold>OK</>' : '<fg=red;options=bold>FAIL</>');

            foreach ($errors as $key => $messages) {
                foreach ($messages as $message) {
                    $this->line("    <fg=yellow>{$key}</>  {$message}");
                }
            }

            $failed += $errors === [] ? 0 : 1;
        }

        $this->newLine();

        if ($failed > 0) {
            $this->components->error(sprintf('%d of %d config objects failed validation.', $failed, count($classes)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * An exception other than a validation error, such as an invalid attribute, is listed under
     * the class name.
     *
     * @param  class-string  $class
     * @return array<string, list<string>>
     */
    private function errorsOf(ConfigMapper $mapper, string $class): array
    {
        try {
            $mapper->map($class);
        } catch (MappingValidationException $e) {
            return $e->errors();
        } catch (Throwable $e) {
            return [class_basename($class) => [$e->getMessage()]];
        }

        return [];
    }
}
