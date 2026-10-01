# How-to guides

Task-focused recipes. Each assumes you already know your way around the gallery. If you don't,
start with the [tutorial](tutorial.md).

**For everyone**

- [Share a link to a particular month](#share-a-link-to-a-particular-month)
- [Show documentation photos in the network timeline](#show-documentation-photos-in-the-network-timeline)

**For gallery managers**

- [Upload photos so they land in the right month](#upload-photos-so-they-land-in-the-right-month)
- [Fix a photo that appears in the wrong month](#fix-a-photo-that-appears-in-the-wrong-month)

**For server administrators**

- [Build the timeline index on an existing installation](#build-the-timeline-index-on-an-existing-installation)
- [Keep the index in sync automatically](#keep-the-index-in-sync-automatically)
- [Make photos copied onto the server appear right away](#make-photos-copied-onto-the-server-appear-right-away)
- [Change the timezone used for months](#change-the-timezone-used-for-months)

---

## Share a link to a particular month

1. Open the timeline (an AP's **Časová osa**, or **Časová osa** in the header).
2. Click the month in the month index, or scroll to it.
3. Copy the address from the address bar. It ends with `?from=YYYY-MM`, for example
   `?from=2025-03`.

Whoever opens the link starts at that month and can scroll further back from there.

## Show documentation photos in the network timeline

Documentation ("Dokumentace") photos are only visible to signed-in users.

1. Sign in with **Přihlásit**.
2. Open **Časová osa** in the header.
3. Click **Zobrazit i Dokumentaci**. Documentation photos appear with a lock icon.

The choice is kept while you scroll and in links you copy (the address contains `priv=1`).
Click **Včetně Dokumentace** to go back to public photos only.

## Upload photos so they land in the right month

A photo is placed in the month it was **taken**, which the gallery reads from the photo's EXIF
data. To keep that information:

1. Upload the **original files** from the camera or phone: drag them onto the dropzone, or click
   it to choose them.
2. Avoid uploading copies saved from messaging apps (WhatsApp, Messenger, Signal), screenshots,
   or files exported by editors that strip metadata. They usually have no taken date.

If a photo has no taken date, the gallery uses the file's last-modified date as reported by
your browser, and failing that, the upload time. See
[how photos get their date](explanation.md#how-a-photo-gets-its-date).

## Fix a photo that appears in the wrong month

There is no way to edit a photo's date in the gallery. The date is read once, when the photo is
indexed. To correct it:

1. Find the original file with the correct EXIF date (from the camera or phone).
2. In the AP gallery, hover over the wrong photo and click the trash icon to move it to the
   trash.
3. Upload the original file.

If no original with a taken date exists, set the file's modification time on your computer to
the right date before uploading. Your browser reports it, and the gallery uses it as the
fallback date.

## Build the timeline index on an existing installation

When you deploy the timeline to a server that already has photos, index them once:

1. Run the migrations:

   ```bash
   php artisan migrate --force
   ```

2. Do a dry run and check the month distribution it prints:

   ```bash
   sudo -u www-data php artisan gallery:index --dry-run
   ```

   If most photos land in a single recent month, their files lost their original
   modification times when they were copied, for example with `cp` without `-p`. Photos without
   EXIF would all be dated to that copy. If you still have the source, re-copy it preserving
   times (`rsync -t` or `cp -p`) before continuing.

3. Build the index:

   ```bash
   sudo -u www-data php artisan gallery:index
   ```

To try it on part of the data first, add `--area=<id>` or `--ap=<id>`.

## Keep the index in sync automatically

Uploads and deletions update the index immediately. To also pick up changes made directly on
disk, run Laravel's scheduler, which runs `gallery:index` once a day:

1. Open the crontab of the web server user:

   ```bash
   sudo crontab -u www-data -e
   ```

2. Add:

   ```cron
   * * * * * cd /home/<user>/websites/hkfree-gallery && php artisan schedule:run >> /dev/null 2>&1
   ```

3. Check that the task is registered:

   ```bash
   sudo -u www-data php artisan schedule:list
   ```

Run the scheduler as `www-data` so the log and database files it creates stay writable for
Apache.

## Make photos copied onto the server appear right away

After copying photos into a gallery directory, or restoring a file from the trash by renaming
it:

- **For the AP's own timeline,** nothing is needed. Opening it indexes new files, up to 200 per
  page load; a yellow notice (**Probíhá indexace…**) tells you when more are waiting. Reload
  until it disappears.
- **For the network timeline,** run:

  ```bash
  sudo -u www-data php artisan gallery:index --ap=<ap id>
  ```

  Otherwise the photos appear after the next daily run.

## Change the timezone used for months

Photos are placed in months by Europe/Prague local time. To use another timezone:

1. Set it in `.env`:

   ```dotenv
   GALLERY_TIMEZONE=Europe/Bratislava
   ```

2. Refresh the configuration cache: `php artisan config:cache`.

This affects photos indexed from now on. Existing rows keep their dates. Before re-indexing
everything, read [why dates are stored as local time](explanation.md#why-dates-are-local-time).
