# Releasing

This plugin updates itself from its **GitHub releases** using the bundled
[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker).
Once a release is published, existing installs see an update on their
**Plugins** screen and can update in one click — no account or licence key.

## One-time setup

- **The repository must be public.** WordPress sites fetch the update metadata
  and download the release asset anonymously; a private repo blocks both.

## Every release

1. **Bump the version** in two places (they must match):
   - `listing-moderation-for-hivepress.php` → the `Version:` header.
   - `readme.txt` → the `Stable tag:` line (and add a `== Changelog ==` entry).

2. **Build the zip:**
   ```
   php bin/build.php      # or: composer build
   ```
   This writes to `dist/`:
   - `listing-moderation-for-hivepress.zip` — **attach this as the release asset.**
   - `listing-moderation-for-hivepress-<version>.zip` — a versioned copy for your
     own records only; do **not** attach it.

   Both zips contain a top-level folder named exactly
   `listing-moderation-for-hivepress/`, so the plugin always installs into the
   correct folder with no mismatch warning. Only user-facing files are shipped;
   dev tooling (CI, Composer, coding-standards config, this script, tests) is
   excluded.

3. **Create a GitHub release:**
   - Tag it to match the version — `1.4.0` or `v1.4.0` both work (a leading `v`
     is ignored when comparing).
   - Attach `dist/listing-moderation-for-hivepress.zip`. **Keep that exact
     filename** on every release — both the updater and the always-latest link
     below resolve that fixed name.
   - Publish it (not a draft, not a pre-release).

That's it. Within ~12 hours (or immediately if a site clicks **Check for
updates**), installs on an older version will be offered the new one.

## The always-latest download link (for the forum)

Post this link once; it permanently redirects to the newest release's asset:

```
https://github.com/irapidchris-del/listing-moderation-for-hivepress/releases/latest/download/listing-moderation-for-hivepress.zip
```

Because every release attaches an asset with that same fixed name, the link
always downloads the latest version — you never need to edit the forum post.

## Testing the updater

1. Install an **older** version on a test site (e.g. build and hand-install a
   `1.3.x` zip, or install `1.4.0` and then publish a `1.4.1` release).
2. Publish the newer release with its asset attached.
3. On the test site, go to **Dashboard → Updates** (or Plugins) and click
   **Check for updates** — the new version should appear and update cleanly.

## Version numbering

The installed `Version:` header is compared against the release tag. An update
is only offered when the release tag is **higher**, so always bump the version
before releasing.
