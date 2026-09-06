<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests\Cases;

use Aicountly\Api\Features;
use Aicountly\Api\Http\CompanyContext;
use Aicountly\Api\Integrations\AicountlyClient;
use Aicountly\Api\Integrations\HttpTransport;
use Aicountly\Api\Tests\TestCase;

/**
 * What actually goes on the wire to another AICOUNTLY product, and what comes
 * back.
 *
 * Three failures this guards against, all of them quiet:
 *
 *   1. **A refusal read as a payload.** Siblings answer `{status: 0, message}`
 *      on HTTP 200. Unwrapping `data` without looking at `status` turns a
 *      permission denial into a row of nulls in the UI.
 *   2. **A call with no company on it.** `cmp_id` / `fy_id` / `bo_id` are how
 *      the suite says which company is being asked about. Dropping them is the
 *      "works for me, empty for them" bug.
 *   3. **The wrong address.** The origin is derived from this deployment's own
 *      hostname, and `/api` is part of the base — a path concatenated onto a
 *      bare origin 404s.
 */
final class SiblingCallTest extends TestCase
{
    public function name(): string
    {
        return 'Sibling calls';
    }

    public function setUp(): void
    {
        foreach (['CONTACTS_API_ORIGIN', 'CONTACTS_API_URL'] as $key) {
            putenv($key);
        }
        CompanyContext::clear();
    }

    public function testTheCallersSesKeyIsWhatGoesOnTheWire(): void
    {
        $http = $this->transport('{"status":1,"data":{"ok":true}}');
        $this->call($http, 'GET', '/contacts', 'ses_abc123');

        $this->assertTrue(
            in_array('Authorization: Bearer ses_abc123', $http->headers, true),
            'the caller\'s ses_key must be forwarded unchanged',
        );
        // Nothing this deployment holds is a substitute for it.
        foreach ($http->headers as $header) {
            if (str_starts_with($header, 'Authorization:')) {
                $this->assertSame('Authorization: Bearer ses_abc123', $header);
            }
        }
    }

    public function testTheAddressIsDerivedFromThisDeploymentsHost(): void
    {
        $http = $this->transport('{"status":1,"data":[]}');
        $this->call($http, 'GET', '/contacts', 'ses_abc123');

        // No HTTP_HOST in a CLI test, and an unrecognised host resolves to
        // sandbox. What matters here is the `/api` prefix and the host label.
        $this->assertContainsString('https://contacts.gh.aicountly.com/api/contacts', $http->url);
    }

    public function testTheSiblingSuccessEnvelopeIsUnwrapped(): void
    {
        $http = $this->transport('{"status":1,"data":{"name":"Ada"}}');
        $data = $this->call($http, 'GET', '/contacts/1', 'ses_abc123');

        $this->assertSame('Ada', $data['name'] ?? null);
    }

    public function testASiblingRefusalIsAnErrorAndNotAnEmptyPayload(): void
    {
        // HTTP 200 with `status: 0` — the shape that produces silent nulls if
        // only `data` is read.
        $http = $this->transport('{"status":0,"message":"You may not see that contact."}');

        $this->assertApiError(
            'UPSTREAM_UNAVAILABLE',
            fn () => $this->call($http, 'GET', '/contacts/1', 'ses_abc123'),
            'a status: 0 answer must not be unwrapped as data',
        );
    }

    public function testDrivesSuccessEnvelopeIsAlsoUnderstood(): void
    {
        // Drive answers {success, data, errors} rather than {status, data} —
        // see SesAuthController::jsonSuccess in drive-react-app.
        $http = $this->transport('{"success":true,"data":{"name":"board-pack.pdf"},"errors":[]}');
        $data = $this->call($http, 'GET', '/documents/1', 'ses_abc123');

        $this->assertSame('board-pack.pdf', $data['name'] ?? null);
    }

    public function testADriveStyleRefusalIsAlsoAnError(): void
    {
        $http = $this->transport('{"success":false,"message":"nope","data":null}');

        $this->assertApiError(
            'UPSTREAM_UNAVAILABLE',
            fn () => $this->call($http, 'GET', '/documents/1', 'ses_abc123'),
        );
    }

    public function testCompanyContextTravelsWithTheCall(): void
    {
        CompanyContext::capture(['cmp_id' => '42', 'fy_id' => '7', 'bo_id' => '3', 'page' => '2']);

        $http = $this->transport('{"status":1,"data":[]}');
        $this->call($http, 'GET', '/contacts', 'ses_abc123', null, ['q' => 'ada']);

        $this->assertContainsString('cmp_id=42', $http->url);
        $this->assertContainsString('fy_id=7', $http->url);
        $this->assertContainsString('bo_id=3', $http->url);
        $this->assertContainsString('q=ada', $http->url);
        // `page` is not company context and is not smuggled along with it.
        $this->assertFalse(str_contains($http->url, 'page=2'));
    }

    public function testAContextThatIsNotAnIdIsDroppedRatherThanForwarded(): void
    {
        // These values are pasted into an outbound query string. Anything that
        // is not an id was never a company.
        CompanyContext::capture(['cmp_id' => '1 OR 1=1', 'fy_id' => '']);

        $http = $this->transport('{"status":1,"data":[]}');
        $this->call($http, 'GET', '/contacts', 'ses_abc123');

        $this->assertFalse(str_contains($http->url, 'cmp_id'));
        $this->assertFalse(str_contains($http->url, 'fy_id'));
    }

    public function testNoCompanyIsInventedWhenTheCallerSentNone(): void
    {
        $http = $this->transport('{"status":1,"data":[]}');
        $this->call($http, 'GET', '/contacts', 'ses_abc123');

        $this->assertFalse(str_contains($http->url, 'cmp_id'));
    }

    // -----------------------------------------------------------------------

    /**
     * One call through the real client, with the Contacts flag on for exactly
     * as long as it takes.
     *
     * The runner has no tearDown, so a flag switched on in setUp would leak
     * into every case that runs after this one.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function call(
        HttpTransport $http,
        string $method,
        string $path,
        string $sesKey,
        ?array $body = null,
        array $query = [],
    ): array {
        $client = new AicountlyClient('contacts', Features::CONTACTS, 'contacts', $http);

        putenv('NOTES_CONTACTS_ENABLED=true');
        try {
            return $client->send($method, $path, $sesKey, $body, $query);
        } finally {
            putenv('NOTES_CONTACTS_ENABLED');
        }
    }

    private function transport(string $body, int $status = 200): object
    {
        return new class ($body, $status) implements HttpTransport {
            public string $url = '';
            /** @var array<int, string> */
            public array $headers = [];
            public ?string $body = null;

            public function __construct(private readonly string $answer, private readonly int $status)
            {
            }

            public function send(
                string $method,
                string $url,
                array $headers,
                ?string $body,
                int $connectTimeoutSeconds,
                int $timeoutSeconds,
                int $maxResponseBytes,
            ): array {
                $this->url = $url;
                $this->headers = $headers;
                $this->body = $body;

                return ['connection_failed' => false, 'status' => $this->status, 'body' => $this->answer];
            }
        };
    }
}
