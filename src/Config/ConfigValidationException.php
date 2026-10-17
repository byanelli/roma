<?php

namespace BYanelli\Roma\Config;

use BYanelli\Roma\Data\MappingValidationException;

/**
 * Config that failed validation while being mapped onto a config object. It is usually
 * thrown while the application boots, so the message lists all the errors.
 */
class ConfigValidationException extends MappingValidationException
{
    protected function summarize(): string
    {
        $lines = [];

        foreach ($this->errors as $key => $messages) {
            foreach ($messages as $message) {
                $lines[] = "  {$key}: {$message}";
            }
        }

        return "Roma config object {$this->className} is invalid:\n".implode("\n", $lines);
    }
}
