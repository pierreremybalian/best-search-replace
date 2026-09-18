<?php
/**
 * One generic dialog, filled by JS.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<dialog id="besr-dialog" class="besr-dialog" aria-labelledby="besr-dialog-title">
    <form method="dialog" class="besr-dialog-form">
        <h2 id="besr-dialog-title"></h2>
        <div id="besr-dialog-body" class="besr-dialog-body"></div>
        <p class="besr-dialog-ack" id="besr-dialog-ack-wrap" hidden>
            <label><input type="checkbox" id="besr-dialog-ack" /> <span id="besr-dialog-ack-text"></span></label>
        </p>
        <p class="besr-dialog-buttons">
            <button type="button" class="button" id="besr-dialog-cancel"><?php esc_html_e( 'Cancel', 'best-search-replace' ); ?></button>
            <button type="button" class="button button-primary" id="besr-dialog-ok"><?php esc_html_e( 'OK', 'best-search-replace' ); ?></button>
        </p>
    </form>
</dialog>
