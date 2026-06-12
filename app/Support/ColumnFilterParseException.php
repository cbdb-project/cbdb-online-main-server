<?php

namespace App\Support;

/**
 * Thrown when a column boolean filter expression cannot be parsed.
 *
 * The {@see $errorCode} is a stable, machine-readable token (e.g. `unbalanced_paren`)
 * that maps to an i18n message key at the presentation layer. See
 * docs/CODES_BOOLEAN_FILTER_DESIGN.md §3.4 / §9.2.
 */
class ColumnFilterParseException extends \RuntimeException {
    public function __construct(
        public readonly string $errorCode,
        string $message = ''
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
