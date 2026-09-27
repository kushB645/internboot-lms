<?php
function require_positive_int($value, string $field): int
{
    $number = filter_var($value, FILTER_VALIDATE_INT);
    if ($number === false || $number < 1) {
        throw new InvalidArgumentException($field . ' must be a positive integer.');
    }
    return $number;
}

function sanitize_string(string $input): string
{
    return trim($input);
}

function is_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validates a candidate's full name.
 * 
 * CONTRACT: This function is self-sufficient; it calls trim() internally 
 * so callers do not need to pass pre-trimmed input.
 * 
 * Enforces:
 * - Max length of 100 characters (post-trim).
 * - Must start with a Unicode letter (preventing CSV/Excel formula injection =,+,-,@).
 * - Allows Unicode letters, marks, spaces, periods, hyphens, and straight/curly apostrophes.
 */
function is_valid_full_name(string $name): bool
{
    $name = trim($name);
    
    // Pattern: 
    // ^\p{L} : Must start with a letter (rejects =,+,-,@ and whitespace/control chars at the start)
    // [\p{L}\p{M}\s\'\x{2018}\x{2019}\x{02BC}\-\.]{0,99}$ : Allows up to 99 more valid characters
    // u modifier: Unicode aware
    return preg_match('/^\p{L}[\p{L}\p{M}\s\'\x{2018}\x{2019}\x{02BC}\-\.]{0,99}$/u', $name) === 1;
}
