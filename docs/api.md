# The Feelm API

Written for somebody building a native client. The web front end uses exactly
these endpoints and nothing else — there is no private surface it reaches for,
so anything it can do, an app can do.

Base URL is `https://feelm.org`. Everything is JSON; nothing is form-encoded
except the avatar upload, which is multipart.

## Start here

```
GET /api/meta
```

Public, and the first call a client should make.

It returns the enumerations and limits the API validates against — work types,
shelf statuses, sort keys, credit roles, supported languages, the maximum page
size. **Read them rather than hardcoding them.** A web page ships alongside the
API and the two move together; an installed app does not, and every constant it
bakes in is one that can only be corrected by a store release.

Two fields deserve attention:

- **`contract`** — the shape of the responses. It goes up when something
  published changes meaning or disappears. Adding a field is not a change under
  that rule, so **ignore unknown keys**; that is what makes additions free.
- **`minimumClient`** — the oldest build still trusted to understand the
  responses. A client below it should tell the user to update rather than
  misbehave quietly. It is a floor and moves rarely, because raising it locks
  people out.

## Authentication

Bearer tokens. `Authorization: Bearer <access_token>` on anything under
`/api/me`, plus writes.

```
POST /api/login            { username, password }  -> { access_token, refresh_token, expires_at }
POST /api/register         { username, email, password, name, tagline? }
POST /api/auth/google      { credential }          -- the Google ID token
POST /api/token/refresh    { refresh_token }
POST /api/logout
```

Access tokens are short-lived; refresh tokens are not. A client should refresh
on a 401 and retry once, rather than refreshing on a timer — clock skew on a
phone makes expiry arithmetic unreliable.

An account created through Google arrives with a generated handle and
`handlePending: true` on `/api/me`. That is the one chance to change it:

```
POST /api/me/username      { username }
```

After that the handle is in every link to the profile and the endpoint returns
409 `handle_already_set`.

## Reading the catalog

All public.

```
GET /api/home                              rails, latest releases, the upcoming queue
GET /api/items?type=movie&sort=popularity  browse, paged
GET /api/items/{type}/{slug}               one work, everything about it
GET /api/items/{type}/{slug}/related
GET /api/items/{type}/{slug}/reviews
GET /api/search?q=…                        full text, plus every filter
GET /api/search/suggest?q=…                as-you-type; smaller payload, no facets
GET /api/search/filters                    genres, certifications, languages, decades
GET /api/upcoming
GET /api/people/{slug}                     one person and everything they are on
```

`/api/search` takes `type`, `genre`, `genreMode`, `yearFrom`, `yearTo`,
`scoreMin`, `imdbMin`, `votesMin`, `runtimeMin`, `runtimeMax`, `certification`,
`language`, `release`, `person`, `sort`, `page`, `limit`. Repeat a parameter to
pass several values.

### Paging

Every paged response is the same envelope:

```json
{ "items": [], "total": 0, "page": 1, "pages": 1, "limit": 24 }
```

With one wrinkle worth knowing on search: above a thousand matches the server
stops counting, because an exact figure past that costs more than it is worth
to anybody reading it. Then `totalIsExact` is `false`, `pages` is `null`, and
`hasMore` is the only reliable answer to "is there another page". A client that
draws a page count must handle `pages: null`.

### Artwork

Poster and backdrop URLs come back **absolute and ready to use**. Do not build
them. They currently point at either TMDB's CDN or our own bucket depending on
whether that work has been mirrored yet, and both are correct. `imageHosts` in
`/api/meta` lists where they may come from; it will gain a host when a CDN goes
in front of the bucket, and clients that pinned a hostname would break.

## The signed-in user

```
GET    /api/me
PATCH  /api/me                      name, tagline, bio, location
PATCH  /api/me/preferences          locale, timezone
POST   /api/me/password             currentPassword, newPassword
POST   /api/me/avatar               multipart, field name `avatar`
DELETE /api/me/avatar
```

`PATCH /api/me` sends the whole profile: a missing field means cleared, not
untouched. Preferences are separate precisely so that changing a language does
not require re-posting a bio.

## Shelves and reviews

```
GET    /api/me/entries
PUT    /api/me/entries/{type}/{slug}     { status, rating?, progress? }
DELETE /api/me/entries/{type}/{slug}
PUT    /api/me/reviews/{type}/{slug}     { rating, body }
DELETE /api/me/reviews/{type}/{slug}
```

`PUT` is an upsert — no need to know whether the entry exists. Status is one of
`shelfStatuses` from `/api/meta`. Progress is shaped by type: `{season, episode}`
for series, `{hours}` for games, `{page}` for books, and nothing for films.

One review per person per work. Writing again edits it and keeps the previous
text, which is what the version history on a review is.

## People and feeds

```
GET  /api/me/feed?scope=following|everyone|me&page=1
GET  /api/users/{username}
GET  /api/users/{username}/entries
GET  /api/users/{username}/followers
GET  /api/users/{username}/following
POST /api/me/follows/{username}          toggles
```

Follow is a toggle rather than a pair of verbs, so a client never has to know
the current state to change it.

## What is new since you last looked

```
GET  /api/me/seen
POST /api/me/seen/{type}/{slug}
POST /api/me/seen/catch-up
```

The catalog gains titles nightly. These mark what an account has already been
shown, which is what the "New" badge reads.

## Errors

A failed request returns a JSON object with an `error` key holding a
snake_case code:

```json
{ "error": "username_already_used" }
```

Codes are stable and worth matching on — `wrong_password`,
`password_unchanged`, `handle_already_set`, `email_already_used`,
`file_too_large`, `unsupported_type`. Validation failures come back as 422 in
Symfony's constraint-violation shape, with a `violations` array naming the
fields.

Status codes mean what they usually mean. 401 means the token is missing or
expired and should be refreshed; 403 means it is valid and not allowed.

## Rate limits

None on the API today. Do not take that as a promise — cache what does not
change, and in particular do not call `/api/search/suggest` on every keystroke
without debouncing. The web client waits 250ms.
