<?php
/**
 * Tools → Best Search Replace
 *
 * @var string     $tab
 * @var array      $groups
 * @var array      $settings
 * @var bool       $show_global
 * @var BESR_Run[] $runs
 * @var int        $runs_total
 * @var int        $paged
 * @var int        $journal_total
 * @var array      $flag_labels
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$besr_tabs = array(
    'search'   => __( 'Search & replace', 'best-search-replace' ),
    'history'  => __( 'History', 'best-search-replace' ),
    'settings' => __( 'Settings', 'best-search-replace' ),
    'help'     => __( 'Help', 'best-search-replace' ),
);
?>
<div class="wrap besr-wrap" data-tab="<?php echo esc_attr( $tab ); ?>">
    <h1><?php esc_html_e( 'Best Search Replace', 'best-search-replace' ); ?></h1>
    <p class="besr-intro"><?php esc_html_e( 'Find text in your database, see exactly where it appears, then replace it — with undo.', 'best-search-replace' ); ?></p>

    <div id="besr-resume" class="notice notice-warning besr-resume" hidden>
        <p><strong><?php esc_html_e( 'An unfinished run was found.', 'best-search-replace' ); ?></strong> <span id="besr-resume-text"></span></p>
        <p>
            <button type="button" class="button button-primary" id="besr-resume-continue"><?php esc_html_e( 'Continue', 'best-search-replace' ); ?></button>
            <a class="button" id="besr-resume-view" href="#"><?php esc_html_e( 'View details', 'best-search-replace' ); ?></a>
        </p>
    </div>

    <nav class="nav-tab-wrapper besr-tabs" aria-label="<?php esc_attr_e( 'Best Search Replace sections', 'best-search-replace' ); ?>">
        <?php foreach ( $besr_tabs as $key => $label ) : ?>
            <a href="<?php echo esc_url( BESR_Admin::page_url( 'search' === $key ? array() : array( 'tab' => $key ) ) ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" <?php echo $tab === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
        <?php endforeach; ?>
    </nav>

    <div id="besr-status" class="screen-reader-text" aria-live="polite"></div>
    <div id="besr-notice" class="notice inline besr-notice" hidden><p></p></div>

    <?php require BESR_PLUGIN_DIR . 'templates/tab-' . $tab . '.php'; ?>
    <?php require BESR_PLUGIN_DIR . 'templates/partials/dialogs.php'; ?>
</div>
