# Wordfence Intelligence API for PHP

A modern, strongly-typed, dependency-free PHP client for the
[Wordfence Intelligence](https://www.wordfence.com/threat-intel/) vulnerability
data feed (v3) and its webhook notifications.

## Features

- **Both feeds**: the production feed (fully analyzed records) and the scanner
  feed (minimal records for detection).
- **Streamed**: the feed is one large JSON document (158 MB, 42,504 records on
  2026-10-18). The client downloads it into a temporary file and yields one
  record at a time; memory stays at a few megabytes.
- **Webhooks**: verifies the HMAC-SHA-256 signature before anything is decoded
  and turns the payload into typed records.
- **Typed records** for feed and webhook alike, including both date formats and
  both shapes of the copyright notices.
- **Version matching**: `Software::affects('1.2.3')` answers whether an
  installed version is vulnerable.
- **No dependencies**: only `ext-curl` and `ext-json`; bring your own transport
  if you like.

## Requirements

- PHP 8.4 or newer
- `ext-curl`, `ext-json`

## Installation

```bash
composer require gosuccess/wordfence-api
```

## Quick start

Create an API key on wordfence.com under *Account → Integrations*. The feed is
free, also for commercial use, under the
[Wordfence Intelligence Terms and Conditions](https://www.wordfence.com/wordfence-intelligence-terms-and-conditions/).

```php
use GoSuccess\Wordfence\Wordfence;

$wordfence = new Wordfence($apiKey);

foreach ($wordfence->production() as $uuid => $vulnerability) {
    foreach ($vulnerability->software as $software) {
        if ($software->slug === 'contact-form-7' && $software->affects('5.0')) {
            echo "{$vulnerability->title}\n";
        }
    }
}
```

`production()` and `scanner()` are generators: the download starts with the
first iteration, so errors are thrown while iterating, not when the method is
called. Stopping early (`break`) is fine. The temporary file's name is removed as
soon as reading starts (the client reads from the open handle) and after a failed
download; only a process killed during the download itself can leave a
`wordfence-*` file in the temp dir.

Records arrive one by one, so a defect later in the document is thrown only after
the records before it were yielded. If you mirror the feed, apply anything that
depends on the whole feed (such as deleting records that are no longer in it)
only after the iteration finished without an exception. One malformed record
stops the feed rather than being skipped silently; a value Wordfence adds later
(e.g. a new software type) therefore needs a package update.

## Rate limit

The API key has a usage limit that Wordfence does not publish. On 2026-10-18 a
second full download right after the first one was refused with `429` ("API key
limit exceeded"), without a `Retry-After` header, and Wordfence mailed at once:
continued requests beyond the limit may get the key **suspended**. Download a
feed rarely (e.g. once a day, never both feeds back to back), wait long after a
`429` instead of trying again, and use webhooks for changes in between. The
client never retries a `429`; it throws `RateLimitException` (with `retryAfter`
when the API announces it).

## Webhooks

Webhooks deliver changes as they happen: new records, records that replace an
earlier version, and removed IDs. Set them up on wordfence.com under
*Account → Integrations → Add Webhook* with a target URL and a secret of at
least 32 characters.

```php
use GoSuccess\Wordfence\Exception\InvalidSignatureException;
use GoSuccess\Wordfence\Webhook\Webhook;

$webhook = new Webhook($secret);

try {
    $notification = $webhook->parse($rawBody, Webhook::signatureFrom($headers));
} catch (InvalidSignatureException) {
    // Reject with 401: the request may not come from Wordfence.
}

$notification->created;  // list<Vulnerability>
$notification->replaced; // list<Vulnerability>
$notification->deleted;  // list<string> of UUIDs
```

Pass the raw request body exactly as received; the signature is computed over
it. The signature is read from `X-Wordfence-Signature`, or from `X-Signature`,
the name Wordfence's documentation also uses.

A webhook only reports changes. The state at the start, and anything sent while
your endpoint was unreachable, comes from the feed.

## Records

`Vulnerability` carries `id`, `title`, `software`, `informational`,
`description`, `references`, `cwe`, `cvss`, `cve`, `cveLink`, `researchers`,
`published`, `updated` and `copyrights`. Fields that only the production feed
has are `null` or empty in scanner records. Dates are `DateTimeImmutable` in UTC.

`Software` carries `type` (`core`, `plugin`, `theme`), `name`, `slug`,
`affectedVersions` (keyed by a display label), `patched`, `patchedVersions`,
`remediation` and, from webhooks only, `id`.

Version matching compares like WordPress (`version_compare`); `*` means any
version, and each bound is inclusive or exclusive as the record says. Trailing
zero segments and a trailing dot do not count, so `1.0` equals `1.0.0`.

### Copyright and attribution

Records may contain material under copyright (Defiant, MITRE). Whoever shows a
record must show its notices (`$vulnerability->copyrights->notices`) with it; for
CVE data from MITRE this is a condition of its license.

## Error handling

Every exception implements `GoSuccess\Wordfence\Exception\WordfenceException`.

| Exception | When |
|---|---|
| `AuthenticationException` | `401`, missing or invalid key |
| `RateLimitException` | `429`, never retried |
| `ServerException` | `5xx` after the retries |
| other `ApiException` subclasses | other error statuses |
| `TransportException` | network failure after the retries |
| `SerializationException` | the document or a record is malformed; the message names the field path or byte offset, never the body itself (a path may contain a version label such as `affected_versions["1.0 - 2.0"]`) |
| `InvalidSignatureException` | a webhook whose signature does not match |

## Configuration and custom transport

`ClientOptions` sets the timeouts (default 300 s for the whole download), retries
of network failures and server errors, backoff and user agent. A custom transport
implements `GoSuccess\Wordfence\Http\StreamingHttpClient`: besides `send()` it
writes a response body into a file (`sendToFile()`), which is how the feed stays
out of memory.

## Not covered

- The v1 and v2 feeds (superseded by v3).
- Webhook integrations for Slack and Discord (they are configured on
  wordfence.com and need no client).

## Documentation and examples

- [docs/feed.md](docs/feed.md), [docs/webhooks.md](docs/webhooks.md)
- [examples/feed.php](examples/feed.php), [examples/webhook.php](examples/webhook.php)
- Wordfence's own documentation:
  [feed](https://www.wordfence.com/help/wordfence-intelligence/v3-accessing-and-consuming-the-vulnerability-data-feed/),
  [webhooks](https://www.wordfence.com/help/wordfence-intelligence/wordfence-intelligence-webhook-notifications/)

## Development

```bash
composer check            # code style, static analysis, unit tests
WORDFENCE_FEED=production composer test:integration # one live feed per run
```

Live tests read the key from the environment, e.g. from a `.env` that is never
committed: `set -a; . ./.env; set +a`. They read exactly one feed, chosen with
`WORDFENCE_FEED`, and are skipped otherwise; do not run them twice in a row (see
"Rate limit"). With `WORDFENCE_MEASURE=1` they report size, time and memory.

## License

MIT
