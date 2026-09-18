<?php
/**
 * Review stage: summary, warnings, match browser, action bar. Filled by review.js.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<section id="besr-review" class="besr-card besr-stage" hidden>
    <h2 class="besr-step-title" tabindex="-1"><span class="besr-step-num">4</span> <?php esc_html_e( 'Review what was found', 'best-search-replace' ); ?></h2>
    <p id="besr-review-stale" class="notice notice-info inline" hidden></p>

    <div id="besr-review-summary" class="besr-summary-strip">
        <div class="besr-stat"><strong id="besr-sum-matches">0</strong><span><?php esc_html_e( 'matches', 'best-search-replace' ); ?></span></div>
        <div class="besr-stat"><strong id="besr-sum-cells">0</strong><span><?php esc_html_e( 'cells', 'best-search-replace' ); ?></span></div>
        <div class="besr-stat"><strong id="besr-sum-tables">0</strong><span><?php esc_html_e( 'tables', 'best-search-replace' ); ?></span></div>
        <p class="besr-effective"><?php esc_html_e( 'Will replace', 'best-search-replace' ); ?> <strong id="besr-sum-effective">0</strong> · <?php esc_html_e( 'Skipped', 'best-search-replace' ); ?> <span id="besr-sum-skipped">0</span></p>
    </div>

    <div id="besr-group-chips" class="besr-chips" role="group" aria-label="<?php esc_attr_e( 'Filter by content group', 'best-search-replace' ); ?>"></div>

    <p id="besr-review-empty" class="besr-empty" hidden><?php esc_html_e( 'No matches. Nothing to replace. Try "Ignore letter case", more variants, or more tables.', 'best-search-replace' ); ?></p>

    <details id="besr-warnings" class="besr-warnings" open>
        <summary><?php esc_html_e( 'Things to check before replacing', 'best-search-replace' ); ?> <span class="besr-badge" id="besr-warnings-count"></span></summary>
        <ul id="besr-warnings-list"></ul>
    </details>

    <div class="besr-matches-toolbar">
        <h3 class="besr-matches-title"><?php esc_html_e( 'All matches', 'best-search-replace' ); ?></h3>
        <input type="search" id="besr-match-filter" class="regular-text" placeholder="<?php esc_attr_e( 'Filter matches…', 'best-search-replace' ); ?>" aria-label="<?php esc_attr_e( 'Filter matches by text, title or key', 'best-search-replace' ); ?>" />
        <fieldset class="besr-view">
            <legend class="screen-reader-text"><?php esc_html_e( 'View', 'best-search-replace' ); ?></legend>
            <label><input type="radio" name="besr-view" value="all" checked /> <?php esc_html_e( 'All', 'best-search-replace' ); ?></label>
            <label><input type="radio" name="besr-view" value="flagged" /> <?php esc_html_e( 'Flagged', 'best-search-replace' ); ?></label>
            <label><input type="radio" name="besr-view" value="skipped" /> <?php esc_html_e( 'Skipped', 'best-search-replace' ); ?></label>
            <label><input type="radio" name="besr-view" value="errors" /> <?php esc_html_e( 'Errors', 'best-search-replace' ); ?></label>
        </fieldset>
        <a class="button" id="besr-export-csv" href="#"><?php esc_html_e( 'Export CSV', 'best-search-replace' ); ?></a>
    </div>

    <div id="besr-matches" class="besr-matches"></div>
</section>

<div id="besr-review-actions" class="besr-actionbar" hidden>
    <p class="besr-actionbar-note"><?php esc_html_e( 'An undo point is saved before anything changes.', 'best-search-replace' ); ?></p>
    <button type="button" class="button-link-delete" id="besr-discard"><?php esc_html_e( 'Discard scan', 'best-search-replace' ); ?></button>
    <button type="button" class="button" id="besr-back"><?php esc_html_e( 'Back to search', 'best-search-replace' ); ?></button>
    <button type="button" class="button button-primary button-hero" id="besr-apply"><?php esc_html_e( 'Replace', 'best-search-replace' ); ?> <span id="besr-apply-count">0</span> <?php esc_html_e( 'matches', 'best-search-replace' ); ?></button>
</div>
