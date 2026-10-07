# Confluence import — plan

Let a gallery manager paste a Confluence page URL such as
`https://doc.hkfree.org/spaces/fotogalerie/pages/22020482/Foto+v%C3%BDhled` into an AP
gallery, click a button, and get all photos from that page imported into that gallery.
Optionally, each photo gets a short, useful description: which AP it was taken from, which
direction it looks, which other APs lie that way, and what kind of scene it shows.

Status: implemented (phases 0–4). User documentation in Czech: `docs/confluence-import/`. Branch `confluence-import`, based on `timeline`: the
import reuses the timeline index and its date handling.

---

## 1. What is on the Confluence side

Checked against the live server on 2026-10-01:

- **Server:** `doc.hkfree.org` runs **Confluence Data Center 10.2.18**. The page and the REST
  API (`/rest/api/content/…`) are readable **anonymously** for the `fotogalerie` space.
- **Size of the space:** 657 pages and **10,539 attachments**. It is effectively the whole
  legacy photo archive, so this feature will be used repeatedly.
- **Example page** (id 22020482, "Foto výhled"):
  - parent pages: Home › Benesovka
  - body: a `gallery` macro titled "Vyhled z Benesovky", plus one sentence of text
  - attachments: 11 JPEGs of 5–8 MB each (about 75 MB in total), uploaded in 2011
- **Three page shapes exist** (sampled from the newest pages):
  1. **`gallery` macro:** shows all image attachments of the page; it can be narrowed by the
     macro's `include` and `exclude` parameters.
  2. **Embedded images:** `<ac:image><ri:attachment ri:filename="…"/></ac:image>` elements. These
     are an explicit, ordered subset of the page's attachments; one page has 133 of them.
  3. **Index pages:** no images, only links to child pages (Karosarna links 34).
- **No captions exist.** Attachments have no comments or labels, and many pages have no text
  at all, only a title such as `vyhledy_karosarna_2026`. Any description has to come from the
  images themselves.
- **Parent page ≈ AP name,** but not exactly: "Benesovka", "Plotiště AP", "Dvorská",
  "Karosarna". That is good enough for a warning, not for automatic mapping.
- **Some pages group images under date headings** ("2026 - březen", "2022 - prosinec"). Not used
  in this plan (see section 11).

Relevant REST calls (Confluence DC REST API v1):

| Purpose | Call |
| --- | --- |
| Page title, body, version, space, parents | `GET /rest/api/content/{id}?expand=body.storage,version,space,ancestors` |
| Attachments, paged | `GET /rest/api/content/{id}/child/attachment?limit=200&start=…&expand=version` |
| Child pages (for index pages) | `GET /rest/api/content/{id}/child/page?limit=200` |
| Download | the attachment's `_links.download` (relative to the base URL) |

## 2. Decisions taken

| Question | Decision |
| --- | --- |
| Entry point | In an AP gallery (grid or timeline), for managers. Photos go into that gallery: public, or Dokumentace if opened there. |
| Index pages | Import only the pasted page. A page without photos imports nothing and lists its child pages as links. |
| Descriptions | One per photo, **optional** per import. After the test (section 7): built from direction, position and geometry, plus a scene tag, instead of free-form captions. |
| Direction | From the photo's position and compass heading when EXIF has them; otherwise from the file name, or set by a manager. |
| Resources for a model on the server | About 1–2 GB of RAM; the chosen design needs no model on the server. |
| Where the model runs | In the manager's browser (scene tags only, ~0.1 s per photo). |

## 3. User flow

1. A manager opens an AP gallery and clicks **Import z Confluence** (next to the dropzone).
2. They paste a page URL, optionally tick **Rozpoznat typ scény** (tag the scene type), and
   click **Načíst** (load).
