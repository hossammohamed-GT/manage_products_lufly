# LUFLY catalog manager — work in progress

PHP / MySQL catalog manager intended for local, temporary use with XAMPP.
**This is a draft implementation, not a finished runnable application yet.**
The entry page is still empty; UI, request routing, setup screens and full runtime
verification remain to be implemented. Do not deploy or treat this branch as ready.

## Included so far

- Extracted catalog snapshot from all six `AB229200_part*.xlsx` workbooks.
- **715 independent source rows**, **1,465 attachment associations**, including
  **136 attachments in unlabeled column G**. Repeated codes remain separate records.
- 1,148 distinct downloadable assets, with each source attachment association retained.
- Provenance: workbook, source row and attachment column.
- PHP backend helpers for MySQL installation/seeding, CSRF, validation, prepared
  statements, product creation/editing, multi-image uploads, attachment metadata,
  conflict detection and derived code-prefix grouping.
- MySQL schema, XAMPP-oriented default config and Apache directory protections.

## Image handling

The importer reads every embedded drawing anchor, not only the Picture and Drawing
columns. D maps to product photos, E to drawings, and other columns to extras.
Associations use each anchor's top-left Excel row; no unmatched anchors remain in
this snapshot. This validates structural coverage, **not a manual visual audit of
every product/image association**.

Raster assets were converted to **WebP quality 94 without resizing** to keep the
repository manageable. This is lossy optimization, not byte-for-byte preservation
of original images; originals remain in the source Drive workbooks. Non-raster
assets such as EMF are retained in their original formats and will need download
links or an appropriate viewer rather than an HTML image preview.

`data/import-report.json` records per-file/column counts and conversion details.
Raw Excel workbooks are intentionally excluded from Git.

## Intended XAMPP setup (once the application is complete)

1. Use PHP 8.1+ with PDO MySQL, fileinfo and sessions enabled.
2. Place this repository in `xampp/htdocs/lufly` and start Apache and MySQL.
3. Defaults in `config.php` match a typical local XAMPP install (`root`, no password).
   Override locally via a git-ignored `config.local.php` returning an array.
4. `database/schema.sql` creates the MySQL schema; it does not contain the catalog
   rows. The backend `install()` helper seeds those from `data/catalog.json` in a
   transaction. A UI/CLI entry point for that helper is still pending.
5. PHP's upload limits may need adjusting in XAMPP's `php.ini` (Apache mod_php
   ignores `.user.ini`): `upload_max_filesize=12M`, `post_max_size=128M`,
   `max_file_uploads=30`. Restart Apache after changing `php.ini`.

There is currently **no authentication**. Local trusted use only; do not expose
this project to a public network. Runtime config, datasets and helper directories
are denied by the supplied Apache `.htaccess` rules (requires AllowOverride).
An optional SQLite backend exists for future local testing, not as a replacement
for the requested MySQL deployment.

## Rebuild catalog snapshot

With the original six workbooks in `google_drive/`:

```sh
python -m venv .venv
.venv/bin/pip install Pillow
.venv/bin/python tools/import_excel.py google_drive
```

On Windows use `.venv\\Scripts\\python.exe` instead. Rebuilding replaces only the
committed seed snapshot/assets, not an installed database. It requires the source
workbooks; they are not included in this repository.

## Current checks

```sh
python -m unittest discover -s tests -p 'test_*.py' -v
```

These check attachment file existence, source row identity, duplicate preservation,
all 1,465 anchor associations, and the 136 unlabeled-column attachments. PHP/MySQL
integration and browser tests have not yet been completed.

## Remaining work

- Complete RTL responsive browsing, prefix groups, search/filtering and product editor.
- Wire setup, CRUD, uploads, archive/restore, settings and review/report routes.
- Add gallery support for all attachments and fallback downloads for non-browser formats.
- Add the user's logo/brand colors when the logo is supplied.
- Run PHP lint, integration tests against MySQL/XAMPP, and browser checks.
