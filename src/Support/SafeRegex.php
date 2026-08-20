<?php

namespace Drakelid\NmsDashWidgets\Support;

use Illuminate\Support\Facades\Log;

/**
 * A user-supplied regular expression, compiled defensively.
 *
 * The original widgets each did this differently: the uplink widget always wrapped
 * the pattern in /.../i, while the temperature widget first tried the raw string
 * (letting users supply their own delimiters and modifiers) before falling back to
 * ~...~i. This class standardises on always-wrap, which is predictable and stops a
 * stored pattern from changing meaning depending on which widget reads it.
 *
 * Patterns are stored bare, exactly as typed, e.g.:
 *     uplink|upstream|trunk|wan|core|backbone|transport
 */
final class SafeRegex
{
    public const MAX_LENGTH = 512;

    public const ERROR_INVALID = 'invalid';
    public const ERROR_TOO_LONG = 'too_long';
    public const ERROR_DEGRADED = 'degraded';

    private function __construct(
        private readonly string $raw,
        private readonly ?string $compiled,
        private readonly ?string $error,
        private bool $degraded = false,
    ) {
    }

    public static function make(mixed $pattern): self
    {
        $raw = trim((string) ($pattern ?? ''));

        if ($raw === '') {
            return new self('', null, null);
        }

        if (mb_strlen($raw) > self::MAX_LENGTH) {
            return new self($raw, null, self::ERROR_TOO_LONG);
        }

        $compiled = '/' . str_replace('/', '\/', $raw) . '/i';

        // preg_match() emits a warning for a bad pattern; @ suppresses it and the
        // false return tells us it failed to compile.
        if (@preg_match($compiled, '') === false) {
            return new self($raw, null, self::ERROR_INVALID);
        }

        return new self($raw, $compiled, null);
    }

    /** The pattern as the user typed it. */
    public function raw(): string
    {
        return $this->raw;
    }

    /** No pattern was supplied, so no filtering should be applied. */
    public function isEmpty(): bool
    {
        return $this->raw === '';
    }

    /** A pattern was supplied and compiled successfully. */
    public function isUsable(): bool
    {
        return $this->compiled !== null && ! $this->degraded;
    }

    /** A pattern was supplied but could not be used. */
    public function isInvalid(): bool
    {
        return $this->raw !== '' && $this->compiled === null;
    }

    /**
     * Why the pattern was rejected, as a stable code. Kept free of translation calls
     * so this class can be unit tested without booting the framework.
     */
    public function errorCode(): ?string
    {
        if ($this->degraded) {
            return self::ERROR_DEGRADED;
        }

        return $this->error;
    }

    /** Human readable reason the pattern was rejected, for display next to the widget. */
    public function error(): ?string
    {
        return match ($this->errorCode()) {
            self::ERROR_INVALID => __('Not a valid regular expression.'),
            self::ERROR_TOO_LONG => __('Pattern is longer than :max characters.', ['max' => self::MAX_LENGTH]),
            self::ERROR_DEGRADED => __('Pattern was too expensive to evaluate and was ignored.'),
            default => null,
        };
    }

    /**
     * True once a match has blown PCRE's backtrack limit. The pattern is abandoned
     * from that point on rather than being retried against every remaining row.
     */
    public function isDegraded(): bool
    {
        return $this->degraded;
    }

    /**
     * Does this pattern match the empty string?
     *
     * Worth surfacing to users: a trailing alternation such as "outlet|inlet|" matches
     * everything, which looks like a filter but is not one.
     */
    public function matchesEmptyString(): bool
    {
        return $this->isUsable() && @preg_match((string) $this->compiled, '') === 1;
    }

    /**
     * Test a subject. An empty, invalid or degraded pattern never matches; callers
     * decide what that means for their filter (include vs exclude).
     */
    public function matches(string $subject): bool
    {
        if (! $this->isUsable()) {
            return false;
        }

        $result = @preg_match((string) $this->compiled, $subject);

        if ($result === false || preg_last_error() !== PREG_NO_ERROR) {
            $this->markDegraded();

            return false;
        }

        return $result === 1;
    }

    private function markDegraded(): void
    {
        if ($this->degraded) {
            return;
        }

        $this->degraded = true;

        // Logged once per instance, not once per row. Guarded so the class stays
        // usable (and testable) outside a booted Laravel application.
        if (class_exists(Log::class)) {
            Log::warning('nmsdashwidgets: regex abandoned mid-scan', [
                'pattern' => $this->raw,
                'preg_error' => preg_last_error_msg(),
            ]);
        }
    }
}
