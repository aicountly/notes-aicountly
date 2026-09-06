<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Features;
use Aicountly\Api\Integrations\SiblingApi;
use Aicountly\Api\Tests\TestCase;

/**
 * Finding the other AICOUNTLY products.
 *
 * The failure this guards against is the quiet one: a sandbox deployment that
 * resolves production origins and starts reading live company data because a
 * hostname did not match a pattern. Hence the direction of the fallback —
 * anything unrecognised is sandbox, never production.
 */
final class SiblingApiTest extends TestCase
{
    public function name(): string
    {
        return 'Sibling API resolution';
    }

    public function setUp(): void
    {
        foreach ([
            'DRIVE_API_URL', 'CALENDAR_API_URL', 'CONTACTS_API_URL', 'CONNECT_API_URL', 'PULSE_API_URL',
            'DRIVE_API_ORIGIN', 'DOCS_API_ORIGIN', 'CALENDAR_API_ORIGIN', 'CONTACTS_API_ORIGIN',
            'CONNECT_API_ORIGIN', 'PULSE_API_ORIGIN',
        ] as $key) {
            putenv($key);
        }
    }

    public function testProductionHostsResolveToProductionSiblings(): void
    {
        $this->assertSame('https://drive.aicountly.com', SiblingApi::origin('drive', 'notes.aicountly.com'));
        $this->assertSame('https://calendar.aicountly.com', SiblingApi::origin('calendar', 'notes.aicountly.com'));
        $this->assertSame('https://contacts.aicountly.com', SiblingApi::origin('contacts', 'notes.aicountly.com'));
    }

    public function testSandboxHostsResolveToSandboxSiblings(): void
    {
        $this->assertSame('https://drive.gh.aicountly.com', SiblingApi::origin('drive', 'notes.gh.aicountly.com'));
        $this->assertSame('https://contacts.gh.aicountly.com', SiblingApi::origin('contacts', 'notes.gh.aicountly.com'));
    }

    public function testLocalhostIsTreatedAsSandbox(): void
    {
        $this->assertSame('https://drive.gh.aicountly.com', SiblingApi::origin('drive', 'localhost'));
        // A port must not defeat the match.
        $this->assertSame('https://drive.gh.aicountly.com', SiblingApi::origin('drive', 'localhost:8000'));
        $this->assertSame('https://drive.gh.aicountly.com', SiblingApi::origin('drive', '127.0.0.1:5173'));
    }

    public function testTheLegacySandboxZoneStillResolves(): void
    {
        $this->assertSame('https://drive.gh.aicountly.com', SiblingApi::origin('drive', 'gh-notes.aicountly.com'));
    }

    public function testAnUnrecognisedHostFailsTowardsSandbox(): void
    {
        // A deployment on an unexpected host reading live company data is a
        // worse outcome than one reading sandbox and looking empty.
        $this->assertTrue(SiblingApi::isSandbox('notes.example.com'));
        $this->assertTrue(SiblingApi::isSandbox(''));
        $this->assertSame('https://contacts.gh.aicountly.com', SiblingApi::origin('contacts', 'staging.internal'));
    }

    public function testPulseAnswersOnItsRenamedHostInProductionOnly(): void
    {
        // Pulse was renamed from Buddy in production but sandbox still serves
        // the old name; asking for pulse.gh.aicountly.com resolves nowhere.
        $this->assertSame('https://pulse.aicountly.com', SiblingApi::origin('pulse', 'notes.aicountly.com'));
        $this->assertSame('https://buddy.gh.aicountly.com', SiblingApi::origin('pulse', 'notes.gh.aicountly.com'));
    }

    public function testAnExplicitOriginOverrideWins(): void
    {
        putenv('DRIVE_API_ORIGIN=https://drive.internal.test/');

        // Trailing slash stripped, so callers can concatenate a path safely.
        $this->assertSame('https://drive.internal.test', SiblingApi::origin('drive', 'notes.aicountly.com'));

        putenv('DRIVE_API_ORIGIN');
    }

    public function testTheOverrideIsAcceptedUnderEitherSpelling(): void
    {
        // Drive's product_code is `docs`; its host is drive.aicountly.com.
        // Someone editing a .env is looking at the hostname, so both names work.
        putenv('DOCS_API_ORIGIN=https://drive.internal.test');
        $this->assertSame('https://drive.internal.test', SiblingApi::origin('drive', 'notes.aicountly.com'));
        putenv('DOCS_API_ORIGIN');

        putenv('DRIVE_API_ORIGIN=https://drive.other.test');
        $this->assertSame('https://drive.other.test', SiblingApi::origin('drive', 'notes.aicountly.com'));
        putenv('DRIVE_API_ORIGIN');
    }

