<?php
/**
 * Help.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="besr-card besr-help">
    <h2><?php esc_html_e( 'How it works', 'best-search-replace' ); ?></h2>
    <ol>
        <li><?php esc_html_e( 'What: type the text to find and what to replace it with. For a website address we also look for http/https, www, JSON-escaped and URL-encoded spellings.', 'best-search-replace' ); ?></li>
        <li><?php esc_html_e( 'Where: pick content groups (posts, settings, users, plugin data…) instead of raw table names. Expand a group to fine-tune individual tables.', 'best-search-replace' ); ?></li>
        <li><?php esc_html_e( 'Scan: nothing is changed. Every match is recorded with its location.', 'best-search-replace' ); ?></li>
        <li><?php esc_html_e( 'Review: see every match with context, and skip whole categories such as e-mail addresses or sub-domains with one switch.', 'best-search-replace' ); ?></li>
        <li><?php esc_html_e( 'Replace: each cell is re-checked before it is written and its previous value is saved so the run can be undone.', 'best-search-replace' ); ?></li>
    </ol>

    <h2><?php esc_html_e( 'Serialized data and JSON', 'best-search-replace' ); ?></h2>
    <p><?php esc_html_e( 'Serialized PHP data (theme settings, widgets, plugin options) is walked byte by byte and string lengths are recalculated, so it never breaks. Objects are never instantiated. JSON stored as text is handled through the JSON-escaped variant.', 'best-search-replace' ); ?></p>

    <h2><?php esc_html_e( 'What is never changed', 'best-search-replace' ); ?></h2>
    <ul>
        <li><?php esc_html_e( 'PHP class and property names inside serialized data.', 'best-search-replace' ); ?></li>
        <li><?php esc_html_e( 'GUIDs, transients, user e-mails and logins — unless you opt in under Advanced.', 'best-search-replace' ); ?></li>
        <li><?php esc_html_e( 'Tables without a primary key and binary columns — reported as "not searchable".', 'best-search-replace' ); ?></li>
        <li><?php esc_html_e( 'Cells whose content changed between the scan and the replace.', 'best-search-replace' ); ?></li>
    </ul>

    <h2><?php esc_html_e( 'Changing the site address', 'best-search-replace' ); ?></h2>
    <p><?php esc_html_e( 'The "siteurl" and "home" settings are written last so the dashboard keeps working during the run. When they change, open the dashboard at the new address. If WP_HOME or WP_SITEURL is defined in wp-config.php, update it too.', 'best-search-replace' ); ?></p>

    <h2><?php esc_html_e( 'WP-CLI', 'best-search-replace' ); ?></h2>
    <pre><code>wp besr scan "https://old.com" "https://new.com" --tables=recommended
wp besr apply &lt;run&gt; --yes
wp besr undo &lt;run&gt;
wp besr runs</code></pre>

    <h2><?php esc_html_e( 'Multisite', 'best-search-replace' ); ?></h2>
    <p><?php esc_html_e( 'This version works on one site at a time: open the tool inside the site you want to change. Network-wide tables (users, network settings) are available to super admins under Advanced. Running across all sites of a network from one screen is planned.', 'best-search-replace' ); ?></p>
</div>
