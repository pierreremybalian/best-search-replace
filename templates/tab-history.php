<?php
/**
 * History: every run, with review / undo / delete.
 *
 * @var BESR_Run[] $runs
 * @var int        $runs_total
 * @var int        $paged
 * @var int        $journal_total
 * @var array      $settings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$besr_status_labels = array(
    'scanning'  => __( 'Scanning', 'best-search-replace' ),
    'scanned'   => __( 'Scanned', 'best-search-replace' ),
    'applying'  => __( 'Replacing', 'best-search-replace' ),
    'applied'   => __( 'Applied', 'best-search-replace' ),
    'undoing'   => __( 'Undoing', 'best-search-replace' ),
    'undone'    => __( 'Undone', 'best-search-replace' ),
    'error'     => __( 'Failed', 'best-search-replace' ),
    'cancelled' => __( 'Stopped', 'best-search-replace' ),
);
?>
<div class="besr-history-head">
    <p class="besr-history-meta">
        <?php
        if ( (int) $settings['retention_days'] > 0 ) {
            /* translators: 1: days, 2: size */
            echo esc_html( sprintf( __( 'Undo data kept %1$s days · %2$s in use', 'best-search-replace' ), number_format_i18n( (int) $settings['retention_days'] ), size_format( $journal_total ) ) );
        } else {
            /* translators: %s: size */
            echo esc_html( sprintf( __( 'Undo data kept until deleted · %s in use', 'best-search-replace' ), size_format( $journal_total ) ) );
        }
        ?>
    </p>
</div>
<?php if ( ! $runs ) : ?>
    <div class="besr-card"><p class="besr-empty"><?php esc_html_e( 'No runs yet.', 'best-search-replace' ); ?> <a href="<?php echo esc_url( BESR_Admin::page_url() ); ?>"><?php esc_html_e( 'Start with Search & replace.', 'best-search-replace' ); ?></a></p></div>
