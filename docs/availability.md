# Availability source (`check_availability`)

`check_availability` lets Claude check whether requested dates are free
before it drafts a reply — useful for a holiday rental, a guest house or
anything else booked by the night. mailbox-mcp does not keep a calendar
itself: it reads a small JSON feed that **your own website** exposes and
filters it locally.

## Configuration

Per account, optional:

```php
'availability' => [
    'url' => 'https://www.example.com/availability.php', // https only, no user:pass@
    'format' => 'occupied-json',                         // the only format so far
    'timeout' => 10,                                     // seconds, 1..30, default 10
],
```

Without the block the tool answers
`Account '<id>' has no availability source configured`.

The URL comes from the config only. The model cannot change it, and the
requested dates are never sent to it: the server fetches the whole feed
and works out the answer itself.

## The `occupied-json` format

`GET <url>` must answer `200` with a JSON body (any `Content-Type`,
at most 256 KiB, no redirects):

```json
{
  "success": true,
  "generated": "2026-10-22T08:00:00+02:00",
  "occupied": [
    {"from": "2026-11-06", "to": "2026-11-08"},
    {"from": "2026-12-23", "to": "2026-12-27"}
  ]
}
```

| Key | Meaning |
| --- | --- |
| `success` | `true` when the list is complete and current. `false` (any other keys ignored) tells mailbox-mcp the source is temporarily unable to answer, e.g. the booking database is down; the tool then reports `Availability source temporarily unavailable` instead of guessing. |
| `generated` | Optional ISO 8601 date or date-time at which the list was produced; shown to the model as `source_generated`. Anything that does not look like an ISO 8601 timestamp is shown as `unknown`. |
| `occupied` | A JSON array (at most 2000 entries) of occupied ranges, in any order. Each entry has `from` and `to`, both `YYYY-MM-DD` calendar dates, `to` after `from`. |

A range occupies the **nights** from `from` (the check-in day) up to but
excluding `to` (the check-out day) — the same convention as a hotel
booking. A range `2026-11-06 → 2026-11-08` therefore blocks the nights
of the 6th and the 7th: a new guest may check in on the 8th, and a stay
ending on the 6th does not collide with it. Overlapping or adjacent
ranges are fine; merge them or not, as is easiest for you.

Anything else — not JSON, a missing key, an impossible date, more than
2000 ranges — makes the tool answer
`Availability source returned unexpected data` (the body itself is never
shown to the model). Network or TLS errors, a status other than `200`,
a timeout or a body over 256 KiB give
`Availability source could not be fetched`; the reason is written to the
PHP error log.

## Privacy: dates only

The feed is public (no authentication, by design: there is nothing secret
in it). It must therefore contain **dates only** — no guest names, email
addresses, phone numbers, prices, booking references or notes. Generate
it from whatever system holds your bookings (a booking plugin, a channel
manager's iCal export, a spreadsheet) with a small script on your own
site that outputs nothing but the structure above. If you would rather
not publish even the occupancy pattern, put the endpoint behind an
unguessable path; it is still read only by your mailbox-mcp server.

## What the tool returns

```
account: rental
requested: 2026-11-08 → 2026-11-10 (2 nights)
available: yes
source_generated: 2026-10-22T08:00:00+02:00
overlapping: 0
occupied_nearby: 1
- 2026-11-06 → 2026-11-08
```

`overlapping` lists every occupied range sharing at least one night with
the request (`available: no` when there is any); `occupied_nearby` lists,
chronologically, the other ranges that touch the 45 days before the
check-in or the 45 days after the check-out, so Claude can suggest
alternative dates. A request may cover at most 60 nights and start at
most two years from today.