3. **Preview:** the server reads the page (two or three API calls, no downloads) and shows:
   - page title, space and parent pages
   - number of photos found and their total size
   - how many are already imported into this gallery
   - photos that will be skipped, and why
   - a warning if the parent page name doesn't resemble this AP's name
   - for an index page: "no photos", with links to the child pages
4. The manager clicks **Importovat**. A background job downloads the photos one by one. The
   gallery shows a progress line ("Importuji 4/11…") that updates on its own.
5. When it finishes, the photos are in the gallery and on its timeline:
   - Headings found in EXIF or file names are already set.
   - If scene tags were requested, the manager's browser tags the photos (section 7), with its
     own progress line.
   - Photos without a direction show a compass button for the manager.

Importing the same page again adds only photos that are new or changed since the last import.

## 4. Which photos a page yields

Parse the page's storage-format body (XHTML with `ac:` and `ri:` namespaces) using `DOMDocument`
(`ext-dom` is installed):

- Wrap the body in a root element that declares the `ac` and `ri` namespaces.
- Convert HTML named entities such as `&nbsp;` to numeric ones before loading. The storage
  format is not valid stand-alone XML.

| Found in the body | Photos |
| --- | --- |
| `gallery` macro | All image attachments of the page, filtered by the macro's `include`/`exclude` (comma-separated file names), ordered as on the page |
| `ac:image` with `ri:attachment` and no `ri:page` | That attachment of this page, in page order, without duplicates |
| `ac:image` with `ri:attachment` on **another** page (`ri:page`) | Skipped and listed: "obrázek z jiné stránky". Supporting this is out of scope. |
| `ac:image` with `ri:url` (external image) | Skipped and listed. External URLs are never fetched (SSRF, section 9). |
| Both macro and `ac:image` | The union: macro attachments, plus embedded ones not already included |
| Neither | No photos; show child pages |

An attachment counts as a photo when its media type is `image/jpeg`, `image/png`, `image/gif`
or `image/webp`, its extension is in `GalleryStorage::ALLOWED_EXTENSIONS`, and it is at most
50 MB (the same limit as uploads). The downloaded file must also pass `getimagesize`. Anything
else is skipped with the reason.

## 5. Import pipeline

**Why a queue:** one page can mean 1 GB of downloads (133 photos × ~7 MB). That can't happen
inside a web request.

- **Queue:** `QUEUE_CONNECTION=database` is already configured, but production runs no worker.
  The scheduler cron already exists (timeline), so instead of adding supervisor, run the worker
  from it:

  ```php
  Schedule::command('queue:work --stop-when-empty --max-time=50 --memory=256')
      ->everyMinute()->withoutOverlapping(5);
  ```

  - The `5` makes the overlap lock expire after 5 minutes. The default is 24 hours, so a killed
    worker would otherwise block all imports for a day.
  - A long import continues across minutes, because the job is chunked (below).
  - This needs no new service and no root, and it is already covered by the `www-data`
    crontab line.
- **Job `ImportConfluencePage`:** starts attachments for at most **20 seconds** (originally 30; changed after review), then
  re-dispatches itself for the rest.
  - The chunk is time-boxed rather than a fixed count, because a slow link can make 10 files
    take minutes.
  - The job's `$timeout` (85 s, allowing one more 50-second download plus storing) stays below the database queue's `retry_after` (90 s). A job
    that ran past `retry_after` would be started a second time, in parallel with itself.
  - A crash loses at most the file being processed.

  For each attachment:
  1. Skip it if this attachment id and version were already imported into this gallery.
  2. If the item already has a `stored_filename` whose file exists, a previous run stored it
     and crashed before finishing. Only index it, don't download again; otherwise a retry
     would create a duplicate (`name-1.jpg`).
  3. Download it as a stream into a temp file under `gallery/tmp/`, with a size cap,
     same-host-only redirects and a timeout.
  4. Validate it as an image, then store it through the same code path as uploads, including
     the thumbnail and the memory guard. **Save `stored_filename` on the item immediately**
     (for step 2).
  5. Index it with the date chain **EXIF → attachment creation date → download time**.
     - The attachment date (here 2011-05-25) is a much better fallback than "today". It goes
       into the existing `client_modified_at` slot, which becomes "date reported by the source"
       (update its docs).
  6. Record the item as imported, failed (with a reason) or skipped.
