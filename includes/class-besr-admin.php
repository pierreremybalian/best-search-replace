<?php
/**
 * Tools → Best Search Replace: menu, assets, page rendering, downloads.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The admin screen: menu, assets and the data handed to the browser.
 */
class BESR_Admin {

    const SLUG = 'best-search-replace';
    const TABS = array( 'search', 'history', 'settings', 'help' );

    /**
     * @var string
     */
    private string $hook = '';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        add_action( 'admin_post_besr_export_undo', array( $this, 'export_undo' ) );
        add_action( 'admin_post_besr_export_matches', array( $this, 'export_matches' ) );
    }

    public function menu(): void {
        $this->hook = (string) add_submenu_page(
            'tools.php',
            __( 'Best Search Replace', 'best-search-replace' ),
            __( 'Best Search Replace', 'best-search-replace' ),
            BESR_Settings::capability(),
            self::SLUG,
            array( $this, 'render_page' )
        );
    }

    public static function page_url( array $args = array() ): string {
        return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
    }

    private function current_tab(): string {
        if ( isset( $_GET['run'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state; download handlers verify the nonce and capability in download_run().
            return 'search';
        }
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'search'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state; download handlers verify the nonce and capability in download_run().
        return in_array( $tab, self::TABS, true ) ? $tab : 'search';
    }

    private function requested_run(): ?BESR_Run {
        if ( empty( $_GET['run'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state; download handlers verify the nonce and capability in download_run().
            return null;
        }
        return BESR_Run::load( sanitize_text_field( wp_unslash( $_GET['run'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state; download handlers verify the nonce and capability in download_run().
    }

    public function enqueue( string $hook ): void {
        if ( $hook !== $this->hook ) {
            return;
        }
        BESR_Schema::ensure();

        wp_enqueue_style( 'besr-admin', BESR_PLUGIN_URL . 'assets/css/admin.css', array(), BESR_VERSION );
        wp_enqueue_script( 'besr-admin', BESR_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), BESR_VERSION, true );
        wp_enqueue_script( 'besr-review', BESR_PLUGIN_URL . 'assets/js/review.js', array( 'besr-admin' ), BESR_VERSION, true );

        $requested   = $this->requested_run();
        $unfinished  = BESR_Run::unfinished();
        $run_payload = null;
        if ( $requested ) {
            $run_payload = ( new BESR_Engine( $requested ) )->response();
        }
        $banner = null;
        if ( $unfinished && ( ! $requested || $unfinished->id() !== $requested->id() ) ) {
            $banner = ( new BESR_Engine( $unfinished ) )->response();
        }

        $show_global = is_multisite() && is_super_admin();
        $settings    = BESR_Settings::all();

        wp_localize_script( 'besr-admin', 'besrData', array(
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'adminPost'    => admin_url( 'admin-post.php' ),
            'pageUrl'      => self::page_url(),
            'nonce'        => wp_create_nonce( BESR_Engine::NONCE ),
            'tab'          => $this->current_tab(),
            'run'          => $run_payload,
            'runRequested' => (bool) $requested,
            'runDownloads' => $requested ? array(
                'export_undo' => self::download_url( 'besr_export_undo', $requested ),
                'export_csv'  => self::download_url( 'besr_export_matches', $requested ),
            ) : null,
            'activeRun'    => $banner,
            'groups'       => BESR_Groups::build( $show_global ),
            'showGlobal'   => $show_global,
            'multisite'    => is_multisite(),
            'settings'     => array(
                'variants'      => $settings['default_variants'],
                'excluded'      => $settings['default_excluded'],
                'includeLogs'   => (bool) $settings['include_logs'],
                'retentionDays' => (int) $settings['retention_days'],
            ),
            'links'        => array(
                'permalinks' => admin_url( 'options-permalink.php' ),
                'home'       => home_url( '/' ),
                'history'    => self::page_url( array( 'tab' => 'history' ) ),
                'settings'   => self::page_url( array( 'tab' => 'settings' ) ),
            ),
            'perPage'      => 50,
            'flags'        => array(
                'toggleable' => BESR_Context::TOGGLEABLE,
                'hard'       => BESR_Context::HARD,
            ),
            'i18n'         => $this->strings(),
        ) );
    }

    private function strings(): array {
        return array(
            'checking'         => __( 'Checking…', 'best-search-replace' ),
            'plainText'        => __( 'Plain text', 'best-search-replace' ),
            'variantsIntro'    => __( 'We will also look for the common ways WordPress stores it:', 'best-search-replace' ),
            /* translators: 1: selected table count, 2: total table count, 3: approximate row count */
            'selectedTables'   => __( '%1$s of %2$s tables · ~%3$s rows', 'best-search-replace' ),
            'notSearchable'    => __( 'not searchable', 'best-search-replace' ),
            'scanning'         => __( 'Scanning…', 'best-search-replace' ),
            'replacing'        => __( 'Replacing…', 'best-search-replace' ),
            'undoing'          => __( 'Undoing…', 'best-search-replace' ),
            'stopped'          => __( 'Stopped', 'best-search-replace' ),
            'failed'           => __( 'Something went wrong', 'best-search-replace' ),
            'stopScan'         => __( 'Stop scan', 'best-search-replace' ),
            'stopReplace'      => __( 'Stop replacing', 'best-search-replace' ),
            'stopConfirmScan'  => __( 'Stop the scan? Nothing has been changed; you can start a new scan any time.', 'best-search-replace' ),
            'stopConfirmApply' => __( 'Stop now? Tables already processed stay changed. You can undo them afterwards.', 'best-search-replace' ),
            'busy'             => __( 'Another request is working on this run…', 'best-search-replace' ),
            'networkError'     => __( 'The server did not respond. Retrying…', 'best-search-replace' ),
            'gaveUp'           => __( 'The server stopped responding. Your progress is saved; click Retry to continue.', 'best-search-replace' ),
            'sessionExpired'   => __( 'Your session expired. Reload the page to continue; the run is saved in History.', 'best-search-replace' ),
            'leaving'          => __( 'A run is in progress. Leaving this page pauses it; you can resume from History.', 'best-search-replace' ),
            'rows'             => __( 'rows', 'best-search-replace' ),
            'matches'          => __( 'matches', 'best-search-replace' ),
            'cells'            => __( 'cells', 'best-search-replace' ),
            'tables'           => __( 'tables', 'best-search-replace' ),
            'tablesLabel'      => __( 'Tables', 'best-search-replace' ),
            'of'               => __( 'of', 'best-search-replace' ),
            'willReplace'      => __( 'Will replace', 'best-search-replace' ),
            'skipped'          => __( 'Skipped', 'best-search-replace' ),
            /* translators: %s: number of matches in this category */
            'skipThese'        => __( 'Skip these (%s)', 'best-search-replace' ),
            'alwaysSkipped'    => __( 'Always skipped', 'best-search-replace' ),
            /* translators: %s: number of matches */
            'showAll'          => __( 'Show all %s', 'best-search-replace' ),
            /* translators: %s: number of matches that will be replaced */
            'replaceN'         => __( 'Replace %s matches', 'best-search-replace' ),
            'replaceNow'       => __( 'Replace now', 'best-search-replace' ),
            'noMatches'        => __( 'No matches. Nothing to replace. Try "Ignore letter case", more variants, or more tables.', 'best-search-replace' ),
            /* translators: %s: number of times the text occurs in this cell */
            'occ'              => __( '×%s in this cell', 'best-search-replace' ),
            'details'          => __( 'Details', 'best-search-replace' ),
            'hideDetails'      => __( 'Hide details', 'best-search-replace' ),
            'cellChanged'      => __( 'This cell changed since the scan. It will be re-checked when replacing.', 'best-search-replace' ),
            /* translators: 1: current page number, 2: total number of pages */
            'page'             => __( 'Page %1$s of %2$s', 'best-search-replace' ),
            'prev'             => __( 'Previous', 'best-search-replace' ),
            'next'             => __( 'Next', 'best-search-replace' ),
            /* translators: %s: number of tables */
            'noMatchTables'    => __( 'Tables with no matches (%s)', 'best-search-replace' ),
            /* translators: 1: number of matches, 2: number of cells */
            'matchesIn'        => __( '%1$s matches in %2$s cells', 'best-search-replace' ),
            'where'            => __( 'Where', 'best-search-replace' ),
            'column'           => __( 'Column', 'best-search-replace' ),
            'change'           => __( 'Change', 'best-search-replace' ),
            'replace'          => __( 'Replace', 'best-search-replace' ),
            'skipReason'       => __( 'skipped', 'best-search-replace' ),
            'forced'           => __( 'included by you', 'best-search-replace' ),
            'error'            => __( 'error', 'best-search-replace' ),
            /* translators: %s: value */
            'confirmTitle'     => __( 'Replace %s matches?', 'best-search-replace' ),
            'searchFor'        => __( 'Search for', 'best-search-replace' ),
            'replaceWith'      => __( 'Replace with', 'best-search-replace' ),
            /* translators: %s: number of additional search variants. */
            'variantsN'        => __( '+%s variants', 'best-search-replace' ),
            /* translators: 1: value 1, 2: value 2 */
            'tablesN'          => __( '%1$s tables in %2$s content groups', 'best-search-replace' ),
            'skipping'         => __( 'Skipping', 'best-search-replace' ),
            /* translators: %s: value */
            'individual'       => __( '%s individual matches', 'best-search-replace' ),
            'notSkipped'       => __( 'Not skipped, please confirm:', 'best-search-replace' ),
            'ack'              => __( "I've looked at these and want them replaced", 'best-search-replace' ),
            'siteurlNote'      => __( 'Your site address (siteurl/home) is changed last. You may need to sign in again at the new address.', 'best-search-replace' ),
            'keepSiteurl'      => __( 'Leave the site address settings unchanged', 'best-search-replace' ),
            /* translators: %s: value */
            'undoNote'         => __( 'An undo point is kept for %s days.', 'best-search-replace' ),
            'undoNoteForever'  => __( 'An undo point is kept until you delete it.', 'best-search-replace' ),
            /* translators: %s: value */
            'journalEstimate'  => __( 'Undo data will need roughly %s.', 'best-search-replace' ),
            'backupReminder'   => __( 'Back up your database first if you have not recently.', 'best-search-replace' ),
            'cancel'           => __( 'Cancel', 'best-search-replace' ),
            'ok'               => __( 'OK', 'best-search-replace' ),
            'discardTitle'     => __( 'Discard this scan?', 'best-search-replace' ),
            'discardBody'      => __( 'The scan results are deleted. Nothing in your content was changed.', 'best-search-replace' ),
            'discard'          => __( 'Discard', 'best-search-replace' ),
            'deleteTitle'      => __( 'Delete this run?', 'best-search-replace' ),
            'deleteBody'       => __( 'The run, its results and its undo data are removed. Your content is not touched.', 'best-search-replace' ),
            'delete'           => __( 'Delete', 'best-search-replace' ),
            'undoTitle'        => __( 'Undo this run?', 'best-search-replace' ),
            /* translators: 1: value 1, 2: value 2, 3: value 3 */
            'undoBody'         => __( '%1$s cells in %2$s tables will be restored to what they contained before %3$s.', 'best-search-replace' ),
            /* translators: %s: value */
            'undoChanged'      => __( '%s of these cells were edited after this run.', 'best-search-replace' ),
            /* translators: %s: value */
            'undoChangedPartial' => __( '(checked the most recent %s)', 'best-search-replace' ),
            'undoLeave'        => __( 'Leave those cells as they are now', 'best-search-replace' ),
            'undoForce'        => __( 'Restore them too', 'best-search-replace' ),
            /* translators: %s: value */
            'undoOverlap'      => __( '%s later run(s) changed some of the same cells. Undo those first, or expect conflicts.', 'best-search-replace' ),
            'undoSiteurl'      => __( 'Site address settings are restored last.', 'best-search-replace' ),
            'undoRun'          => __( 'Undo run', 'best-search-replace' ),
            /* translators: 1: number of matches, 2: number of rows, 3: number of tables. */
            'done'             => __( 'Done. Replaced %1$s matches in %2$s rows across %3$s tables.', 'best-search-replace' ),
            /* translators: 1: number of matches, 2: number of rows, 3: number of tables. */
            'donePartial'      => __( 'Stopped early. Replaced %1$s matches in %2$s rows across %3$s tables before stopping.', 'best-search-replace' ),
            /* translators: 1: number of cells restored, 2: number of cells left unchanged. */
            'undone'           => __( 'Undone. %1$s cells restored, %2$s left unchanged.', 'best-search-replace' ),
            'cancelled'        => __( 'Scan stopped. Nothing was changed.', 'best-search-replace' ),
            /* translators: %s: value */
            'staleSkipped'     => __( '%s cells were skipped because they changed since the scan.', 'best-search-replace' ),
            /* translators: %s: value */
            'rowsSkipped'      => __( '%s cells were skipped (row deleted, too large, or site address kept).', 'best-search-replace' ),
            /* translators: %s: value */
            'errorsN'          => __( '%s errors.', 'best-search-replace' ),
            'noErrors'         => __( '0 errors.', 'best-search-replace' ),
            /* translators: %s: value */
            'conflictsN'       => __( '%s cells were left unchanged because they were edited after the run.', 'best-search-replace' ),
            /* translators: %s: value */
            'undoUntil'        => __( 'Undo is available until %s.', 'best-search-replace' ),
            /* translators: %s: value */
            'siteurlChanged'   => __( 'Your site address is now %s.', 'best-search-replace' ),
            'openNewDashboard' => __( 'Open the new dashboard', 'best-search-replace' ),
            'siteurlLost'      => __( 'The site address was changed, so this page can no longer reach the server at the old address. Open the dashboard at the new address to see the result.', 'best-search-replace' ),
            'nextSteps'        => __( 'Next steps', 'best-search-replace' ),
            'cacheFlushed'     => __( 'Object cache flushed automatically.', 'best-search-replace' ),
            'pageCache'        => __( 'Using a page-cache plugin or a CDN? Clear it now.', 'best-search-replace' ),
            'savePermalinks'   => __( 'Save permalinks', 'best-search-replace' ),
            'permalinksHint'   => __( 'refreshes rewrite rules', 'best-search-replace' ),
            'openHomepage'     => __( 'Open your homepage', 'best-search-replace' ),
            'undoThisRun'      => __( 'Undo this run', 'best-search-replace' ),
            'downloadUndo'     => __( 'Download undo SQL', 'best-search-replace' ),
            'viewHistory'      => __( 'View in History', 'best-search-replace' ),
            'newSearch'        => __( 'New search', 'best-search-replace' ),
            'reapply'          => __( 'Scan again with the same terms', 'best-search-replace' ),
            'table'            => __( 'Table', 'best-search-replace' ),
            'rowsScanned'      => __( 'Rows scanned', 'best-search-replace' ),
            'rowsUpdated'      => __( 'Rows updated', 'best-search-replace' ),
            'errors'           => __( 'Errors', 'best-search-replace' ),
            'restored'         => __( 'Restored', 'best-search-replace' ),
            'saved'            => __( 'Saved', 'best-search-replace' ),
            'saveFailed'       => __( 'Could not save the settings.', 'best-search-replace' ),
            'deleted'          => __( 'Deleted.', 'best-search-replace' ),
            'requestFailed'    => __( 'Request failed.', 'best-search-replace' ),
            'unexpected'       => __( 'Unexpected response.', 'best-search-replace' ),
            'retry'            => __( 'Retry', 'best-search-replace' ),
            'reload'           => __( 'Reload', 'best-search-replace' ),
            /* translators: 1: value 1, 2: value 2 */
            'scanFinished'     => __( 'Scan finished. %1$s matches found in %2$s tables.', 'best-search-replace' ),
            'scanStarted'      => __( 'Scan started.', 'best-search-replace' ),
            'replaceStarted'   => __( 'Replacing started.', 'best-search-replace' ),
            'exportCsv'        => __( 'Export CSV', 'best-search-replace' ),
            'guidTitle'        => __( 'GUID column not searched', 'best-search-replace' ),
            /* translators: 1: value 1, 2: value 2, 3: value 3, 4: value 4 */
            'excludedCols'     => __( '%1$s.%2$s not searched (%3$s): %4$s rows contain the text', 'best-search-replace' ),
            /* translators: 1: value 1, 2: value 2, 3: value 3 */
            'excludedColsNoCount' => __( '%1$s.%2$s not searched (%3$s)', 'best-search-replace' ),
            /* translators: 1: value 1, 2: value 2, 3: value 3 */
            'skippedTable'     => __( '%1$s could not be searched (%2$s): %3$s rows contain the text', 'best-search-replace' ),
            /* translators: 1: value 1, 2: value 2 */
            'skippedTableNoCount' => __( '%1$s could not be searched (%2$s)', 'best-search-replace' ),
            'deferredInfo'     => __( 'Site address settings are updated last', 'best-search-replace' ),
            'deferredText'     => __( '"siteurl" and "home" are changed at the very end so the dashboard keeps working during the run. If your address changes, you may need to sign in again at the new one.', 'best-search-replace' ),
            /* translators: %s: value */
            'notSearchableTitle' => __( '%s tables could not be searched', 'best-search-replace' ),
            'notSearchableText'  => __( 'These tables have no primary key, or only contain binary data, so rows cannot be safely identified and rewritten. They were left untouched.', 'best-search-replace' ),
            /* translators: %s: value */
            'excludedColsTitle'  => __( '%s columns were not searched', 'best-search-replace' ),
            'excludedColsText'   => __( 'GUIDs and sign-in columns are skipped unless you opt in under Advanced when starting a new scan.', 'best-search-replace' ),
            /* translators: %s: value */
            'staleScan'        => __( 'Scanned %s ago. Content may have changed; matches are re-checked when replacing and anything that changed is skipped and listed.', 'best-search-replace' ),
            /* translators: %s: value */
            'transientsSkipped' => __( '%s transient rows were left alone (temporary cache data).', 'best-search-replace' ),
            /* translators: %s: value */
            'errorsTitle'      => __( '%s cells could not be processed', 'best-search-replace' ),
            'errorsText'       => __( 'These cells hold data we could not safely rewrite (for example damaged serialized data, or a replacement that would not fit the column). They are never written.', 'best-search-replace' ),
            'showErrors'       => __( 'Show errors', 'best-search-replace' ),
            'thingsToCheck'    => __( 'Things to check before replacing', 'best-search-replace' ),
            /* translators: %s: value */
            'warningsN'        => __( '%s warnings', 'best-search-replace' ),
            'allMatches'       => __( 'All matches', 'best-search-replace' ),
            'group'            => __( 'group', 'best-search-replace' ),
            'confirmStop'      => __( 'Stop', 'best-search-replace' ),
            'continue'         => __( 'Continue', 'best-search-replace' ),
            'unfinishedRun'    => __( 'An unfinished run was found.', 'best-search-replace' ),
            /* translators: 1: value 1, 2: value 2, 3: value 3, 4: value 4 */
            'startedBy'        => __( '%1$s → %2$s, %3$s, started by %4$s.', 'best-search-replace' ),
            'analysisError'    => __( 'Could not analyze the search text.', 'best-search-replace' ),
            'startError'       => __( 'Could not start the scan.', 'best-search-replace' ),
            'noTables'         => __( 'Choose at least one table.', 'best-search-replace' ),
            'sameText'         => __( '"Search for" and "Replace with" are the same.', 'best-search-replace' ),
            'runActive'        => __( 'Another run is in progress. Finish or stop it before starting a new one.', 'best-search-replace' ),
            'headsUp'          => __( 'Heads-up', 'best-search-replace' ),
            'exactNote'        => __( 'always on', 'best-search-replace' ),
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( BESR_Settings::capability() ) ) {
            wp_die( esc_html__( 'You do not have permission to use this tool.', 'best-search-replace' ) );
        }
        BESR_Schema::ensure();
        $tab         = $this->current_tab();
        $groups      = BESR_Groups::build( is_multisite() && is_super_admin() );
        $settings    = BESR_Settings::all();
        $show_global = is_multisite() && is_super_admin();
        $runs        = array();
        $runs_total  = 0;
        $paged       = 1;
        if ( 'history' === $tab ) {
            $paged      = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging on a capability-gated screen.
            $runs       = BESR_Run::list( array( 'limit' => 25, 'offset' => ( $paged - 1 ) * 25 ) );
            $runs_total = BESR_Run::count();
        }
        $journal_total = BESR_Journal::total_bytes();
        $flag_labels   = array();
        foreach ( array_merge( BESR_Context::TOGGLEABLE, BESR_Context::HARD ) as $f ) {
            $flag_labels[ $f ] = BESR_Context::label( $f );
        }
        include BESR_PLUGIN_DIR . 'templates/page.php';
    }

    // ---- downloads ----

    /**
     * The run a download request refers to, or stop with an error.
     */
    private function download_run(): BESR_Run {
        $run_id = isset( $_GET['run'] ) ? sanitize_text_field( wp_unslash( $_GET['run'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state; download handlers verify the nonce and capability in download_run().
        if ( ! current_user_can( BESR_Settings::capability() ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'besr_download_' . $run_id ) ) {
            wp_die( esc_html__( 'Not allowed.', 'best-search-replace' ), '', array( 'response' => 403 ) );
        }
        $run = BESR_Run::load( $run_id );
        if ( ! $run ) {
            wp_die( esc_html__( 'Unknown run.', 'best-search-replace' ), '', array( 'response' => 404 ) );
        }
        return $run;
    }

    public static function download_url( string $action, BESR_Run $run, array $extra = array() ): string {
        return add_query_arg( array_merge( array(
            'action'   => $action,
            'run'      => $run->id(),
            '_wpnonce' => wp_create_nonce( 'besr_download_' . $run->id() ),
        ), $extra ), admin_url( 'admin-post.php' ) );
    }

    public function export_undo(): void {
        $run = $this->download_run();
        $gz  = ! empty( $_GET['gz'] ) && function_exists( 'gzencode' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state; download handlers verify the nonce and capability in download_run().
        nocache_headers();
        header( 'Content-Type: ' . ( $gz ? 'application/gzip' : 'application/sql; charset=utf-8' ) );
        header( 'Content-Disposition: attachment; filename="besr-undo-' . $run->short() . '.sql' . ( $gz ? '.gz' : '' ) . '"' );
        if ( $gz ) {
            $buf = '';
            foreach ( BESR_Journal::export_sql( $run ) as $line ) {
                $buf .= $line;
            }
            echo gzencode( $buf ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- File download with an explicit Content-Type; HTML escaping would corrupt the SQL.
            exit;
        }
        foreach ( BESR_Journal::export_sql( $run ) as $line ) {
            echo $line; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- File download with an explicit Content-Type; HTML escaping would corrupt the SQL.
            if ( function_exists( 'ob_get_level' ) && ob_get_level() > 0 ) {
                ob_flush();
            }
            flush();
        }
        exit;
    }

    /**
     * Neutralise spreadsheet formula injection: a cell that starts with =, +, -, @,
     * tab or carriage return is executed as a formula by Excel and LibreOffice, so
     * any content an untrusted user planted in the database could run when an admin
     * opens the export. Prefixing with an apostrophe forces it to be read as text.
     */
    private static function csv_cell( $value ): string {
        $value = (string) $value;
        if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
            return "'" . $value;
        }
        return $value;
    }

    public function export_matches(): void {
        $run = $this->download_run();
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="besr-matches-' . $run->short() . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'table', 'primary_key', 'column', 'where', 'before', 'after', 'occurrences', 'will_replace', 'flags', 'selection', 'status', 'note' ) );
        $excluded = $run->excluded_contexts();
        $page     = 1;
        do {
            $res = BESR_Matches::query( $run->id(), array( 'page' => $page, 'per_page' => 200, 'excluded' => $excluded ) );
            foreach ( $res['rows'] as $r ) {
                $where = trim( ( $r['where']['type'] ?? '' ) . ' ' . ( $r['where']['label'] ?? '' ) );
                fputcsv( $out, array(
                    self::csv_cell( $r['table_name'] ),
                    self::csv_cell( wp_json_encode( $r['pk'] ) ),
                    self::csv_cell( $r['column_name'] ),
                    self::csv_cell( $where ),
                    self::csv_cell( html_entity_decode( wp_strip_all_tags( (string) $r['before'] ), ENT_QUOTES, 'UTF-8' ) ),
                    self::csv_cell( html_entity_decode( wp_strip_all_tags( (string) $r['after'] ), ENT_QUOTES, 'UTF-8' ) ),
                    (int) $r['occurrences'],
                    (int) $r['included'],
                    self::csv_cell( implode( '|', $r['flags'] ) ),
                    self::csv_cell( $r['selection'] ),
                    self::csv_cell( $r['status'] ),
                    self::csv_cell( $r['note'] ),
                ) );
            }
            ++$page;
        } while ( $page <= $res['pages'] );
        fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the php://output stream used to write the CSV download.
        exit;
    }
}
