# Timeline feature — plan

Show gallery images grouped by **month and year**, newest first, and let the viewer scroll
back into the past. Two scopes:

1. **Per-AP timeline** — the images of one AP gallery (`pub` or `priv`).
2. **Network timeline** — all galleries of all areas and APs in one stream.

A photo is placed by its **taken date** (EXIF `DateTimeOriginal`), falling back to its
**upload date** when the taken date is missing or implausible.

Status: plan only, nothing implemented. Open questions are resolved (section 10).

---

## 1. Why a database index is needed

Today the filesystem is the only record: `GalleryStorage::imageNames()` lists a directory,
sorted by name. That works for one AP, but not for a timeline:

- **The network timeline needs one sorted, paginated stream across every AP directory.**
  Building it from the filesystem would mean scanning every directory and reading EXIF from
  every file on each request.
- **Reading EXIF is not free.** It is cheap for one file but adds up over thousands, so the
  result must be stored, not recomputed.
- **Month grouping and "jump to month" need cheap aggregate queries** (count per month).

**Decision:** add a `gallery_images` table as an **index** of what is on disk. The disk stays
the source of truth for whether a file exists. The table stores derived metadata (dates) and
answers ordering and pagination.

Alternatives considered and rejected:

- **A JSON sidecar file per AP directory caching the dates.** This is fine for the per-AP view,
  but the network timeline would still have to merge hundreds of sidecars on each request.
- **Reading EXIF on each request with `Cache::remember`.** Cache invalidation on upload or
  trash, plus a cold-cache cost on large galleries; it also doesn't solve cross-AP pagination.

## 2. Data model

Migration `create_gallery_images_table`:

| column | type | notes |
| --- | --- | --- |
| `id` | id | tie-breaker for stable ordering |
| `area_id` | unsignedInteger | |
| `ap_id` | unsignedInteger | |
| `visibility` | string(4) | `pub` / `priv` |
| `filename` | string | as stored on disk (no trash prefix) |
| `taken_at` | datetime, nullable | EXIF `DateTimeOriginal`, when plausible |
| `uploaded_at` | datetime | file mtime (for new uploads, the upload time) |
| `sort_at` | datetime | `taken_at ?? uploaded_at`; the timeline orders by this |
| `timestamps` | | |

Indexes:

- unique `(visibility, area_id, ap_id, filename)`, for upserts and lookups
- `(visibility, area_id, ap_id, sort_at, id)` for the per-AP timeline
- `(visibility, sort_at, id)` for the network timeline

Model `App\Models\GalleryImage` with a factory, including states for `withoutTakenDate()` and
`private()`. `sort_at` is set in a model `saving` hook so it can never drift from its inputs.

Trashing deletes the row. The trashed file on disk remains the audit trail, and restoring a
file by renaming it is picked up by the reconcile command (section 5).

**Month grouping:** group by `sort_at` formatted `Y-m` **in PHP** for page rendering. For the
month index (counts per month), use one aggregate query. SQLite and MySQL format dates
differently (`strftime` vs `DATE_FORMAT`), so either:

- (a) add a denormalised `sort_month` `char(7)` column (`2024-05`), indexed and portable,
  set in the same `saving` hook; or
- (b) use a driver-specific expression.

**Recommendation: (a).** It is simple, portable and indexable.

## 3. Determining the date

New class `App\Services\ImageDate` with one method, `takenAt(string $absolutePath): ?CarbonImmutable`.

- Use `exif_read_data($path, 'EXIF')` and read `DateTimeOriginal`, falling back to
  `DateTimeDigitized`. Do **not** use IFD0 `DateTime`: it is the last-edit time.
- `exif_read_data` only reads headers, so it is cheap even for 50 MB files. Intervention's
  `exif()` is not suitable because it requires a full decode.
- **Robustness:**
  - EXIF is user-supplied binary data. Laravel turns PHP warnings into exceptions, so call it
    with `@` inside `try/catch (\Throwable)` and treat any failure as "no date".
  - Parse strictly with the format `Y:m:d H:i:s`.
  - Reject placeholder values such as `0000:00:00 00:00:00` or blanks.
  - Reject implausible dates: before 1990, or more than one day in the future. This catches
    cameras with an unset clock (1970, 2000-01-01) and bad clocks.
