# Feed

## Endpoints

| Method | Feed | Endpoint |
|---|---|---|
| `Wordfence::production()` | fully analyzed records | `GET /api/intelligence/v3/vulnerabilities/production` |
| `Wordfence::scanner()` | minimal records for detection | `GET /api/intelligence/v3/vulnerabilities/scanner` |

Both return every known vulnerability and take no parameters. Authentication is
`Authorization: Bearer <key>`.

## How it is read

1. The client downloads the document into a temporary file
   (`StreamingHttpClient::sendToFile()`), retrying network failures and `5xx`
   with exponential backoff, never a `429`.
2. `Feed\ObjectStreamReader` walks the top-level object member by member. It
   finds the end of each value (strings, escapes, nesting) and decodes only
   that value, dropping what it consumed.
3. Each value becomes a `Model\Vulnerability`, yielded with its UUID as key.
4. The file's name is removed as soon as reading starts, and after a failed
   download; the open handle keeps the data until the iteration ends. Only a
   process killed during the download can leave a `wordfence-*` file behind.

## Measured (2026-10-18)

| Feed | Records | Download | Time | Peak memory |
|---|---|---|---|---|
| production | 42,504 | 158.2 MB | 5.2 s | 3.0 MB above start |

A second download right after the first was refused with `429` and no
`Retry-After`; Wordfence warned by mail that continued excess may suspend the
key. The scanner feed was therefore not measured.

## Malformed input

A body that is not a JSON object (an HTML page from a proxy, a list, truncated
JSON, data after the object) raises `SerializationException` with the byte
offset. A record with a missing or mistyped field raises it with the field path,
e.g. `software[0].slug`.

Both are thrown when the reader reaches the defect, after the records before it
were yielded. Apply changes that depend on the whole feed only after the
iteration finished. A malformed record stops the feed instead of being skipped.
