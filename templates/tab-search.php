<?php
/**
 * Search & replace: wizard (steps 1–3) and all run stages.
 *
 * @var array $groups
 * @var array $settings
 * @var bool  $show_global
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$besr_default_variants = (array) $settings['default_variants'];
$besr_total_tables     = 0;
foreach ( $groups['groups'] as $g ) {
    $besr_total_tables += count( $g['tables'] );
    foreach ( $g['children'] as $c ) {
        $besr_total_tables += count( $c['tables'] );
    }
}
?>
<ol class="besr-steps" id="besr-steps">
    <li data-step="what" aria-current="step"><span class="besr-step-dot">1</span> <?php esc_html_e( 'What', 'best-search-replace' ); ?></li>
    <li data-step="where"><span class="besr-step-dot">2</span> <?php esc_html_e( 'Where', 'best-search-replace' ); ?></li>
    <li data-step="scan"><span class="besr-step-dot">3</span> <?php esc_html_e( 'Scan', 'best-search-replace' ); ?></li>
    <li data-step="review"><span class="besr-step-dot">4</span> <?php esc_html_e( 'Review', 'best-search-replace' ); ?></li>
    <li data-step="replace"><span class="besr-step-dot">5</span> <?php esc_html_e( 'Replace', 'best-search-replace' ); ?></li>
</ol>

<div id="besr-summary-bar" class="besr-summary-bar" hidden>
    <div class="besr-summary-terms">
        <code id="besr-bar-search"></code> <span class="besr-arrow" aria-hidden="true">→</span> <code id="besr-bar-replace"></code>
        <span class="besr-pill" id="besr-bar-variants" hidden></span>
        <span class="besr-pill besr-pill-muted" id="besr-bar-case" hidden>Aa</span>
        <span class="besr-summary-meta" id="besr-bar-meta"></span>
    </div>
    <div class="besr-summary-actions">
        <button type="button" class="button" id="besr-edit-search"><?php esc_html_e( 'New search', 'best-search-replace' ); ?></button>
    </div>
</div>

<div id="besr-wizard">
    <form id="besr-form" class="besr-card besr-step" data-step="what" autocomplete="off">
        <h2 class="besr-step-title" tabindex="-1"><span class="besr-step-num">1</span> <?php esc_html_e( 'What do you want to change?', 'best-search-replace' ); ?></h2>
        <table class="form-table besr-form-table" role="presentation">
            <tr>
                <th scope="row"><label for="besr-search"><?php esc_html_e( 'Search for', 'best-search-replace' ); ?></label></th>
                <td><input type="text" id="besr-search" name="search" class="large-text code" spellcheck="false" placeholder="https://old-site.com" /></td>
            </tr>
            <tr class="besr-swap-row">
                <th scope="row"></th>
                <td><button type="button" id="besr-swap" class="button button-small" aria-label="<?php esc_attr_e( 'Swap search and replace', 'best-search-replace' ); ?>">⇄ <?php esc_html_e( 'Swap', 'best-search-replace' ); ?></button></td>
            </tr>
            <tr>
                <th scope="row"><label for="besr-replace"><?php esc_html_e( 'Replace with', 'best-search-replace' ); ?></label></th>
                <td><input type="text" id="besr-replace" name="replace" class="large-text code" spellcheck="false" placeholder="https://new-site.com" /></td>
            </tr>
        </table>

        <div id="besr-analysis" class="besr-analysis" aria-live="polite" hidden>
            <p class="besr-analysis-head"><span class="besr-pill besr-pill-blue" id="besr-analysis-type"></span> <span id="besr-analysis-text"></span></p>
            <fieldset id="besr-variants" class="besr-variants" hidden>
                <legend class="screen-reader-text"><?php esc_html_e( 'Variants to include', 'best-search-replace' ); ?></legend>
            </fieldset>
            <ul id="besr-prewarnings" class="besr-prewarnings"></ul>
        </div>

        <p class="besr-case">
            <label><input type="checkbox" id="besr-case" name="case_insensitive" /> <?php esc_html_e( 'Ignore letter case', 'best-search-replace' ); ?></label>
            <span class="description"><?php esc_html_e( 'Also match Example.com or EXAMPLE.COM. Replacements always use your exact spelling.', 'best-search-replace' ); ?></span>
        </p>
    </form>

    <section id="besr-step-where" class="besr-card besr-step is-locked" data-step="where">
        <h2 class="besr-step-title" tabindex="-1"><span class="besr-step-num">2</span> <?php esc_html_e( 'Where should we look?', 'best-search-replace' ); ?>
            <span id="besr-where-count" class="besr-where-count"></span></h2>
        <div class="besr-where-toolbar">
            <span class="besr-select-links"><?php esc_html_e( 'Select:', 'best-search-replace' ); ?>
                <button type="button" class="button-link" data-select="all"><?php esc_html_e( 'All', 'best-search-replace' ); ?></button> ·
                <button type="button" class="button-link" data-select="recommended"><?php esc_html_e( 'Recommended', 'best-search-replace' ); ?></button> ·
                <button type="button" class="button-link" data-select="none"><?php esc_html_e( 'None', 'best-search-replace' ); ?></button>
            </span>
            <input type="search" id="besr-table-filter" class="regular-text" placeholder="<?php esc_attr_e( 'Filter tables…', 'best-search-replace' ); ?>" aria-label="<?php esc_attr_e( 'Filter tables by name', 'best-search-replace' ); ?>" />
        </div>
        <ul id="besr-groups" class="besr-groups" data-total="<?php echo (int) $besr_total_tables; ?>">
            <?php
            foreach ( $groups['groups'] as $g ) :
                $is_default = ! empty( $g['default'] ) && ( 'logs' !== $g['key'] || ! empty( $settings['include_logs'] ) );
                $gid        = 'besr-group-' . sanitize_html_class( $g['key'] );
                $tcount     = count( $g['tables'] );
                foreach ( $g['children'] as $c ) {
                    $tcount += count( $c['tables'] );
                }
                if ( 0 === $tcount ) {
                    continue;
                }
                ?>
                <li class="besr-group" data-group="<?php echo esc_attr( $g['key'] ); ?>" data-default="<?php echo $is_default ? '1' : '0'; ?>">
                    <div class="besr-group-row">
                        <input type="checkbox" id="<?php echo esc_attr( $gid ); ?>" class="besr-group-cb" <?php checked( $is_default ); ?> />
                        <label for="<?php echo esc_attr( $gid ); ?>" class="besr-group-name"><?php echo esc_html( $g['label'] ); ?></label>
                        <span class="besr-group-meta">
                        <?php
                            /* translators: 1: number of tables, 2: number of rows */
                            echo esc_html( sprintf( _n( '%1$s table · %2$s rows', '%1$s tables · %2$s rows', $tcount, 'best-search-replace' ), number_format_i18n( $tcount ), number_format_i18n( (int) $g['rows'] ) ) );
                        ?>
                        </span>
                        <button type="button" class="besr-group-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $gid ); ?>-tables" aria-label="<?php /* translators: %s: content group name. */ echo esc_attr( sprintf( __( 'Show tables in %s', 'best-search-replace' ), $g['label'] ) ); ?>">▸</button>
                    </div>
                    <?php
                    if ( ! empty( $g['hint'] ) ) :
						?>
                        <p class="besr-group-desc"><?php echo esc_html( $g['hint'] ); ?></p><?php endif; ?>
                    <ul id="<?php echo esc_attr( $gid ); ?>-tables" class="besr-tables" hidden>
                        <?php foreach ( $g['tables'] as $t ) : ?>
                            <li data-table="<?php echo esc_attr( $t['name'] ); ?>">
                                <label><input type="checkbox" name="tables[]" value="<?php echo esc_attr( $t['name'] ); ?>" data-rows="<?php echo (int) $t['rows']; ?>" <?php checked( $is_default ); ?> /> <code><?php echo esc_html( $t['name'] ); ?></code></label>
                                <span class="besr-table-meta"><?php echo esc_html( number_format_i18n( (int) $t['rows'] ) . ' ' . __( 'rows', 'best-search-replace' ) . ' · ' . size_format( (int) $t['size_bytes'] ) ); ?></span>
                            </li>
                        <?php endforeach; ?>
                        <?php foreach ( $g['children'] as $c ) : ?>
                            <li class="besr-subgroup" data-subgroup="<?php echo esc_attr( $c['key'] ); ?>">
                                <span class="besr-subgroup-name"><?php echo esc_html( $c['label'] ); ?></span>
                                <ul class="besr-tables">
                                    <?php foreach ( $c['tables'] as $t ) : ?>
                                        <li data-table="<?php echo esc_attr( $t['name'] ); ?>">
                                            <label><input type="checkbox" name="tables[]" value="<?php echo esc_attr( $t['name'] ); ?>" data-rows="<?php echo (int) $t['rows']; ?>" <?php checked( $is_default ); ?> /> <code><?php echo esc_html( $t['name'] ); ?></code></label>
                                            <span class="besr-table-meta"><?php echo esc_html( number_format_i18n( (int) $t['rows'] ) . ' ' . __( 'rows', 'best-search-replace' ) . ' · ' . size_format( (int) $t['size_bytes'] ) ); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="description besr-tables-note"><?php esc_html_e( 'Tables without a primary key and binary columns are reported as "not searchable" after the scan instead of being silently skipped.', 'best-search-replace' ); ?></p>

        <details class="besr-advanced" id="besr-advanced">
            <summary><?php esc_html_e( 'Advanced', 'best-search-replace' ); ?></summary>
            <p><label><input type="checkbox" id="besr-include-guid" /> <?php esc_html_e( 'Include GUID columns', 'best-search-replace' ); ?></label>
                <span class="description"><?php esc_html_e( 'GUIDs are permanent post IDs that look like links. Only change them on a brand-new site nobody has subscribed to.', 'best-search-replace' ); ?></span></p>
            <p><label><input type="checkbox" id="besr-include-transients" /> <?php esc_html_e( 'Include transients (temporary cache rows)', 'best-search-replace' ); ?></label></p>
            <p><label><input type="checkbox" id="besr-include-identity" /> <?php esc_html_e( 'Include protected sign-in columns (user e-mails, logins)', 'best-search-replace' ); ?></label>
                <span class="description"><?php esc_html_e( 'Only if you really mean to change how people log in.', 'best-search-replace' ); ?></span></p>
            <p><label><input type="checkbox" id="besr-include-pkless" /> <?php esc_html_e( 'Include tables without a primary key', 'best-search-replace' ); ?></label>
                <span class="description"><?php esc_html_e( 'Rows are identified by their full content. If identical duplicate rows exist, only one of them is changed.', 'best-search-replace' ); ?></span></p>
            <?php if ( $show_global ) : ?>
                <p><label><input type="checkbox" id="besr-include-global" /> <?php esc_html_e( 'Include network-wide tables (users, user meta, network settings)', 'best-search-replace' ); ?></label>
                    <span class="description"><?php esc_html_e( 'Changes here affect every site in the network.', 'best-search-replace' ); ?></span></p>
            <?php endif; ?>
        </details>
    </section>

    <section id="besr-step-scan" class="besr-card besr-step is-locked" data-step="scan">
        <h2 class="besr-step-title" tabindex="-1"><span class="besr-step-num">3</span> <?php esc_html_e( 'Find matches', 'best-search-replace' ); ?></h2>
        <p class="description"><?php esc_html_e( "Nothing is changed in this step. You'll see every match and decide what to replace.", 'best-search-replace' ); ?></p>
        <p class="submit">
            <button type="button" id="besr-scan" class="button button-primary button-hero" disabled><?php esc_html_e( 'Find matches', 'best-search-replace' ); ?></button>
            <span class="spinner" id="besr-spinner"></span>
            <span id="besr-scan-blocked" class="besr-note" hidden></span>
        </p>
    </section>
</div>

<?php require BESR_PLUGIN_DIR . 'templates/partials/progress.php'; ?>
<?php require BESR_PLUGIN_DIR . 'templates/partials/review.php'; ?>
<?php require BESR_PLUGIN_DIR . 'templates/partials/result.php'; ?>
