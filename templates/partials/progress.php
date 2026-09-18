<?php
/**
 * Shared progress card for scan, apply and undo.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<section id="besr-progress" class="besr-card besr-progress besr-stage" hidden>
    <h2 class="besr-step-title" id="besr-progress-title" tabindex="-1"><?php esc_html_e( 'Scanning…', 'best-search-replace' ); ?></h2>
    <div class="besr-bar-wrap" id="besr-bar-wrap" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="besr-bar" id="besr-bar" style="width:0%"></div></div>
    <p class="besr-phase"><span id="besr-percent">0%</span> <span id="besr-phase"></span></p>

    <div id="besr-error" class="notice notice-error inline" hidden><p id="besr-error-text"></p></div>

    <table class="widefat striped besr-progress-tables" id="besr-progress-tables-wrap">
        <thead><tr>
            <th scope="col"><?php esc_html_e( 'Table', 'best-search-replace' ); ?></th>
            <th scope="col" class="besr-num" data-col="scanned"><?php esc_html_e( 'Rows scanned', 'best-search-replace' ); ?></th>
            <th scope="col" class="besr-num" data-col="matches"><?php esc_html_e( 'Matches', 'best-search-replace' ); ?></th>
            <th scope="col" class="besr-num" data-col="updated"><?php esc_html_e( 'Rows updated', 'best-search-replace' ); ?></th>
            <th scope="col" class="besr-num" data-col="errors"><?php esc_html_e( 'Errors', 'best-search-replace' ); ?></th>
        </tr></thead>
        <tbody id="besr-progress-tables"></tbody>
    </table>

    <p class="besr-actions">
        <button type="button" class="button" id="besr-progress-stop"><?php esc_html_e( 'Stop', 'best-search-replace' ); ?></button>
        <button type="button" class="button button-primary" id="besr-progress-retry" hidden><?php esc_html_e( 'Retry', 'best-search-replace' ); ?></button>
        <button type="button" class="button" id="besr-progress-reload" hidden><?php esc_html_e( 'Reload', 'best-search-replace' ); ?></button>
        <button type="button" class="button" id="besr-progress-back" hidden><?php esc_html_e( 'Back to search', 'best-search-replace' ); ?></button>
    </p>

    <details class="besr-log-wrap" id="besr-log-wrap">
        <summary><?php esc_html_e( 'Show log', 'best-search-replace' ); ?> <span id="besr-log-count"></span></summary>
        <textarea id="besr-log" class="besr-log" readonly rows="10"></textarea>
    </details>
</section>
