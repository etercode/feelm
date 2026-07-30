# You Know Me — API

Symfony 8.1 (PHP 8.5) JSON API for **You Know Me**. Pairs with the SvelteKit
frontend in `../youknowme-front`.

Auth matches the runbook pattern: opaque Bearer access tokens + refresh tokens
stored in Postgres (no JWT). Login is **username** + password.

## Run it

```bash
docker compose up -d
docker compose exec php bin/console doctrine:migrations:migrate
docker compose exec php bin/console app:catalog:seed
```

| service  | where                                             |
| -------- | ------------------------------------------------- |
| API      | http://localhost:8092                             |
| Postgres | `localhost:5435` — db `youknowme`, user/pass `app` |

```bash
docker compose exec php bin/console about
docker compose exec php composer require <symfony-package>
```

## Auth

| Method | Path | Body |
| ------ | ---- | ---- |
| POST | `/api/register` | `{ username, password, name, tagline? }` → user (no tokens) |
| POST | `/api/login` | `{ username, password }` → TokenResponse |
| POST | `/api/token/refresh` | `{ refresh_token }` → TokenResponse |
| POST | `/api/logout` | `Authorization: Bearer …` → 204 |
| GET | `/api/me` | Bearer → current user |

TokenResponse:

```json
{
  "token_type": "Bearer",
  "access_token": "…",
  "expires_at": "…",
  "refresh_token": "…",
  "refresh_token_expires_at": "…"
}
```

Access tokens live **1 hour**; refresh tokens **30 days**. Refresh rotates both
values on the same `access_tokens` row. Logout soft-deletes that row.

## Catalog (public GET)

| Method | Path |
| ------ | ---- |
| GET | `/api/items?type=&q=&genre=&page=&limit=&sort=` |
| GET | `/api/items/{type}/{slug}` |
| GET | `/api/items/{type}/{slug}/reviews` |
| GET | `/api/upcoming` |
| GET | `/api/search` — full filter set, facets, did-you-mean |
| GET | `/api/search/suggest` — type-ahead, no facet queries |
| GET | `/api/search/filters` — genres, certifications, languages, year span |

