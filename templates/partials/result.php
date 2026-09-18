<?php
/**
 * Result card after apply, undo or cancel. Filled by admin.js.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<section id="besr-result" class="besr-card besr-result besr-stage" hidden>
    <h2 class="besr-step-title" id="besr-result-title" tabindex="-1"></h2>
    <ul class="besr-result-notes" id="besr-result-notes"></ul>
    <div id="besr-result-errors" class="notice notice-error inline" hidden>
        <p><strong id="besr-result-errors-title"></strong></p>
        <ul id="besr-result-errors-list"></ul>
    </div>
    <table class="widefat striped besr-result-tables" id="besr-result-tables-wrap" hidden>
        <thead><tr>
            <th scope="col"><?php esc_html_e( 'Table', 'best-search-replace' ); ?></th>
            <th scope="col" class="besr-num"><?php esc_html_e( 'Rows updated', 'best-search-replace' ); ?></th>
            <th scope="col" class="besr-num"><?php esc_html_e( 'Matches', 'best-search-replace' ); ?></th>
            <th scope="col" class="besr-num"><?php esc_html_e( 'Errors', 'best-search-replace' ); ?></th>
        </tr></thead>
        <tbody id="besr-result-tables"></tbody>
    </table>
    <div id="besr-result-next" class="besr-next" hidden>
        <h3><?php esc_html_e( 'Next steps', 'best-search-replace' ); ?></h3>
        <ul id="besr-result-next-list"></ul>
    </div>
    <p class="besr-actions" id="besr-result-actions"></p>
    <p class="besr-undo-until" id="besr-undo-until"></p>
    <details class="besr-log-wrap"><summary><?php esc_html_e( 'Show log', 'best-search-replace' ); ?></summary><textarea id="besr-result-log" class="besr-log" readonly rows="10"></textarea></details>
</section>