    public function testTheLegacyUrlVariableIsReadVerbatim(): void
    {
        // `_API_URL` always named a full API base including /api, so apiBase()
        // must return it untouched — appending the prefix would give /api/api.
        putenv('DRIVE_API_URL=https://drive.legacy.test/api');

        $this->assertSame('https://drive.legacy.test/api', SiblingApi::apiBase('drive', 'notes.aicountly.com'));
        // It is a *base*, not an origin, so it does not answer origin().
        $this->assertSame('https://drive.aicountly.com', SiblingApi::origin('drive', 'notes.aicountly.com'));

        putenv('DRIVE_API_URL');
    }

    public function testApiBaseAppendsThePrefixEveryProductMountsAt(): void
    {
        $this->assertSame('https://drive.aicountly.com/api', SiblingApi::apiBase('drive', 'notes.aicountly.com'));
        $this->assertSame('https://contacts.gh.aicountly.com/api', SiblingApi::apiBase('contacts', 'notes.gh.aicountly.com'));
    }

    public function testAProductCodeIsNotAHostname(): void
    {
        // The three products whose code and host are spelled differently. The
        // code goes in a request body and an object key; the host is where the
        // socket opens. Deriving one from the other gives docs.aicountly.com,
        // connect→chat.aicountly.com and buddy.aicountly.com — none of which
        // resolve. See §10 of Drive's AICOUNTLY_DRIVE_STORAGE_ARCHITECTURE.md.
        $this->assertSame('docs', SiblingApi::productCode('drive'));
        $this->assertSame('docs', SiblingApi::productCode('docs'));
        $this->assertSame('https://drive.aicountly.com', SiblingApi::origin('docs', 'notes.aicountly.com'));

        $this->assertSame('chat', SiblingApi::productCode('connect'));
        $this->assertSame('https://connect.aicountly.com', SiblingApi::origin('chat', 'notes.aicountly.com'));

        $this->assertSame('buddy', SiblingApi::productCode('pulse'));
        $this->assertSame('https://pulse.aicountly.com', SiblingApi::origin('buddy', 'notes.aicountly.com'));
    }

    public function testTheModelGatewayUrlIsNeverMistakenForPulsesOwnApi(): void
    {
        // PULSE_API_URL names the model gateway HttpPulseProvider posts
        // completions to. Reading it as a sibling origin would send Notes'
        // product calls to whatever gateway the AI feature is pointed at.
        putenv('PULSE_API_URL=https://gateway.example.test/v1');

        $this->assertSame(
            'https://pulse.aicountly.com/api',
            SiblingApi::apiBase('pulse', 'notes.aicountly.com'),
        );

        putenv('PULSE_API_URL');
    }

    public function testAnUnknownProductIsAProgrammingError(): void
    {
        $threw = false;
        try {
            SiblingApi::origin('teleporter', 'notes.aicountly.com');
        } catch (\InvalidArgumentException) {
            $threw = true;
        }

        // Not an ApiException: no request can name a product, so reaching this
        // means the calling code is wrong.
        $this->assertTrue($threw, 'an unknown product must throw');
    }

    public function testIntegrationFlagsNoLongerNeedAUrl(): void
    {
        putenv('NOTES_DRIVE_ENABLED=true');
        putenv('NOTES_CONTACTS_ENABLED=true');

        // The flag is the gate; the URL is an override. Requiring both meant
        // four URLs per environment that the hostname already implies.
        $this->assertTrue(Features::enabled(Features::DRIVE));
        $this->assertTrue(Features::enabled(Features::CONTACTS));

        putenv('NOTES_DRIVE_ENABLED');
        putenv('NOTES_CONTACTS_ENABLED');
    }

    public function testAiStillNeedsSomewhereToSendAPrompt(): void
    {
        putenv('NOTES_AI_ENABLED=true');

        // Pulse exposes no prompt endpoint to derive, so this one genuinely
        // cannot be inferred and must stay configured. See
        // docs/PULSE_INTEGRATION.md.
        $this->assertFalse(Features::enabled(Features::AI));

        putenv('PULSE_API_URL=https://pulse.example.test/api');
        $this->assertTrue(Features::enabled(Features::AI));

        putenv('NOTES_AI_ENABLED');
        putenv('PULSE_API_URL');
    }
}