<?php else : ?>
<table class="widefat striped besr-runs" id="besr-runs">
    <thead><tr>
        <th scope="col"><?php esc_html_e( 'Date', 'best-search-replace' ); ?></th>
        <th scope="col"><?php esc_html_e( 'User', 'best-search-replace' ); ?></th>
        <th scope="col"><?php esc_html_e( 'Search → Replace', 'best-search-replace' ); ?></th>
        <th scope="col" class="besr-num"><?php esc_html_e( 'Tables', 'best-search-replace' ); ?></th>
        <th scope="col" class="besr-num"><?php esc_html_e( 'Matches', 'best-search-replace' ); ?></th>
        <th scope="col"><?php esc_html_e( 'Status', 'best-search-replace' ); ?></th>
        <th scope="col"><?php esc_html_e( 'Actions', 'best-search-replace' ); ?></th>
    </tr></thead>
    <tbody>
    <?php
    foreach ( $runs as $run ) :
        $p            = $run->summary_payload();
        $counts       = $run->counts();
        $run_status   = $run->status();
        $view         = BESR_Admin::page_url( array( 'run' => $run->id() ) );
        $matches_text = '';
        if ( in_array( $run_status, array( 'applied', 'undone' ), true ) ) {
            /* translators: %s: number of matches replaced. */
            $matches_text = sprintf( __( '%s replaced', 'best-search-replace' ), number_format_i18n( (int) ( $counts['applied_occurrences'] ?? 0 ) ) );
        } elseif ( 'scanned' === $run_status ) {
            /* translators: %s: number of matches found. */
            $matches_text = sprintf( __( '%s found', 'best-search-replace' ), number_format_i18n( (int) ( $counts['occurrences'] ?? 0 ) ) );
        } elseif ( (int) ( $counts['occurrences'] ?? 0 ) > 0 ) {
            $matches_text = number_format_i18n( (int) $counts['occurrences'] );
        }
        ?>
        <tr data-run="<?php echo (int) $run->id(); ?>">
            <td><?php echo esc_html( get_date_from_gmt( (string) $run->row( 'created_at' ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
            <td><?php echo esc_html( $p['user'] ?: '—' ); ?></td>
            <td class="besr-run-terms">
                <code title="<?php echo esc_attr( $run->search() ); ?>"><?php echo esc_html( mb_strlen( $run->search() ) > 40 ? mb_substr( $run->search(), 0, 40 ) . '…' : $run->search() ); ?></code>
                <span class="besr-arrow" aria-hidden="true">→</span>
                <code title="<?php echo esc_attr( $run->replace() ); ?>"><?php echo esc_html( '' === $run->replace() ? '(empty)' : ( mb_strlen( $run->replace() ) > 40 ? mb_substr( $run->replace(), 0, 40 ) . '…' : $run->replace() ) ); ?></code>
                <?php
                if ( count( $p['variants'] ) > 1 ) :
					?>
                    <?php /* translators: %s: number of additional search variants. */ ?>
                    <span class="besr-pill besr-pill-muted"><?php echo esc_html( sprintf( __( '+%s variants', 'best-search-replace' ), count( $p['variants'] ) - 1 ) ); ?></span><?php endif; ?>
                <?php
                if ( $p['case_insensitive'] ) :
					?>
                    <span class="besr-pill besr-pill-muted" title="<?php esc_attr_e( 'Ignore letter case', 'best-search-replace' ); ?>">Aa</span><?php endif; ?>
            </td>
            <td class="besr-num"><?php echo esc_html( number_format_i18n( count( $p['tables'] ?: $p['tables_requested'] ) ) ); ?></td>
            <td class="besr-num"><?php echo esc_html( $matches_text ?: '—' ); ?></td>
            <td>
                <span class="besr-status is-<?php echo esc_attr( $run_status ); ?>"><?php echo esc_html( $besr_status_labels[ $run_status ] ?? $run_status ); ?><?php echo $p['partial'] ? ' (' . esc_html__( 'partial', 'best-search-replace' ) . ')' : ''; ?></span>
                <?php
                if ( 'applied' === $run_status && $p['journal_expired'] ) :
					?>
                    <br /><small class="besr-muted"><?php esc_html_e( 'undo data removed', 'best-search-replace' ); ?></small><?php endif; ?>
            </td>
            <td class="besr-run-actions">
                <a href="<?php echo esc_url( $view ); ?>"><?php echo 'scanned' === $run_status ? esc_html__( 'Review', 'best-search-replace' ) : esc_html__( 'View', 'best-search-replace' ); ?></a>
                <?php if ( 'applied' === $run_status && ! $p['journal_expired'] ) : ?>
                    · <a href="<?php echo esc_url( add_query_arg( 'do', 'undo', $view ) ); ?>"><?php esc_html_e( 'Undo', 'best-search-replace' ); ?></a>
                    · <a href="<?php echo esc_url( BESR_Admin::download_url( 'besr_export_undo', $run ) ); ?>"><?php esc_html_e( 'Undo SQL', 'best-search-replace' ); ?></a>
                <?php endif; ?>
                <?php if ( 'error' === $run_status ) : ?>
                    · <a href="<?php echo esc_url( add_query_arg( 'do', 'retry', $view ) ); ?>"><?php esc_html_e( 'Retry', 'best-search-replace' ); ?></a>
                <?php endif; ?>
                <?php if ( ! $run->is_running() ) : ?>
                    · <button type="button" class="button-link-delete besr-run-delete" data-run="<?php echo (int) $run->id(); ?>"><?php esc_html_e( 'Delete', 'best-search-replace' ); ?></button>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
	<?php
	$besr_pages = (int) ceil( $runs_total / 25 );
	if ( $besr_pages > 1 ) :
		?>
    <nav class="besr-pager" aria-label="<?php esc_attr_e( 'History pages', 'best-search-replace' ); ?>">
        <?php for ( $i = 1; $i <= $besr_pages; $i++ ) : ?>
            <?php
            if ( $i === $paged ) :
				?>
                <span class="besr-page is-current"><?php echo (int) $i; ?></span>
				<?php
            else :
				?>
                <a class="besr-page" href="<?php echo esc_url( BESR_Admin::page_url( array( 'tab' => 'history', 'paged' => $i ) ) ); ?>"><?php echo (int) $i; ?></a><?php endif; ?>
        <?php endfor; ?>
    </nav>
	<?php endif; ?>
<?php endif; ?>
