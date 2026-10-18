# Webhooks

## Signature

Wordfence computes `hash_hmac('sha256', $rawBody, $secret)` and sends the hex
digest in `X-Wordfence-Signature`; its documentation's prose calls the header
`X-Signature`, so both are read (`Webhook::signatureFrom()`, case-insensitive,
first value of a repeated header). `Webhook::verify()` compares in constant time,
ignoring case and surrounding whitespace. `Webhook::parse()` verifies first and
decodes only a body whose signature matches.

The secret must have at least 32 characters.

## Payload

```json
{"vulnerabilities": {"created": [...], "replaced": [...], "deleted": ["<uuid>"]}}
```

`created` and `replaced` hold full records; `deleted` holds UUIDs. A missing
list counts as empty.

## Differences from the feed

The models read both forms:

| | Feed | Webhook |
|---|---|---|
| Dates | `YYYY-MM-DD hh:mm:ss` (UTC) | ISO 8601 with microseconds and `Z` |
| Copyrights | parties keyed by name (`defiant`, `mitre`) | list under `copyrights` |
| `software[].id` | absent | present |
| `informational` | present | absent (read as `false`) |
| `cwe.description` | text | may be `null` |

## Webhooks alone are not enough

A webhook reports changes only. Load the feed once at the start and now and then
to catch up on anything sent while the endpoint was unreachable.
