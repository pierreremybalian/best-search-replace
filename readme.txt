=== Best Search Replace ===
Contributors: pierreremybalian
Tags: search replace, migration, database, serialized, undo
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Search and replace across your database with a full preview of every match, collision warnings, friendly table groups, serialized-data safety and one-click undo.

== Description ==

Best Search Replace finds text anywhere in your WordPress database, shows you exactly where it appears, warns you about the places you probably do not want to touch, and only then replaces it. Every run can be undone.

**Nothing is ever changed without a review.** A run always starts with a scan. The review screen lists every match with human context (which page, which setting, which custom field), a before/after snippet, and a checkbox per row.

**Collision warnings.** Each occurrence is classified before you decide:

* inside an e-mail address (`admin@example.com` when replacing `example.com`)
* part of a longer domain (`shop.example.com`, `example.com.au`)
* inside a longer word
* inside a code or hash
* used as a key inside stored PHP data
* inside a PHP class or property name (never changed)

Skip or include a whole category with one toggle; the counts update instantly.

**Friendly table groups.** Choose "Posts, pages & custom content", "Site settings", "Users", "WooCommerce", "Plugin data: Gravity Forms" and so on, with row counts. Raw table names are one click away. Logs and caches are grouped separately and unchecked by default. Tables that cannot be searched safely (no primary key, binary only) are listed, not silently skipped.

**Address variants.** When you search for a URL the plugin also offers the http/https spelling, with and without `www.`, the JSON-escaped form (`https:\/\/`), the URL-encoded form (`https%3A%2F%2F`) and the scheme-relative form (`//`), each shown with the exact text it becomes.

**Serialized data done right.** Stored PHP data is rewritten by a byte-exact walker, never `unserialize()`d: string lengths are fixed up, protected and private properties are handled, floats and references stay untouched, and nested serialization works at any depth. Anything malformed is reported and never written.

**Undo.** Before a cell is changed its previous value is saved to an undo journal. Undo restores every cell that still contains what the run wrote and lists the ones edited since. You can also download the undo as a SQL file.

**Runs never time out.** Work happens in small, resumable steps sized to your server's PHP time limit, driven by your browser or by WP-CLI.

**WP-CLI.** `wp besr scan`, `wp besr apply`, `wp besr undo`, `wp besr runs`, `wp besr matches`, `wp besr export`, `wp besr cleanup`.

Also: site address settings (`siteurl`, `home`) are written last so the dashboard keeps working during the run; GUIDs, transients and sign-in columns are protected unless you opt in; the object cache is flushed after every run.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate it.
3. Go to Tools → Best Search Replace.

== Frequently Asked Questions ==

= Does it change anything during the scan? =

No. The scan only reads your tables and records what it found in the plugin's own tables. Nothing outside them is written until you click "Replace".

= Can I undo a replace? =

Yes. Every applied run keeps an undo journal for 30 days by default (configurable). Undo re-checks each cell and restores it if it still contains what the run wrote. Cells edited afterwards are listed and left alone unless you choose to restore them anyway.

= What about serialized data? =

Handled by a byte-exact walker, including protected and private properties and nested serialization. Malformed serialized values are reported and never written.

= Does it work on multisite? =

Each site's own tables can be searched from that site's Tools menu. Network-wide tables are offered to super administrators. Running one search across all sites at once is planned.

= Regular expressions? =

Not yet. Planned for a later version.

== Changelog ==

= 1.0.0 =
* First release.
