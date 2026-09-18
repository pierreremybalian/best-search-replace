<?php
/**
 * Settings, saved over AJAX.
 *
 * @var array $settings
 * @var array $flag_labels
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$besr_variant_labels = array(
    'scheme'          => __( 'http and https', 'best-search-replace' ),
    'www'             => __( 'with and without www', 'best-search-replace' ),
    'json'            => __( 'JSON-escaped (\/)', 'best-search-replace' ),
    /* translators: %s: the URL-encoded form of "://", shown as an example. */
    'urlencoded'      => sprintf( __( 'URL-encoded (%s)', 'best-search-replace' ), '%3A%2F%2F' ),
    'scheme_relative' => __( 'Scheme-relative (//)', 'best-search-replace' ),
    'bare'            => __( 'Bare domain (example.com) — also matches e-mails and sub-domains', 'best-search-replace' ),
);
?>
<form id="besr-settings-form" class="besr-card besr-settings">
    <h2><?php esc_html_e( 'Undo data', 'best-search-replace' ); ?></h2>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="besr-retention"><?php esc_html_e( 'Keep undo data for', 'best-search-replace' ); ?></label></th>
            <td><input type="number" min="0" max="3650" id="besr-retention" name="retention_days" class="small-text" value="<?php echo (int) $settings['retention_days']; ?>" /> <?php esc_html_e( 'days', 'best-search-replace' ); ?>
                <p class="description"><?php esc_html_e( '0 keeps undo data until you delete it. Undo data is stored in a table in your database and grows with the number of cells you replace.', 'best-search-replace' ); ?></p></td>
        </tr>
        <tr>
            <th scope="row"><label for="besr-keep-runs"><?php esc_html_e( 'Keep undo data for at most', 'best-search-replace' ); ?></label></th>
            <td><input type="number" min="1" max="500" id="besr-keep-runs" name="keep_runs" class="small-text" value="<?php echo (int) $settings['keep_runs']; ?>" /> <?php esc_html_e( 'runs', 'best-search-replace' ); ?></td>
        </tr>
    </table>

    <h2><?php esc_html_e( 'Default address variants', 'best-search-replace' ); ?></h2>
    <p class="description"><?php esc_html_e( 'Pre-checked when a website address is detected.', 'best-search-replace' ); ?></p>
    <fieldset>
        <?php foreach ( $besr_variant_labels as $key => $label ) : ?>
            <label class="besr-check"><input type="checkbox" name="default_variants[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $settings['default_variants'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
        <?php endforeach; ?>
    </fieldset>

    <h2><?php esc_html_e( 'Default skips on the review screen', 'best-search-replace' ); ?></h2>
    <fieldset>
        <?php foreach ( BESR_Context::TOGGLEABLE as $flag ) : ?>
            <label class="besr-check"><input type="checkbox" name="default_excluded[]" value="<?php echo esc_attr( $flag ); ?>" <?php checked( in_array( $flag, (array) $settings['default_excluded'], true ) ); ?> /> <?php echo esc_html( ucfirst( $flag_labels[ $flag ] ?? $flag ) ); ?></label>
        <?php endforeach; ?>
    </fieldset>

    <h2><?php esc_html_e( 'Default table selection', 'best-search-replace' ); ?></h2>
    <label class="besr-check"><input type="checkbox" name="include_logs" value="1" <?php checked( ! empty( $settings['include_logs'] ) ); ?> /> <?php esc_html_e( 'Include "Logs & caches" by default', 'best-search-replace' ); ?></label>

    <details class="besr-advanced">
        <summary><?php esc_html_e( 'Advanced', 'best-search-replace' ); ?></summary>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="besr-budget"><?php esc_html_e( 'Time budget per request', 'best-search-replace' ); ?></label></th>
                <td><input type="number" min="0" max="300" step="0.5" id="besr-budget" name="budget" class="small-text" value="<?php echo esc_attr( (string) $settings['budget'] ); ?>" /> <?php esc_html_e( 'seconds', 'best-search-replace' ); ?>
                    <p class="description"><?php esc_html_e( '0 = automatic (40% of the PHP time limit). Lower this if you see "server stopped responding".', 'best-search-replace' ); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><label for="besr-batch"><?php esc_html_e( 'Rows per batch', 'best-search-replace' ); ?></label></th>
                <td><input type="number" min="0" max="50000" id="besr-batch" name="batch_rows" class="small-text" value="<?php echo (int) $settings['batch_rows']; ?>" />
                    <p class="description"><?php esc_html_e( '0 = adaptive (recommended).', 'best-search-replace' ); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Uninstall', 'best-search-replace' ); ?></th>
                <td><label><input type="checkbox" name="uninstall_remove" value="1" <?php checked( ! empty( $settings['uninstall_remove'] ) ); ?> /> <?php esc_html_e( 'Remove all plugin data (runs, undo data, settings) when the plugin is deleted', 'best-search-replace' ); ?></label></td>
            </tr>
        </table>
    </details>

    <h2><?php esc_html_e( 'Who can use this plugin', 'best-search-replace' ); ?></h2>
    <?php /* translators: 1: capability name in code tags, 2: filter name in code tags. */ ?>
    <p class="description"><?php echo wp_kses_post( sprintf( __( 'Users with the %1$s capability (administrators). Developers can change this with the %2$s filter.', 'best-search-replace' ), '<code>' . esc_html( BESR_Settings::capability() ) . '</code>', '<code>besr_capability</code>' ) ); ?></p>

    <p class="submit">
        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'best-search-replace' ); ?></button>
        <span class="spinner" id="besr-settings-spinner"></span>
        <span class="besr-pill besr-pill-green" id="besr-settings-saved" hidden><?php esc_html_e( 'Saved', 'best-search-replace' ); ?></span>
    </p>
</form>