- **Formats:** JPEG (and TIFF) carry EXIF that PHP can read. PNG and GIF effectively never do.
  WebP/EXIF support in `exif_read_data` must be **verified during implementation**; if it's
  unsupported, WebP falls back to the upload date.
- **Timezone:** EXIF dates have no zone (`OffsetTimeOriginal` is rarely present), so they are
  *local wall-clock* time. To keep `sort_at` on a single scale, **store every date column as
  `Europe/Prague` wall-clock time**: EXIF as is, and mtime / `client_modified_at` converted
  from UTC.
  - Mixing UTC upload times with local EXIF times would misorder photos by up to 2 hours.
  - It would also put an upload made just after midnight on the 1st into the previous month.
  - Open question: should `app.timezone` become `Europe/Prague` instead? See section 10.
- **`ext-exif`:** present on this machine and in Ubuntu's `php8.3-common`. Add `ext-exif` to
  `composer.json` `require` (approved) and `exif` to the CI workflow's extension list.

**Adopted:** the upload JS already has `File.lastModified`, which is often the
capture date for files copied straight from a phone or camera. Send it with the final chunk as
`client_modified_at` and use the chain **EXIF → client lastModified → upload time**.

- Caveat: for downloaded or re-shared files, lastModified is the download date. That is still
  no worse than the upload time.
- This is only possible for new uploads; existing files only have their mtime.

## 4. Keeping the index in sync

Ways files appear or disappear, and how each is handled:

| event | handling |
| --- | --- |
| upload through the app | `GalleryController::uploadChunk` records the row after `assembleUpload` succeeds |
| soft delete through the app | `GalleryController::destroy` deletes the row after `trash()` |
| files copied onto the server, restored from trash, or existing images at deploy | `php artisan gallery:index` (section 5) |
| per-AP timeline visited with unindexed files | lazy reconcile for that AP (below) |

New service `App\Services\GalleryIndex` holds this logic: `record()`, `forget()`,
`reconcileAp()` and `reconcileAll()`. `GalleryStorage` stays purely disk-oriented, and the
controller calls both.

- **Lazy per-AP reconcile:** the per-AP page already lists the directory (cheap). It diffs that
  listing against the DB filenames for the AP, indexes the missing files and deletes rows whose
  file is gone.
  - Reading EXIF for *new* files happens once. It is **capped per request** (for example 200
    files) so a first visit to a large legacy gallery stays fast; the rest is left to
    `gallery:index`.
  - When capped, the page shows a short note ("probíhá indexace…").