- **Reuse, don't duplicate:**
  - Extract `GalleryStorage::storeImage(visibility, area, ap, File, originalName): string` from
    the private `persistImage`. Uploads and imports then share file naming, thumbnails and the
    memory guard.
  - `GalleryIndex::record()` gains an optional `?CarbonImmutable $sourceDate`, replacing the
    millisecond parameter.
- **Disk space:** before queueing, check `disk_free_space()` against the import's total size
  plus a 1 GB margin, and refuse with a clear message. Importing the whole space would need
  tens of GB.
- **Progress:** stored on the import record. The page polls a small JSON endpoint every
  2 seconds while an import of this gallery is running, and also works with a plain reload.
- **Failure:**
  - A failed download is retried twice (backoff 10 s, 60 s), then marked failed. The import
    continues with the next file.
  - A failed page fetch fails the import as a whole.
  - The import ends as "done", or as "done with errors", listing the failed files.

## 6. Data model

`confluence_imports`, one row per import run:

| Column | Notes |
| --- | --- |
| `id`, timestamps | |
| `user_id` | who started it |
| `area_id`, `ap_id`, `visibility` | target gallery |
| `page_id`, `page_title`, `page_version`, `page_url` | source |
| `with_scene_tags` | bool |
| `status` | `queued`, `running`, `done`, `failed` |
| `total`, `imported`, `skipped`, `failed` | counters |
| `error` | text, nullable |

`confluence_import_items`, one row per attachment considered:

| Column | Notes |
| --- | --- |
| `import_id` | |
| `attachment_id`, `attachment_version`, `original_filename`, `size` | |
| `stored_filename` | name in the gallery, nullable |
| `status` | `pending`, `imported`, `skipped`, `failed`; plus `reason` |

The duplicate check is "an item with the same `attachment_id` and `attachment_version` was
imported into the same gallery". It joins imports to items, indexed on
`(attachment_id, attachment_version)`.

**Descriptions** go in their own table, `gallery_image_descriptions`. They are stored as
**structured facts, not finished text**; the sentence is composed when a page is rendered
(section 7). Wording can then change, and new APs can appear in "směrem na…", without
regenerating anything.

