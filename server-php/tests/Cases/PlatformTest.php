<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Env;
use Aicountly\Api\Features;
use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Http\Request;
use Aicountly\Api\Http\Response;
use Aicountly\Api\Http\Router;
use Aicountly\Api\Support\Logger;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\TestCase;

/**
 * The plumbing every other class depends on.
 *
 * Two of these are security properties rather than conveniences: note content
 * must never reach a log line, and a feature flag must not switch on a
 * capability whose dependency is missing.
 */
final class PlatformTest extends TestCase
{
    public function name(): string
    {
        return 'Platform';
    }

    // -- Logging ------------------------------------------------------------

    public function testTheLoggerDropsNoteContent(): void
    {
        $scrubbed = Logger::scrub([
            'note_id' => 'abc',
            'title' => 'Confidential: ABC merger terms',
            'extracted_text' => 'The board agreed to 4.2 crore.',
            'document_json' => ['type' => 'doc'],
            'transcript' => 'and then he said…',
            'prompt' => 'Summarise the merger note',
            'answer' => 'The board agreed…',
            'snippet' => 'agreed to 4.2 crore',
            'q' => 'merger',
            'count' => 3,
        ]);

        foreach (['title', 'extracted_text', 'document_json', 'transcript', 'prompt', 'answer', 'snippet', 'q'] as $key) {
            $this->assertSame('[redacted]', $scrubbed[$key], $key . ' must never reach a log');
        }

        // Ids and counts are what makes a log useful, and are safe to keep.
        $this->assertSame('abc', $scrubbed['note_id']);
        $this->assertSame(3, $scrubbed['count']);
    }

    public function testTheLoggerDropsCredentials(): void
    {
        $scrubbed = Logger::scrub([
            'auth_token' => 'eyJhbGciOi…',
            'ses_key' => 'live-session-key',
            'authorization' => 'Bearer live-session-key',
            'api_key' => 'sk-live-…',
            'password' => 'hunter2',
        ]);

        foreach ($scrubbed as $key => $value) {
            $this->assertSame('[redacted]', $value, $key . ' must never reach a log');
        }
    }

    public function testTheLoggerScrubsNestedContext(): void
    {
        $scrubbed = Logger::scrub([
            'job' => ['id' => 'j1', 'payload' => ['title' => 'Private note', 'attempts' => 2]],
        ]);

        $this->assertSame('[redacted]', $scrubbed['job']['payload']['title']);
        $this->assertSame(2, $scrubbed['job']['payload']['attempts']);
    }

    public function testTheLoggerTruncatesRatherThanLoggingAnEssay(): void
    {
        $scrubbed = Logger::scrub(['reason' => str_repeat('x', 5000)]);

        $this->assertSame(200, mb_strlen((string) $scrubbed['reason']));
    }

    // -- Feature flags ------------------------------------------------------

    public function testEveryFlagIsOffByDefault(): void
    {
        Env::load('/nonexistent');

        foreach (Features::all() as $flag => $enabled) {
            $this->assertFalse($enabled, $flag . ' must default to off');
        }
    }

    public function testAFlagStaysOffWithoutItsDependency(): void
    {
        Env::load('/nonexistent');
        putenv('NOTES_AI_ENABLED=true');

        // Turning on AI without a Pulse URL would produce a UI full of buttons
        // that fail on click, which is worse than no buttons.
        $this->assertFalse(Features::enabled(Features::AI));

        putenv('PULSE_API_URL=https://pulse.example.test/api');
        $this->assertTrue(Features::enabled(Features::AI));

        putenv('NOTES_AI_ENABLED');
        putenv('PULSE_API_URL');
    }

    public function testRequiringADisabledFeatureReportsItHonestly(): void
    {
        Env::load('/nonexistent');

        $this->assertApiError('FEATURE_DISABLED', static fn () => Features::require(Features::OCR));
    }

    public function testAnUnknownFlagIsNeverEnabled(): void
    {
        $this->assertFalse(Features::enabled('teleportation'));
    }

    // -- Routing ------------------------------------------------------------

    public function testTheRouterExtractsParameters(): void
    {
        $router = new Router();
        $router->get('/notes/{id}/attachments/{attachmentId}', static fn () => Response::noContent());

        $matched = $router->match('GET', 'notes/abc/attachments/def');

        $this->assertNotNull($matched);
        $this->assertSame('abc', $matched['params']['id'] ?? null);
        $this->assertSame('def', $matched['params']['attachmentId'] ?? null);
    }

