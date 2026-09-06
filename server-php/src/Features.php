<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Feature flags.
 *
 * Every capability that depends on something outside this repository — Pulse,
 * Drive, an OCR engine, pgvector, a realtime server — is gated here and
 * defaults to **off**. The API answers `FEATURE_DISABLED` for a flag that is
 * off, and the frontend reads the same flags from `GET /api/config` so it can
 * hide the control rather than render a button that fails when pressed.
 *
 * That is the whole reason this class exists: an unconfigured deployment
 * should look like a smaller product, not a broken one.
 */
final class Features
{
    public const AI = 'ai';
    public const SEMANTIC_SEARCH = 'semantic_search';
    public const OCR = 'ocr';
    public const TRANSCRIPTION = 'transcription';
    public const REALTIME = 'realtime';
    public const CANVAS = 'canvas';
    public const PRIVATE_NOTES = 'private_notes';
    public const DRIVE = 'drive';
    public const CALENDAR = 'calendar';
    public const CONTACTS = 'contacts';
    public const CONNECT = 'connect';

    /** flag => env var. */
    private const ENV_KEYS = [
        self::AI => 'NOTES_AI_ENABLED',
        self::SEMANTIC_SEARCH => 'NOTES_SEMANTIC_SEARCH_ENABLED',
        self::OCR => 'NOTES_OCR_ENABLED',
        self::TRANSCRIPTION => 'NOTES_TRANSCRIPTION_ENABLED',
        self::REALTIME => 'NOTES_REALTIME_ENABLED',
        self::CANVAS => 'NOTES_CANVAS_ENABLED',
        self::PRIVATE_NOTES => 'NOTES_PRIVATE_NOTES_ENABLED',
        self::DRIVE => 'NOTES_DRIVE_ENABLED',
        self::CALENDAR => 'NOTES_CALENDAR_ENABLED',
        self::CONTACTS => 'NOTES_CONTACTS_ENABLED',
        self::CONNECT => 'NOTES_CONNECT_ENABLED',
    ];

    /**
     * A flag is on only when it is switched on *and* what it depends on is
     * configured. Turning on `NOTES_AI_ENABLED` without a PULSE_API_URL would
     * otherwise produce a UI full of Pulse buttons that every fail on click.
     */
    private const REQUIRES_ENV = [
        self::AI => ['PULSE_API_URL'],
        self::SEMANTIC_SEARCH => ['PULSE_API_URL'],
        self::DRIVE => ['DRIVE_API_URL'],
        self::CALENDAR => ['CALENDAR_API_URL'],
        self::CONTACTS => ['CONTACTS_API_URL'],
        self::CONNECT => ['CONNECT_API_URL'],
    ];

    public static function enabled(string $flag): bool
    {
        $key = self::ENV_KEYS[$flag] ?? null;
        if ($key === null) {
            return false;
        }
        if (strtolower(Env::get($key, 'false')) !== 'true') {
            return false;
        }

        foreach (self::REQUIRES_ENV[$flag] ?? [] as $required) {
            if (Env::get($required) === '') {
                return false;
            }
        }

        return true;
    }

    public static function require(string $flag): void
    {
        if (!self::enabled($flag)) {
            throw Http\ApiException::featureDisabled($flag);
        }
    }

    /** @return array<string, bool> The map the frontend reads from /api/config. */
    public static function all(): array
    {
        $flags = [];
        foreach (array_keys(self::ENV_KEYS) as $flag) {
            $flags[$flag] = self::enabled($flag);
        }

        return $flags;
    }

    public static function int(string $envKey, int $default, int $min, int $max): int
    {
        $raw = Env::get($envKey);

        return $raw === '' ? $default : max($min, min($max, (int) $raw));
    }
}