| Column | Notes |
| --- | --- |
| `area_id`, `ap_id`, `visibility`, `filename` | unique together |
| `origin_lat`, `origin_lon` | where the photo was taken from, nullable |
| `origin_source` | `exif` (GPS in the photo) or `ap` (the AP's own coordinates) |
| `heading` | compass direction of the view in degrees 0–359, nullable |
| `heading_source` | `exif` (`GPSImgDirection`), `filename` (parsed), or `manual` (set by a manager) |
| `scene` | scene tag key (e.g. `zastavba`), nullable; plus `scene_score` |
| `edited_by` | user id when a manager set or corrected anything, nullable |
| timestamps | |

**Why not columns on `gallery_images`:** that table is a *derived* index. Reconciling may
delete a row and create it again (for example after a trash-and-restore), which would silently
lose a description, and a manually set direction is real work that must survive that.
Trashing a photo deletes its description: a new upload that reuses the name must not
inherit it. Restoring a photo from the trash by hand loses its description. This is documented.

## 7. Descriptions

### What the test showed (phase 0, done 2026-10-01)

20 real archive photos from 20 different pages (2011–2026), as 400 px thumbnails. Measured in
Node with Transformers.js 4.3 on a Ryzen 7 8845HS CPU; a typical laptop is slower.

| Approach | Speed per photo | Result |
| --- | --- | --- |
| Florence-2-base, detailed caption → opus-mt-en-cs | ~1.1 s + translation | Correct but generic lists ("budovy, stromy, obloha"); translation errors ("poles" → "póly", "aerial view" → "vzdušný výhled") |
| Florence-2-base, short caption → opus-mt-en-cs | ~1.0 s | "Pohled na město se spoustou domů" for 6 of 20 photos |
| Florence-2-large, detailed caption | ~3 s | No better for this content |
| SmolVLM-256M | ~0.8 s | Wrong ("lednička" for a network cabinet, "vlak" for a field), loops |
| SmolVLM-500M | ~1.3 s | Hallucinates ("train wreck"), rambles, loops |
| CLIP zero-shot scene tag, fixed Czech labels | ~0.1 s | Right scene type for ~13 of 20 (zástavba / sídliště / krajina / les / antény / rozvaděč); feature tags (věž, solární panely) mostly noise |

**EXIF in the archive:** none of the 20 originals has GPS. Only 4 have camera EXIF at all,
including the taken date; the other 16 were stripped before upload. So for the archive,
**dates come almost always from the attachment date** (section 5), and EXIF gives no direction.

**Direction in file names:** about 20 of the 10,539 attachments name a direction, for
example `Sever - směr Plačice.jpg`, `sektor_jih_2019_small.jpg`,
`Pohled směr severo východ`, and `128° jih Slatiny a směr AP Kladská.JPG`. They are rare but
precise, and they show what these photos are for: **line of sight to other APs**.

**Conclusion:** free-form captions from small models say almost nothing useful about views
from towers and roofs. What makes such a photo useful is **where it was taken from, which
direction it looks, and what lies in that direction**. Small models can't see that, but
metadata and geometry can.

### The description

Composed per photo from whatever facts are known, skipping missing parts:

> **Výhled z AP Piletice na JV (128°)** — směrem AP Kladská (3,2 km), AP Slatiny (5,1 km).
> Zástavba. · Listopad 2013

| Part | Source |
| --- | --- |
| "Výhled z AP Piletice" | the gallery's AP (Userdb) |
| "na JV (128°)" | `heading`, as an 8-point Czech compass name plus degrees |
| "směrem AP Kladská (3,2 km)…" | other APs within ±25° of `heading` and at most 15 km from the origin, nearest first, at most 3 (needs AP coordinates, see below) |
| "Zástavba." | `scene`, only when `scene_score` ≥ 0.6 |
| "Listopad 2013" | the timeline date |

When nothing beyond the AP and date is known, there is no description line at all: no filler.

### Where the facts come from

1. **Origin (where the photo was taken from)**
   - EXIF `GPSLatitude`/`GPSLongitude`, if present. Common in phone photos, absent in the
     archive.
   - Otherwise the AP's own coordinates. These galleries are views *from* that AP.
2. **Heading (which direction it looks)**
   - EXIF `GPSImgDirection`: phones that record GPS usually record the compass heading too.
   - Otherwise parsed from the file name, then the page title: an explicit azimuth (`128°`),
     or Czech direction words (`sever`, `jih`, `východ`, `západ`, `severovýchod`,
     `severo východ`, `SV`, `JZ`…), with and without diacritics.
   - Otherwise **set by a manager**: on each photo, a small compass control with 8 directions,
     or an exact number of degrees. This is the only way to give most archive photos a
     direction, and it's quick: a few clicks per page.
   - A position alone (EXIF GPS without heading) can't give a direction. That case shows
     only "Výhled z AP X".
3. **What lies in that direction**
   - computed on the server from the origin, the heading and the coordinates of all APs
   - plain geometry (bearing and haversine distance), no model
4. **Scene tag**
   - CLIP zero-shot with English prompts mapped to fixed Czech labels, so nothing is
     machine-translated
   - Labels: výhled na zástavbu, výhled na sídliště, výhled do krajiny, výhled na les, antény
     na stožáru, rozvaděč / technika, střecha
   - Optional per import.

### Where the computation runs

| Fact | Runs on | Cost |
| --- | --- | --- |
| EXIF origin and heading, file-name heading | server, PHP, during import or upload | negligible |
| APs in the view cone | server, PHP, at render time (cached) | negligible |
| Scene tag | the manager's **browser** (Transformers.js, CLIP ViT-B/32 quantized, ~90 MB once) | ~0.1 s per photo on CPU, so WebGPU isn't even needed; Firefox on Linux is fine |

Server-side tagging was considered: ONNX Runtime for PHP, plus pre-computed label embeddings,
would fit in a few hundred MB. It needs a new Composer package and image preprocessing in
PHP. The browser route is simpler, and at 0.1 s per photo the browser isn't a bottleneck.
Revisit if tagging should also happen for uploads made from phones.

### Display

- The composed description becomes the tile image's `alt` and `title` (today `alt` is the file
  name), and a one- or two-line text under the thumbnail, in the grid and on both timelines.
