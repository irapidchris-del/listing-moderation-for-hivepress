# Releasing

This plugin updates itself from its **GitHub releases** using WordPress's
native update API (the `Update URI` header + the `update_plugins_github.com`
filter) — no third-party library. Once a release is published, installs see an
update on their **Plugins** screen and can update in one click; no account or
licence key.

## One-time setup

- **The repository must be public.** WordPress sites fetch the release metadata
  and download the asset anonymously from the GitHub API; a private repo blocks
  both.
- The included workflow `.github/workflows/release.yml` needs no secrets beyond
  the automatic `GITHUB_TOKEN` (it has `permissions: contents: write`).

## How the asset is packaged

The release asset must be named exactly **`listing-moderation-for-hivepress.zip`**
(never put a version in the file name) and contain a single top-level
`listing-moderation-for-hivepress/` folder. Both the workflow and
`bin/build.php` produce exactly that. The updater installs the first release
asset whose name ends in `.zip`.

## Releasing from a Claude Code session

`gh` and the raw releases REST API are not available inside a session, so
publish through the workflow via the GitHub MCP tools:

1. **Bump the version** in `listing-moderation-for-hivepress.php` (`Version:`)
   and `readme.txt` (`Stable tag:`), and add a changelog entry. Commit.
2. **Merge to the default branch** (`main`) — the workflow must exist on `main`
   to be dispatchable, and it targets `$GITHUB_SHA`.
3. **Trigger the workflow** with the `actions_run_trigger` MCP tool:
   - method: `run_workflow`
   - workflow_id: `release.yml`
   - ref: `main`
   - inputs: `{ "tag": "vX.Y.Z", "notes": "<changelog markdown>" }`
4. **Verify** with the `get_release_by_tag` MCP tool that the tag, the notes and
   the `listing-moderation-for-hivepress.zip` asset all landed.

The workflow is idempotent: if the tag/release already exists it force-moves the
tag to the new commit, updates the notes (when provided) and re-uploads the
asset with `--clobber`; otherwise it creates the release.

## Releasing by hand (GitHub UI)

1. Bump the version and commit/merge as above.
2. `php bin/build.php` (or `composer build`) → `dist/listing-moderation-for-hivepress.zip`.
3. Create a GitHub release, tag `vX.Y.Z`, attach that zip (keep the exact name),
   publish. The `release: published` trigger re-uploads the built asset to be safe.

## The always-latest download link (for the forum)

Post this once; it permanently redirects to the newest release's asset:

```
https://github.com/irapidchris-del/listing-moderation-for-hivepress/releases/latest/download/listing-moderation-for-hivepress.zip
```

Because every release attaches an asset with that fixed name, the link always
downloads the latest version — you never need to edit the forum post.

## Version numbering

The installed `Version:` header is compared against the release tag (a leading
`v` is ignored). An update is only offered when the tag is **higher**, so always
bump the version before releasing.