- The network timeline does **not** reconcile lazily (scanning every directory per request is
  exactly what we're avoiding). It relies on upload/trash hooks plus the command.

**Failure ordering:** the file is written first and the row second. If recording the row fails,
the file is still served by the grid, and the next lazy reconcile or `gallery:index` indexes it.
The reverse order could leave rows pointing at missing files.

**Concurrency:** use `upsert` on the unique key, so a lazy reconcile racing an upload can't
create duplicates. SQLite write locking is fine at this traffic level.

## 5. Artisan command `gallery:index`

`php artisan gallery:index {--area=} {--ap=} {--dry-run}`

- Walks `gallery/ap/{area}/{ap}/{pub,priv}`, skipping `thumbs/`, trashed files and non-image
  extensions.
- Upserts missing rows (reading EXIF) and deletes rows whose file is gone.
- Prints a summary: added, removed, how many have a taken date, and how many fell back to
  mtime.
- `--dry-run` also prints the **month distribution**. This is a sanity check for the main
  backfill risk: if existing files were copied without preserving mtimes, every image without
  EXIF lands in the deployment month (see section 9).
- Run it once at deployment, and **daily via the scheduler** (`Schedule::command('gallery:index')->daily()`
  in `routes/console.php`). Add the deploy step and the `schedule:run` crontab line to the README.

## 6. Per-AP timeline (phase 2)

**Routes,** mirroring the existing ones:

- `GET /gal/area/{area}/ap/{ap}/pub/timeline` → `gallery.public.timeline`
- `GET /gal/area/{area}/ap/{ap}/priv/timeline` → `gallery.private.timeline` (inside the `auth`
  group)

**UI:**

- A toggle on the gallery page: **Mřížka | Časová osa**.
- The grid stays the default; the timeline is a separate page. Changing the default is a later
  product decision (section 10).
- Month sections, newest first, each with a heading such as "květen 2024" and a count. The
  heading is localised with Carbon `locale('cs')->isoFormat('MMMM YYYY')`.
- A **year/month index** (sticky side list on desktop, a `<select>` on mobile) built from the
  per-month count query. It lets the viewer jump far back without scrolling through everything.
- Managers keep the dropzone and the delete button. Extract the existing image tile into a
  Blade component `<x-gallery.tile>` that both the grid and the timeline use (phase 0), so
  delete, lazy loading and touch behaviour stay identical.

**Pagination:** cursor-based, keyed on `(sort_at desc, id desc)`, with pages of about 60 images.

- **Not month-based:** a single month can hold hundreds of images (one documentation day), so
  pages are sized by image count, and a month may span several pages.
- **Jumping:** `?from=2023-06` starts the listing at the first image on or before the end of
  that month, and a "Novější" link at the top goes back to the newest.

**"Scroll back":**

- Without JS: an **"Starší" link** to the next cursor. This works without JS and is what the
  feature tests exercise.
- With JS: an `IntersectionObserver` in `resources/js/timeline.js` (vanilla, matching
  `gallery.js`) fetches the next page as an HTML fragment (`?cursor=…`, `Accept: text/html`,
  rendering a partial view) and appends it.
- Each section carries `data-month="2024-05"`. When a fragment's first section has the same
  month as the last section on the page, its tiles are moved into the existing section, so a
  month split across pages renders as one block.
- **History and back button:** update `?from=` with `history.replaceState` as month headings
  scroll past, so reloading or going back returns near the same place. This is a small cost
  for a large usability gain.

## 7. Network timeline (phase 3)

**Route:** `GET /timeline` → `timeline`, linked from the header ("Časová osa").

**Visibility:**

- **Guests see only `pub`.**
- **Logged-in users:** recommended `pub` only by default, with a toggle "včetně Dokumentace"
  that adds `priv`, and a lock badge on private tiles. Documentation photos are technical and
  would drown out the public photos otherwise.
- **Authorisation is enforced in the query.** `priv` rows are added only when
  `auth()->check()`, never by trusting a query parameter alone. The image URLs are also
  protected by the existing `auth` middleware, so a leak would show only names, but the list
  must not leak them either.

**APs removed from Userdb:** their gallery and image routes return 404 (`resolveAp`). The
network query must **exclude rows whose AP is no longer in Userdb**, or the page shows broken
thumbnails.

- Filter in SQL with the valid AP set from `UserdbService::areas()` (cached), not in PHP after
  fetching, because that would break page sizes.
- AP ids are globally unique in Userdb (confirmed), so `whereIn('ap_id', $validApIds)` is
  enough.

**Tiles** show the AP name (from Userdb) under the thumbnail and link to the AP's gallery.

Pagination, the month index, JS and fragments are **the same as the per-AP timeline.** Build
them once in phase 2, parameterised by a query scope, so phase 3 is mostly a new route, query
and header link.

**Performance:** each thumbnail is its own request through Laravel; the per-request cost is
`resolveAp` plus a cache read. With the new cache headers, a 60-tile page costs at most 60
requests once, then 304s or browser cache. This is acceptable; no change needed now.

## 8. Testing (Pest, feature tests unless noted)

- **`ImageDate`** (unit):
  - Reads `DateTimeOriginal`; falls back to `DateTimeDigitized`; ignores IFD0 `DateTime`.
  - Rejects `0000:00:00`, dates before 1990 and future dates.
  - Survives a corrupt APP1 segment.
  - Fixtures: a small test helper that injects a hand-built EXIF APP1 segment into a GD JPEG.
    No binary fixtures in the repo, and no new dependency.
- **Index sync:**
  - An upload creates a row with `taken_at` from EXIF.
  - An upload without EXIF uses the upload time, or `client_modified_at` if adopted.
  - Trashing deletes the row.
  - The unique key prevents duplicates when reconcile and upload race.
- **`gallery:index`:**
  - Adds files that are on disk but not indexed.
  - Removes rows for missing files.
  - Skips `thumbs/`, trashed and non-image files.
  - `--dry-run` writes nothing.
- **Per-AP timeline:**
  - Months are newest first, with the correct headings.
  - The fallback to mtime works (set mtime with `touch()` on the fake disk's path).
  - Trashed files are absent.
  - Cursor pages are disjoint and complete.
  - `?from=` lands on the right month.
  - The private timeline redirects guests to login.
  - The lazy reconcile cap is respected.
- **Network timeline:**
  - Guests never see `priv` rows, even with the toggle parameter.
  - Authenticated users see `priv` with the toggle.
  - Rows for APs missing from Userdb are excluded.
- **JS** (merging split months, IntersectionObserver): no JS test setup exists. Keep the JS
  thin and test the server fragment contract (`data-month`, the next-cursor link). Pest 4
  browser tests are possible later, but they would add Playwright to CI; ask first.

## 9. Risks and critical review

1. **Backfill dates may be wrong in bulk.** Existing files without EXIF get their mtime. If
   the gallery data was ever copied without preserving mtimes (`cp` without `-p`, some
   backups), they all land in one month.
   - Mitigation: the `--dry-run` distribution report before the first real run.
   - If mtimes are bad, there is no automatic fix. Those images cluster in one month, and a
     later "edit date" feature for managers is the remedy (out of scope).
2. **EXIF is missing more often than expected.** Messaging apps, screenshots and editors strip
   it. Expect a share of images on upload dates. The `client_modified_at` fallback recovers
   some of them for new uploads.
3. **Index drift.** Disk changes made outside the app aren't seen by the network timeline
   until `gallery:index` runs: at most a day with the daily schedule, or immediately if the
   command is run by hand after manual disk operations.
4. **Timezone month boundaries.** Covered by converting mtimes to `Europe/Prague` before
   grouping (section 3). It is easy to get wrong, so there's an explicit test at a month
   boundary.
5. **Privacy in the network view.** Enforced in the query, and tested with a guest plus the
   toggle parameter.
6. **Scope creep.** The per-AP timeline could tempt switching the existing grid to the DB as
   well. **Don't.** The grid keeps reading the disk in this project, which limits the blast
   radius if the index has bugs. Revisit once the index has proven itself.
7. **Large galleries on first view.** Handled by the lazy-reconcile cap and the command.
8. **Not covered: EXIF GPS in public originals.** Public originals are served unmodified, so
   any GPS coordinates in them are public. This isn't caused by the timeline, but the timeline
   makes old photos more discoverable. Consider stripping GPS on upload as a separate task.

## 10. Decisions

Confirmed by you:

1. **`ext-exif`** is added to `composer.json` `require`, and `exif` to the CI extensions.
2. **`client_modified_at`** is adopted. The date chain is **EXIF → client lastModified → upload time**.
3. **The server can run cron** for `php artisan schedule:run`. `gallery:index` is scheduled
   daily, and the README deploy section gets the crontab line.
4. **Userdb AP ids are globally unique.** The network timeline filters with
   `whereIn('ap_id', …)`; no `ap_key` column is needed.

Not explicitly answered, so the plan follows the recommendation unless you say otherwise:

5. **Default view per AP:** the grid stays the default; the timeline is reached through the
   toggle.
6. **Network timeline and `priv`:** public only by default, with an opt-in toggle
   "včetně Dokumentace" for logged-in users.
7. **Timezone:** keep `app.timezone` as `UTC` and store timeline dates as `Europe/Prague`
   wall-clock time (section 3).

## 11. Phases and deliverables

Each phase is a separate commit, or PR, with its own tests, and is shippable on its own.

| phase | scope | depends on |
| --- | --- | --- |
| 0 | Extract `<x-gallery.tile>` from `gallery/show.blade.php`; no behaviour change | — |
| 1 | Migration, `GalleryImage` model and factory, `ImageDate`, `GalleryIndex`, upload/trash hooks (including `client_modified_at` from the upload JS), `gallery:index` command and daily schedule, `ext-exif` in `composer.json`, README deploy and cron steps, CI `exif` extension | 0 |
| 2 | Per-AP timeline: routes, controller actions, views and partial, month index, cursor pagination, `timeline.js`, grid/timeline toggle, lazy reconcile | 1 |
| 3 | Network timeline: `/timeline`, visibility toggle, Userdb AP filter, header link | 2 |

**Deployment for phases 1 to 3:** `php artisan migrate --force`, then
`php artisan gallery:index --dry-run` (check the distribution), then `php artisan gallery:index`.