- Managers see a small compass button on each tile, to set or correct the direction.
- The scene tag is marked as automatic ("automaticky").

### Needed from Userdb: AP coordinates

"Směrem na AP …" needs each AP's position. The Userdb `areas` response we use today has only
id, name and active. **Open question:** does Userdb have AP coordinates, through this endpoint
or another one? If not, everything else still works; the description just has no "směrem…"
part.

## 8. URLs accepted

The server parses the pasted URL itself and only ever talks to the configured Confluence host.

| Form | Example | Page id |
| --- | --- | --- |
| New page URL | `/spaces/{key}/pages/{id}/{title}` | from the path |
| Classic | `/pages/viewpage.action?pageId={id}` | from the query |
| Display | `/display/{key}/{title}` | looked up via `GET /rest/api/content?spaceKey={key}&title={title}` |

Anything else, or another host, gives "Nepodporovaná adresa". Tiny links (`/x/…`) are left out
of v1.

## 9. Security

- **SSRF:**
  - The host comes from config (`CONFLUENCE_BASE_URL=https://doc.hkfree.org`), never from the
    pasted URL. The pasted URL is only parsed for a page id.
  - Download links from the API are relative and are resolved against the configured base.
  - Redirects are followed only within that host.
  - `ri:url` images are never fetched.
- **Credentials:** none are needed for the public space. An optional `CONFLUENCE_TOKEN` (a
  Confluence DC personal access token, sent as `Bearer`) would allow restricted spaces. Not
  sent at all when empty.
- **Authorization:** all import routes are behind `can:manage-gallery`. The import targets only
  the gallery it was started from.
- **Abuse:**
  - a `throttle` on the preview and import routes
  - at most one running import per gallery
  - the disk-space check from section 5
- **Untrusted content:**
  - Page titles are shown escaped.
  - Image files go through the same validation as uploads.
  - Scene tags and headings sent from the browser are untrusted: the tag must be one of
    the known keys, the heading an integer from 0 to 359, the score between 0 and 1.
    Descriptions are always escaped on output.

## 10. Testing

All HTTP is faked with `Http::fake`. The fixtures are trimmed copies of the real API responses
for page 22020482, plus hand-made storage bodies for the other page shapes.

- **URL parsing:**
  - all three forms
  - other hosts rejected
  - malformed ids rejected
- **Page analysis:**
  - `gallery` macro with and without include/exclude
  - `ac:image` attachments, order, duplicates
  - another page's attachments and `ri:url` skipped with reasons
  - a body with `&nbsp;` and other entities
  - an index page lists its children
- **Import job:**
  - photos stored, thumbnails generated, index rows created
  - a retry after a crash between storing and recording doesn't create a duplicate file
  - dated by EXIF, else by attachment date
  - non-images, oversized files and broken downloads recorded with reasons
  - chunked re-dispatch completes
  - progress counters
