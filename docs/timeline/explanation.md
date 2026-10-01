# Explanation: how the timeline works

## Why there is an index

The gallery stores photos as plain files in one directory per AP gallery, and the grid view
simply lists that directory. That is enough for one gallery sorted by name, but a timeline
needs more:

- **It sorts by date, not name.** Reading the date means opening each file's EXIF data.
  That's cheap for one photo but slow for thousands on every page view.
- **The network timeline merges every gallery into one sorted stream** and pages through it.
  Doing that from directories would mean scanning all of them on each request.
- **The month index needs a count of photos per month.**

So the timeline reads from a database table, `gallery_images`, that indexes the files. Each
photo's dates are read once, when it is indexed, and stored.

The files remain the source of truth. The index only records what exists and when it was
taken; it never decides whether a photo exists. The grid views still read the directories
directly. If the index were ever wrong, the grid would still show every photo, and
`gallery:index` rebuilds the index from the files.

## How the index stays correct

Most changes happen through the gallery itself, and those update the index immediately: an
upload adds a row, and moving a photo to the trash removes it.

Files can also change behind the gallery's back: copied in by an administrator, restored from
the trash by renaming, or deleted on the server. Three mechanisms catch those:

1. **Opening an AP's timeline** compares that gallery's directory with its rows, adds what's
   missing and drops what's gone. It adds at most 200 photos per page load, so the first visit
   to a large, never-indexed gallery stays fast.
2. **A daily `gallery:index` run** does the same for every gallery.
3. **Running `gallery:index` by hand** applies changes immediately.

The network timeline doesn't reconcile on page load: checking every directory on each request
is exactly the cost the index exists to avoid. Changes made directly on disk therefore reach
it after at most a day, unless an administrator runs the command.

When an upload finishes, the file is written first and the row second. If writing the row
fails, the photo is still safely stored and visible in the grid, and the next reconcile adds
it. The opposite order could leave rows pointing to files that don't exist.

## How a photo gets its date

The most useful date is when the photo was **taken**. Cameras and phones record it in the
photo's EXIF data as `DateTimeOriginal`. That value is often missing, though, because messaging
apps, screenshots and many editors strip metadata. So the gallery falls back step by step:

1. **EXIF taken date.** The gallery ignores the EXIF `DateTime` field, because despite its name
   it records when the file was last edited.
2. **The file's modification date as the uploader's browser reports it.** For files copied
   straight off a phone or camera this is often the capture time. For downloaded files it's the
   download time, which is still no worse than the next option.
3. **The upload time** (the stored file's modification time).

Dates that can't be real are skipped and the next source is tried:

- anything before 1990, which is typically a camera whose clock was never set and reports 1970
  or 2000
- anything in the future
- placeholder values such as `0000:00:00 00:00:00`

The date is read once, when the photo is indexed. That keeps pages fast, but it means a wrong
date isn't corrected by re-indexing: the fix is to re-upload a file that carries the right
date (see the [how-to guide](how-to.md#fix-a-photo-that-appears-in-the-wrong-month)).

### A risk when photos were copied onto the server

For photos that were already on the server before the timeline existed, only sources 1 and 3
are available. If those files were once copied without preserving their modification times,
every photo without EXIF carries the date of that copy and lands in the same month. The
`--dry-run` month distribution exists to spot this before building the index.

## Why dates are local time

EXIF dates have no timezone: a camera records the clock on its display, such as
`2024:05:17 10:20:30`. Timestamps such as file times are absolute moments, usually handled in
UTC. Mixing the two would misorder photos by an hour or two, and it would put a photo uploaded
just after midnight on the 1st of a month (Prague time) into the previous month.

So the gallery stores every timeline date as **wall-clock time in one timezone**,
`GALLERY_TIMEZONE` (Europe/Prague by default). EXIF dates are kept exactly as recorded, and
timestamps are converted into that timezone. Months are then simply the year and month of that
wall-clock time.

The application itself keeps running in UTC. Only the timeline dates use local time, so
nothing else in the database changes meaning.

## Privacy

Documentation photos are only for signed-in users. On the network timeline:

- Guests always get public photos only. The `priv=1` parameter is ignored unless you are signed
  in, and the query filters on it, so a guest never receives documentation photos, not even
  their file names.
- Signed-in users see documentation photos only when they ask for them. They are technical
  photos and would otherwise crowd out everything else.
- Even if a documentation photo's address leaked, opening it still requires signing in.

Photos of APs that no longer exist in Userdb are left out of the network timeline, because
their gallery pages would return "not found".

The timeline makes older photos easier to find. Public originals are served unmodified,
including any GPS position a phone stored in them. That was already true before the timeline,
but keep it in mind when uploading public photos.

## Scrolling and the address bar

The timeline loads 60 photos at a time. A busy month, such as a day of installation work, can
easily hold more than that, so pages are cut by photo count rather than by month. When the
next page continues a month that is already on screen, its photos are added to the existing
section, so every month appears as one block with one heading. The count in each heading is
the month's total, not the number loaded so far.

As you scroll, the address bar is updated with `?from=` and the month at the top of the
screen, without adding history entries. Reloading, or going back to the page later, therefore
returns to roughly the same point in time.

Without JavaScript, everything still works through plain links: **Starší** loads the next page,
the month index jumps, and **Novější** returns to the newest photos.