    public function testAKnownPathWithTheWrongVerbIs405NotA404(): void
    {
        $router = new Router();
        $router->get('/notes', static fn () => Response::noContent());

        // The difference is what tells a client "you used the wrong method"
        // rather than "this endpoint does not exist".
        $this->assertApiError('METHOD_NOT_ALLOWED', static fn () => $router->match('DELETE', 'notes'));
    }

    public function testAnUndeclaredPathDoesNotMatch(): void
    {
        $router = new Router();
        $router->get('/notes', static fn () => Response::noContent());

        $this->assertNull($router->match('GET', 'notebooks'));
        // A parameter must not swallow an extra segment.
        $this->assertNull($router->match('GET', 'notes/abc/extra'));
    }

    public function testEarlierRoutesWinSoLiteralsBeatParameters(): void
    {
        $router = new Router();
        $router->get('/notes/counts', static fn () => Response::ok(['literal' => true]));
        $router->get('/notes/{id}', static fn () => Response::ok(['literal' => false]));

        $matched = $router->match('GET', 'notes/counts');
        $this->assertSame([], $matched['params'] ?? ['not' => 'empty']);
    }

    // -- Request parsing ----------------------------------------------------

    public function testAMalformedBodyIsARejectionNotACrash(): void
    {
        $request = Request::forTesting('POST', 'notes', [], null, '', '{not json');

        $this->assertApiError('BAD_REQUEST', static fn () => $request->body());

        // An empty body is not malformed — a POST with no payload is normal.
        $this->assertSame([], Request::forTesting('POST', 'notes')->body());
    }

    public function testANonUuidRouteParameterIsNotFound(): void
    {
        $request = Request::forTesting('GET', 'notes/x');
        $request->routeParams = ['id' => '1 OR 1=1'];

        // Rejecting the shape here is what keeps a malformed id from reaching a
        // query as a value Postgres then refuses with a 500.
        $this->assertApiError('NOT_FOUND', static fn () => $request->uuidParam('id'));
    }

    public function testQueryIntegersAreClamped(): void
    {
        $request = Request::forTesting('GET', 'notes', ['limit' => '99999', 'offset' => '-5']);

        $this->assertSame(100, $request->queryInt('limit', 30, 1, 100));
        $this->assertSame(0, $request->queryInt('offset', 0, 0, 1000));
        $this->assertSame(30, $request->queryInt('missing', 30, 1, 100));
        // Nonsense falls back to the default rather than becoming 0.
        $this->assertSame(
            30,
            Request::forTesting('GET', 'notes', ['limit' => 'abc'])->queryInt('limit', 30, 1, 100),
        );
    }

    // -- Small helpers ------------------------------------------------------

    public function testTagSlugFoldsCaseAndSpacing(): void
    {
        $this->assertSame('gst', Str::tagSlug('GST'));
        $this->assertSame('gst', Str::tagSlug('  gst  '));
        $this->assertSame('gst-audit', Str::tagSlug('GST Audit'));
        $this->assertSame('gst-audit', Str::tagSlug('gst_audit'));
        // Punctuation that would otherwise create a near-duplicate tag.
        $this->assertSame('gst', Str::tagSlug('#GST!'));
        $this->assertSame('', Str::tagSlug('!!!'));
    }

    public function testFirstLineIsWhatAnUntitledNoteShows(): void
    {
        $this->assertSame('Buy milk', Str::firstLine("\n\n  Buy milk  \nand bread"));
        $this->assertSame('', Str::firstLine("   \n \n"));
    }

    public function testUuidsAreValidAndUnique(): void
    {
        $first = Uuid::v4();
        $second = Uuid::v4();

        $this->assertTrue(Uuid::isValid($first));
        $this->assertNotSame($first, $second);
        $this->assertFalse(Uuid::isValid('not-a-uuid'));
        $this->assertFalse(Uuid::isValid(null));
        $this->assertFalse(Uuid::isValid(''));
        $this->assertFalse(Uuid::isValid('11111111-1111-4111-8111-11111111111'), 'too short');
        // The generated form is genuinely version 4, variant 10.
        $this->assertSame('4', $first[14]);
        $this->assertTrue(in_array($first[19], ['8', '9', 'a', 'b'], true));
    }

    // -- Error envelope -----------------------------------------------------

    public function testTheErrorEnvelopeCarriesACodeAndNoStackTrace(): void
    {
        $response = Response::error(ApiException::conflict('changed', ['server_version' => 4]));
        $body = (new \ReflectionProperty($response, 'body'))->getValue($response);

        $this->assertSame(409, $response->status);
        $this->assertFalse($body['success']);
        $this->assertSame('VERSION_CONFLICT', $body['error']['code']);
        $this->assertSame(4, $body['error']['details']['server_version']);
        $this->assertFalse(array_key_exists('trace', $body['error']));
    }
}