`type` is one of `movie`, `series`, `game`, `book`. The resource is still called
`items` on the wire; the tables behind it are described under
[Database](#database).

### Search

One query builder serves browse, search and facets, so a count never describes
a different set of rows than the list next to it.

Filters (repeatable keys or comma lists): `type`, `genre`, `genreMode=any|all`,
`yearFrom`, `yearTo`, `scoreMin`, `scoreMax`, `runtimeMin`, `runtimeMax`,
`certification`, `language`, `person`, `release=any|released|upcoming`,
`sort=relevance|score|popularity|newest|oldest|title|added`, `page`, `limit`.

```bash
curl 'http://localhost:8092/api/search?q=alrm'
# → total 0, suggestion { term: "alarm", total: 2 }

curl 'http://localhost:8092/api/search?type=movie&genre=documentary&yearFrom=1960&yearTo=1969'
# → total 52, facets.genres[], facets.decades[], facets.types{}
```

**How the text matching works.** `works.search_vector` is a stored, weighted
`tsvector` (title A, original title B, tagline C, overview D) with a GIN index,
queried with `to_tsquery` and the last word treated as a prefix so results
appear while you type. Trigram similarity does two narrow jobs beside it:
rescuing a misspelled query that still has to return something, and ranking.

**How "did you mean" works.** `search_terms` holds one row per word the catalog
knows, with a `gin_trgm_ops` index, so a correction is an index lookup. The
alternative — splitting every title at query time — reads the whole table on
every keystroke. Rebuild it with `app:search:refresh-terms`; the crawler already
does after any batch that added titles.

Two rules keep it from being annoying, both learned by watching it misbehave:

1. **A word the catalog knows is never a typo.** Without that guard, searching
   `blacksmith` was answered with "did you mean blacksmiths?" — the search
   doubting a word you spelled correctly.
2. **A correction has to beat what you typed**, not merely return something.
   `garden scene` (3 results) was being "corrected" to `gare scenes` (1).

So corrections only appear for words the catalog has never seen, and only when
the corrected spelling returns strictly more:

```
alarm            2 results   → no suggestion (known word)
alrm             0 results   → "alarm" (2)
blacksmith       4 results   → no suggestion
blaksmith        3 results   → "blacksmith" (4)
lumiere          0 results   → "lumière" (6)     accents recovered by trigram
zzzqqq           0 results   → no suggestion
```

### TMDB crawl (movies + series)

Put credentials in `.env.dev` or `.env.local` ([TMDB docs](https://developer.themoviedb.org/)):

```env
TMDB_API_READ_ACCESS_TOKEN=…   # preferred (Bearer)
TMDB_API_KEY=…                 # fallback
```

Daily resumable batch that **writes straight into Postgres**. Already-stored TMDB
ids are skipped (no second detail request).

```bash
docker compose exec php bin/console app:catalog:crawl
docker compose exec php bin/console app:catalog:crawl --status
docker compose exec php bin/console app:catalog:crawl --limit=500
```

Optional `data/catalog.json` (games/books samples): `app:catalog:seed`.

The client enforces TMDB’s **40 requests / 10 seconds**. Cursor: `data/crawl/state.json`.

Artwork is downloaded into `public/media/tmdb/` (not hot-linked). The API returns
absolute URLs via `PUBLIC_BASE_URL` (default `http://localhost:8092`).

To migrate rows that still have remote `image.tmdb.org` URLs:

```bash
docker compose exec php bin/console app:catalog:localize-media --dry-run
docker compose exec php bin/console app:catalog:localize-media
docker compose exec php bin/console app:catalog:localize-media --limit=100
```

### Starting fresh

```bash
scripts/reset-catalog.sh --yes --media
```

Drops the database and rebuilds it from the migrations, rather than truncating:
that resets identity sequences, cannot leave a stray column behind from a
hand-run `ALTER`, and guarantees the schema is exactly what the migrations
describe. It backs up first, clears the crawl cursors in `data/crawl/`, and ends
with `doctrine:schema:validate`.

There is one migration, and it is the whole schema. The thirteen that built it
incrementally were squashed once the database was empty — that history helped
nobody read it.

It was written from `pg_dump --schema-only`, **not** from
`doctrine:migrations:diff`, because a diff only knows what the ORM knows. It
would have silently dropped the `pg_trgm` extension, the generated
`works.search_vector` column, the GIN/trigram index types (written as plain
btree), the partial indexes on the crawl queue, the rating trigger, and
`tmdb_movie_ids` — which is excluded from schema comparison on purpose.

### pg_trgm

Trigram matching is a Postgres extension, not a library we ship. It provides
`similarity()`, the `%` operator, and the `gin_trgm_ops` index class, and it is
what makes three things possible:

| where | what it does |
| --- | --- |
| "did you mean" | finds the closest known word in `search_terms` |
| search rescue | a misspelled query still matches on `works.title` |
| person filter | matching a name typed loosely |

It ships with the `postgres:18-alpine` image (part of contrib), so nothing needs
installing — it just has to be switched on, per database, once:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
```

That is the first statement of the baseline migration, because every trigram
index below it refers to the operator class it provides. A fresh database
therefore gets it automatically; nothing manual to remember.

Default similarity threshold is 0.3 — `similarity('blaksmith','blacksmith')` is
0.62, comfortably a match.

### Not losing it

The crawl runs for days and is meant to run once, so the database is worth more
than the container it lives in.

```bash
scripts/backup.sh                    # verified pg_dump -Fc into var/backups
KEEP=20 scripts/backup.sh            # keep more snapshots

# every six hours while a crawl is running
0 */6 * * * cd /path/to/youknowme && scripts/backup.sh >> var/log/backup.log 2>&1
```

Each snapshot is checked with `pg_restore --list` before it is kept, and the
restore path is exercised, not assumed:

```bash
docker compose cp var/backups/<file> database:/tmp/restore.dump
docker compose exec database pg_restore -U app -d youknowme --clean --if-exists /tmp/restore.dump
```

**`docker compose down` is safe — `docker compose down -v` is not.** The data
lives in the `youknowme_database_data` volume; `-v` deletes it, and with it the
days of crawling.

### Crawling every movie

`app:catalog:crawl` walks `/discover` by release year. That is right for picking
up what is new, but it cannot enumerate the whole catalog: discover caps at 500
pages per query, its ordering shifts underneath a long crawl as titles are added,
films with no release date never appear in a date window, and the cursor starts
at 1960 so everything before that is invisible to it.

`app:catalog:crawl-all` uses TMDB's [daily id export](https://developer.themoviedb.org/docs/daily-id-exports)
instead — one gzipped file with every movie id, currently **1,226,544** of them.
It loads that into `tmdb_movie_ids`, then each run takes the ids that have no
`external_ids` row yet, most popular first. "What is left" is an anti-join, not a
cursor, so the crawl is safe to stop, restart, or run twice, and the catalog is
useful long before it is complete.

```bash
docker compose exec php bin/console app:catalog:crawl-all --status
docker compose exec php bin/console app:catalog:crawl-all --limit=500 --no-media
```

**Starting from a year.** The export lists ids and popularity and nothing else,
so it cannot tell you when a film came out — the year only arrives with the
detail request you were trying to avoid. `/discover` does return release dates
alongside ids, twenty per page, so `app:catalog:queue` walks it month by month
and fills `release_year` in the queue. After that the crawl can skip whole
decades without spending a request on them:

```bash
# ~2.7 h of enumeration for 1990 onwards; resumable, run it until it says complete
while :; do docker compose exec -T php bin/console app:catalog:queue --since=1990 --requests=2000 || break; done

# then crawl only what was dated 1990 or later
docker compose exec php bin/console app:catalog:crawl-all --since=1990 --limit=500 --no-media
```

Months rather than years because discover caps at 500 pages (10,000 results) per
query and recent years run past 50,000 releases — 2025 alone has 53,843. A month
has never come close.

| span | titles | at ~4/s |
| --- | --- | --- |
| everything in the export | 1,226,544 | ~85 h |
| **1990 onwards** | **775,166** | **~54 h** + ~2.7 h enumerating |
| before 1990 | 266,569 | skipped |
| no release date at all | ~122,500 | skipped — a year filter only counts dated rows rather than guessing |

A year filter deliberately ignores queue rows that have no year: an undated
export row could be from any decade, and assuming otherwise would quietly crawl
the years you meant to skip.

**How long it takes, measured on this machine:**

| | rate | 1.23 M movies |
| --- | --- | --- |
| default (mirrors posters) | 0.9 titles/s | ~375 h, about 15 days |
| `--no-media` | 4.0 titles/s | ~85 h, about 3.5 days |

The gap is images: every title otherwise downloads a poster and a backdrop from
the CDN, serially. At ~64 KB per title that is also **~77 GB of disk** for the
full catalog, against ~40 GB for the database itself (~34 KB per work). Unless
you want your own copy of TMDB's artwork, crawl with `--no-media` and let the
posters come from their CDN.

**The rate limit is not the bottleneck.** TMDB's old published ceiling of 40
requests per 10 seconds is what `TMDB_REQUESTS_PER_10S` defaults to, but a round
trip to the API measures ~290 ms from here, so a serial crawl tops out near 4
requests per second on latency alone — raising the allowance to 100 per 10 s
moved the measured rate from 4.0 to 4.3 titles/s and no further. The only lever
that would genuinely help is issuing several requests concurrently; until that
exists, plan for ~3.5 days and leave the limit where it is.

**Running it unattended.** The command does a bounded amount of work and exits,
so a loop or a timer is all it needs:

```bash
# one batch, a short pause, repeat
while docker compose exec -T php bin/console app:catalog:crawl-all --limit=500 --no-media; do sleep 5; done

# or a cron entry, which self-limits and survives reboots
*/15 * * * * cd /path/to/youknowme && docker compose exec -T php bin/console app:catalog:crawl-all --limit=3000 --no-media >> var/log/crawl.log 2>&1
```

Both are resumable: whatever was stored stays stored, and the next run picks up
the next unfetched ids. Once a day, follow it with the IMDb import below, which
covers everything crawled since the last one.

### IMDb ratings

IMDb has no ratings API and blocks scripted page requests, but it publishes
`title.ratings.tsv.gz` daily — every rated title with its average and vote
count. One 8 MB download beats a million requests, and it is the route IMDb
actually sanctions (**non-commercial use only**:
[developer.imdb.com/non-commercial-datasets](https://developer.imdb.com/non-commercial-datasets/)).

```bash
# ids: new titles get theirs during the crawl; this catches up older rows
docker compose exec php bin/console app:catalog:imdb-ids

# ratings: download, match on stored ids, refresh the primary score
docker compose exec php bin/console app:catalog:imdb-ratings
docker compose exec php bin/console app:catalog:imdb-ratings --file=/tmp/title.ratings.tsv.gz
```

Worth running daily — the dataset changes daily, so there is no point tying it to
a crawl batch. 1.7 M dataset rows scan in about a second; the file is streamed and
only rows matching an id we hold are kept, so memory is bounded by the catalog
rather than by IMDb. Past a few million works, load the file into a staging table
with `COPY` and join in the database instead.

The join key is the `imdb_id` TMDB reports (`tt0392728`), stored in
`external_ids` alongside the TMDB id. Roughly a third of the very old titles in
the catalog have no IMDb id on TMDB at all; those simply keep their TMDB score.

## Shelf, reviews, social (Bearer required)

| Method | Path | Notes |
| ------ | ---- | ----- |
| GET | `/api/me/entries` | Current user's shelf |
| PUT | `/api/me/entries/{type}/{slug}` | `{ status, rating?, progress? }` or `{ clear: true }` |
| DELETE | `/api/me/entries/{type}/{slug}` | Clear shelf row |
| PUT | `/api/me/reviews/{type}/{slug}` | `{ rating, body }` — edit pushes history |
| DELETE | `/api/me/reviews/{type}/{slug}` | |
| POST | `/api/me/follows/{username}` | Toggle follow |
| GET | `/api/me/feed?scope=following\|everyone&limit=` | Activity from entries |
| POST | `/api/me/seen/{type}/{slug}` | Mark item seen (NEW badges) |
| POST | `/api/me/seen/catch-up` | Mark all catalog items seen |
| GET | `/api/me/seen` | Seen item ids |

## Profiles (public GET)

| Method | Path |
| ------ | ---- |
| GET | `/api/users/{username}` | Profile, stats, shelf, reviews |
| GET | `/api/users/{username}/followers` | |
| GET | `/api/users/{username}/following` | |

When the request is authenticated as someone else, the profile also includes
`isFollowing` and `shared` shelf overlap.

## Database

The catalog table is **`works`** — every row is a creative work, which is also
the word schema.org uses for the movie / series / game / book family, and it
does not collide with the `title` column the way `titles` would. "Item" said
nothing about what was stored.

```
works ──┬── work_genre ── genres          many-to-many: a work has genres,
        │                                 a genre has works
        ├── credits ── people             role = cast|director|writer|creator|
        │                                 developer|publisher|author, with
        │                                 character and billing position
        ├── external_ids                  (source, external_id) unique —
        │                                 how a re-crawl finds its own row,
        │                                 and how IMDb ratings are joined
        ├── work_ratings                  one row per source: IMDb 7.4/10,
        │                                 TMDB 87/100, with vote counts
        ├── seasons ── episodes           series only
        ├── entries                       one per (user, work): shelf status,
        │                                 rating, progress
        ├── reviews ── review_versions    one per (user, work), full edit trail
        └── seen_marks                    titles opened since catching up
```

The rule the columns follow: **anything the app filters or sorts on is a
column, anything a work can have several of is its own table, and `extra`
JSONB holds only display-only leftovers** (collection membership, game
platforms and perspectives, ISBN). Nothing queries `extra`.

So `release_date`, `runtime_minutes`, `certification`, `original_language`,
`original_title`, `vote_count`, `popularity`, `page_count` and `publisher` are
real columns — sparse for the types that don't use them, which costs nothing in
Postgres and keeps every filter a simple indexed predicate.

**Ratings are rows, not columns.** `work_ratings` keeps each source's opinion in
that source's own units (`rating` + `scale` + `votes`), because a column per
source is the same mistake as a JSON array of genres — Metacritic and Rotten
Tomatoes would each want another one. IMDb wins where we have it, TMDB covers the
rest, and the API sends every source it holds so the UI can label the number
instead of always crediting TMDB.

`works.external_score` is the one denormalisation, and it is deliberate: it is
the indexed key browse and search sort by, and sorting a filtered set of a
million rows through a correlated subquery is a different proposition. **A
trigger on work_ratings maintains it** — the same arrangement as `search_vector`.
Nothing writes it from PHP; the column is mapped `insertable: false,
updatable: false` so it cannot be written by accident.

There used to be a `score_source` column alongside it, recording which source the
cached number came from. It went: nothing read it that could not read `ratings`,
and it drifted within a day of being added — the crawler set `external_score`
directly without recording a rating row, leaving eight titles with a score and no
source. Two copies of one fact, kept in step by hand, is the `is_upcoming`
mistake wearing a different hat.

Search can filter on `imdbMin` (IMDb's own 0–10 scale) and `votesMin`, and sort
by `sort=imdb` — unrated titles sort last rather than pretending to be zero.

There is no `is_upcoming` flag. Upcoming is `release_date > CURRENT_DATE`,
evaluated when asked; a stored copy silently goes stale the day a film opens.

Constraints worth knowing:

- Unique `(type, slug)` on works; seasons/episodes unique per parent number
- Unique `(source, external_id)` on external_ids
- Unique `(work, source)` on work_ratings; CHECK that the rating fits its scale
- Unique `(work, person, role, character_name)` on credits, with
  `character_name` NOT NULL DEFAULT `''` — two NULLs are distinct in Postgres,
  so a nullable column would have allowed duplicate crew rows
- Unique `(user, work)` on entries, reviews and seen_marks
- Unique follow pair; CHECK `follower_id <> followed_id`
- CHECK status / rating ranges on entries and reviews; CHECK on work type and
  credit role
- `users.seen_up_to` marks a wholesale catch-up, so "mark all as seen" is one
  timestamp write rather than a row per catalogued work
- Feed is derived from `entries` (no separate activity table)

`works.search_vector` and `search_terms` have no entity. `App\Doctrine\GeneratedSchemaListener`
declares them during schema generation so `doctrine:migrations:diff` does not
try to drop them, and `tsvector` is mapped to `string` in `doctrine.yaml`.
`doctrine:schema:validate` is clean.

## Packages

Only Symfony Flex / Doctrine official packs (same spirit as runbook):

- `doctrine/orm`, `doctrine-bundle`, `doctrine-migrations-bundle`
- `symfony/security-bundle`, `serializer`, `validator`, `property-access`
- `symfony/maker-bundle` (dev)

No API Platform, no JWT bundles, no Nelmio CORS — CORS is a small
`CorsSubscriber` (`CORS_ALLOWED_ORIGINS` in `.env`).