- **Deduplication:** re-importing imports nothing, and a new attachment version imports again.
- **Security:**
  - an off-host redirect is not followed
  - the token is sent only when configured
  - routes are forbidden for non-managers
- **Descriptions:**
  - heading from EXIF (`GPSImgDirection`, rational values), and the origin from EXIF GPS
  - file-name parsing: `128°`, `jih`, `Sever - směr Plačice`, `severo východ`, `SV`,
    without diacritics; no false hits such as `jihlava`
  - view cone: APs within ±25° and 15 km, nearest first, including across 0°/360°
  - composed text with every combination of missing facts; no line when only the AP and
    date are known
  - endpoints for scene tags and manual heading: managers only, values validated, only
    images of that gallery
  - trashing a photo deletes its description
- **Browser scene tagging:** not unit-testable. A Playwright smoke test during development, run
  from the scratchpad as for the timeline, not added to CI.

## 11. Risks and open points

1. **Most archive photos have no direction**, so their description is just the AP, a scene tag
   and the date, until a manager sets one. The compass control makes that quick, but it is
   manual work.
2. **Disk usage:** the full space is 10.5k photos. Importing all of it would be tens of GB.
   The free-space check stops imports early; whether the server can host the whole archive is
   a separate decision.
3. **Old originals without EXIF:** only 4 of 20 sampled originals had EXIF. For most of the
   archive, the date is the attachment date, which is the *upload to Confluence* date, not the
   capture date.
4. **Scene tags are wrong about a third of the time.** They are shown only above a confidence
   threshold and marked as automatic. Keep or drop them after seeing them on real imports.
5. **Date headings inside pages** ("2022 - prosinec") would date photos better than the
   attachment date on pages like `vyhledy_plotiste`. Out of scope, but noted as a follow-up.
6. **Queue worker via cron:** an import may start up to a minute late. That's acceptable for
   a background job and avoids new infrastructure.
7. **Confluence availability or rate limits:** downloads are sequential with timeouts and
   retries. No parallelism, to be polite to the server.
8. **Bulk import of the whole space** (all 657 pages, mapped to APs) is a natural next step, but
   **out of scope.** The mapping from parent pages to APs needs human review.

## 12. Phases

Each phase is its own commit (or PR) with tests and is shippable alone.

| Phase | Scope | Notes |
| --- | --- | --- |
| 0 | **Caption test** — done; results and decision in section 7. | |
| 1 | `ConfluenceClient` (config, URL parsing, page and attachment fetch, SSRF rules), page analysis, **preview** UI in the AP gallery. No writes. | |
| 2 | Import tables, `ImportConfluencePage` job (chunked), `GalleryStorage::storeImage` extraction, source date in the index, queue worker in the scheduler, progress UI, dedup, disk check. README: queue and env vars. | Usable without descriptions. |
| 3 | Descriptions: table, EXIF origin and heading, file-name heading parser, composed text in tiles and `alt`, manager compass control. Applies to uploads too, not only imports. | No model, no new dependency. "Směrem na AP…" only if Userdb has AP coordinates. |
| 3b | Scene tags in the browser (`resources/js/scene-tags.js`, CLIP), **Rozpoznat typ scény** on import and for existing photos. | Needs approval for the npm dependency `@huggingface/transformers`. |
| 4 | Czech Diátaxis docs in `docs/confluence-import/`, screenshots in Chromium and Firefox (WebKit if `libavif16` is installed). | |

## 13. Configuration added

| Variable | Default | Purpose |
| --- | --- | --- |
| `CONFLUENCE_BASE_URL` | `https://doc.hkfree.org` | the only host the import talks to |
| `CONFLUENCE_TOKEN` | empty | optional personal access token for restricted spaces |
| `GALLERY_SCENE_MODEL_URL` | empty | optional self-hosted location of the CLIP model files (phase 3b) |
