# Best Search Replace

Search and replace across a WordPress database the way it should have worked all along: scan first, review every match with warnings, replace, and undo if needed.

**Author:** Pierre R. Balian · **License:** GPL v2 or later · **Requires:** WordPress 6.2+, PHP 7.4+

## Why another search/replace plugin

The plugin everybody uses puts the useful parts behind a paywall and gets several things wrong for free. Best Search Replace ships everything, and fixes the failure modes:

| | Better Search Replace (free) | Best Search Replace |
|---|---|---|
| Table picker | raw `<select multiple>` of table names | content groups ("Posts, pages & custom content", "Site settings", "WooCommerce", "Plugin data: Gravity Forms", "Logs & caches") with row counts, tables one click away |
| Preview | count per table, details are a Pro upsell | every match, with post title / option name / meta key, before → after snippet, per-row skip |
| Collisions | none | every occurrence classified: inside e-mail, longer domain, inside word, inside hash, data key, class name; skip whole categories with one toggle |
| URL spellings | one literal | http/https, ±www, JSON-escaped `https:\/\/`, URL-encoded `%3A%2F%2F`, scheme-relative `//` — each shown with its replacement |
| Serialized data | `unserialize()`/`serialize()`; protected props skipped silently | byte-exact walker, protected/private props handled, floats and references untouched, nested at any depth, malformed values never written |
| Tables without a primary key | silently skipped | listed as "not searchable" with the number of rows that contain the text; opt-in whole-row mode |
| Timeouts | fixed 20k-row pages, no resume | time-budgeted resumable steps (40% of `max_execution_time`, adaptive chunk sizes) |
| Undo | none | per-cell undo journal, conflict detection, SQL export |
| WP-CLI | none | full command set |
| Object cache | never flushed | flushed after every apply/undo |

## Using it

Tools → Best Search Replace.

1. **What** — type the search and replacement text. If it looks like a URL you get the spelling variants as toggles, each with the literal text it will match and become.
2. **Where** — tick content groups. Expand a group to see its tables, row counts and anything that cannot be searched.
3. **Find matches** — the scan runs in small steps and shows per-table counters. Nothing is changed.
4. **Review** — totals, the warnings panel (each category with count, explanation, two examples and a *Skip these* toggle), and the browsable match list. Untick any row you do not want.
5. **Replace** — a confirmation restates what will happen. An undo point is saved before each cell changes.
6. **Undo** — from the result card or History. Cells edited after the run are listed and left alone unless you ask to restore them too. *Download undo SQL* gives you the same as a file.

Site address settings (`siteurl`, `home`) are written last so the dashboard keeps working during the run; you can also choose to leave them unchanged. GUIDs, transients and sign-in columns (`user_email`, `user_login`, `comment_author_email`) are protected unless you opt in.

## WP-CLI

```
wp besr scan <search> <replace> [--tables=<recommended|all|core|csv>] [--groups=<csv>] [--variants=<csv>]
    [--exclude-contexts=<csv>] [--include-guid] [--include-transients] [--include-identity] [--include-pkless]
    [--include-global] [--case-insensitive] [--budget=<seconds>] [--format=table|json] [--porcelain]
wp besr apply <run> [--yes] [--stale=skip|replace] [--keep-siteurl] [--no-journal]
wp besr apply <search> <replace> [scan options] [--yes] [--dry-run]
wp besr undo <run> [--yes] [--force]
wp besr status [<run>]
wp besr runs [--status=<csv>] [--format=table|json]
wp besr matches <run> [--table=] [--flag=] [--status=] [--format=table|csv|json]
wp besr export <run> [--file=<path>]
wp besr delete <run>
wp besr cleanup [--older-than=<days>] [--all] [--yes]
```

`<run>` is a run id or the first characters of its uuid. `--dry-run` is the same as `scan`.

## Hooks

| Hook | Type | Signature |
|---|---|---|
| `besr_capability` | filter | `( string $cap = 'manage_options' )` |
| `besr_tables` | filter | `( string[] $tables )` — tables offered on this site |
| `besr_table_descriptor` | filter | `( array $desc )` — per-table descriptor before scanning (drop columns, etc.) |
| `besr_table_groups` | filter | `( array $definitions )` — content group definitions |
| `besr_known_prefixes` | filter | `( array $prefix_to_plugin_name )` |
| `besr_column_exclusions` | filter | `( array $column_to_reason, array $desc, array $config )` |
| `besr_preflight_warnings` | filter | `( array $warnings, array $config )` |
| `besr_journal_max_bytes` | filter | `( int $bytes = 2 GB )` — undo journal cap per run |
| `besr_after_apply` | action | `( BESR_Run $run )` |
| `besr_after_undo` | action | `( BESR_Run $run )` |

## How the engine avoids timeouts

Every request (browser AJAX tick or CLI loop iteration) gets a time budget of 40% of `max_execution_time`, clamped between 2 and 15 seconds (60 in CLI). Work is done in chunks whose size adapts to how long the previous chunk took. State — table cursors, counters, log — lives in the run's database row, so a run survives a lost connection, a closed tab or a PHP fatal (which is turned into a run error you can retry). Scanning uses keyset pagination on the primary key with a `LIKE` pre-filter; applying replays the match index, re-reading each row by primary key and checking the cell hash recorded at scan time before writing.

## Storage

Three tables: `{prefix}besr_runs`, `{prefix}besr_matches` (one row per matched cell), `{prefix}besr_journal` (pre-images, gzip-compressed above 4 KB). Journals are pruned after the retention period (Settings, 30 days by default) and at most *keep runs* applied runs keep a journal. Deactivation only clears the cron event; data is removed on uninstall only when the setting says so.

## Development

A throwaway WordPress lives in `dev/`. It needs Docker and nothing else: no local PHP, MySQL or WordPress. Use `docker compose` or the standalone `docker-compose`, whichever your setup has.

```
cd dev && ./bootstrap.sh              # http://besr.localhost:8081/wp-admin  admin / admin
docker-compose run --rm -T wpcli eval-file /tests/replacer-cases.php
docker-compose run --rm -T wpcli eval-file /tests/engine-smoke.php
./scenario-siteurl.sh                 # deferred siteurl/home path via the CLI
./bootstrap.sh --reset                # wipe and reseed
```

PHP runs with `max_execution_time = 5` on purpose. `dev/fixtures/seed.php` creates every edge case the plugin claims to handle (e-mails, sub-domains, JSON-escaped and URL-encoded URLs, serialized objects with protected properties and nested serialization, a 1.5 MB post, tables without a primary key, composite keys, BLOB columns, plugin-prefixed tables).

## Roadmap

* Network-wide runs across all sites of a multisite.
* Regular-expression search.
