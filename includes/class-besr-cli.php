<?php
/**
 * WP-CLI: wp besr <command>
 *
 * The same engine the admin screen uses, driven from the terminal.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    return;
}

/**
 * Search and replace across the database with preview, collision warnings and undo.
 *
 * ## EXAMPLES
 *
 *     # Find every match first (nothing is changed).
 *     wp besr scan "https://old.com" "https://new.com"
 *
 *     # Apply a reviewed scan, skipping matches inside longer domains.
 *     wp besr apply 7f3a2c --skip=longer_domain --yes
 *
 *     # Scan and apply in one go.
 *     wp besr apply "https://old.com" "https://new.com" --yes
 *
 *     # Revert a run.
 *     wp besr undo 7f3a2c --yes
 */
class BESR_CLI {

    /**
     * Find every match without changing anything.
     *
     * ## OPTIONS
     *
     * <search>
     * : Text to search for.
     *
     * <replace>
     * : Text to replace it with.
     *
     * [--tables=<tables>]
     * : recommended (default), all, core, or a comma-separated list of table names.
     *
     * [--groups=<groups>]
     * : Comma-separated content group keys (content, settings, taxonomy, comments, users, woocommerce, plugins, logs, other, global).
     *
     * [--include-global]
     * : Multisite: also search network-wide tables (users, usermeta, ...).
     *
     * [--variants=<variants>]
     * : Comma-separated variant keys (exact, scheme, www, json, urlencoded, scheme_relative, bare) or "none". Defaults to the plugin settings.
     *
     * [--exclude-contexts=<flags>]
     * : Comma-separated context flags to leave alone (inside_email, longer_domain, inside_word, in_hash_like_string, in_serialized_key, case_differs) or "none". Defaults to the plugin settings.
     *
     * [--include-guid]
     * : Also search the posts.guid column.
     *
     * [--include-transients]
     * : Also search transient option rows.
     *
     * [--include-identity]
     * : Also search protected sign-in columns (user_email, user_login, comment_author_email).
     *
     * [--include-pkless]
     * : Also search tables without a primary key (rows are identified by their full content).
     *
     * [--case-insensitive]
     * : Ignore letter case when matching.
     *
     * [--budget=<seconds>]
     * : Seconds of work per step. Default 60.
     *
     * [--format=<format>]
     * : table (default), json, yaml or csv.
     *
     * [--porcelain]
     * : Print only the run UUID.
     *
     * ## EXAMPLES
     *
     *     wp besr scan "https://old.com" "https://new.com"
     *     wp besr scan "old.com" "new.com" --tables=wp_posts,wp_postmeta --exclude-contexts=inside_email
     *     wp besr scan "Acme Inc" "Acme Ltd" --groups=content,settings --format=json
     */
    public function scan( array $args, array $assoc ): void {
        BESR_Schema::ensure();
        $run = $this->start_scan( $args, $assoc );
        $this->run( $run, $this->budget( $assoc ) );
        if ( ! empty( $assoc['porcelain'] ) ) {
            WP_CLI::line( $run->uuid() );
            return;
        }
        $this->report_scan( $run, (string) ( $assoc['format'] ?? 'table' ) );
        if ( BESR_Run::STATUS_ERROR === $run->status() ) {
            WP_CLI::halt( 1 );
        }
    }

    /**
     * Replace the matches of a reviewed scan, or scan and replace in one go.
     *
     * ## OPTIONS
     *
     * [<run-or-search>]
     * : A run ID/UUID from `wp besr scan`, or the text to search for when <replace> is also given.
     *
     * [<replace>]
     * : Text to replace with (scan + apply mode).
     *
     * [--dry-run]
     * : Scan only, never apply (same as `wp besr scan`).
     *
     * [--skip=<flags>]
     * : Comma-separated context flags to skip in addition to the run's exclusions.
     *
     * [--include=<flags>]
     * : Comma-separated context flags to include even though they were excluded.
     *
     * [--stale=<mode>]
     * : What to do with cells that changed since the scan: skip (default) or replace.
     *
     * [--keep-siteurl]
     * : Leave the siteurl and home options unchanged.
     *
     * [--no-journal]
     * : Do not keep undo data (faster, but the run cannot be undone).
     *
     * [--tables=<tables>]
     * : Scan mode: recommended (default), all, core, or a comma-separated list.
     *
     * [--groups=<groups>]
     * : Scan mode: comma-separated content group keys.
     *
     * [--include-global]
     * : Scan mode: also search network-wide tables.
     *
     * [--variants=<variants>]
     * : Scan mode: variant keys or "none".
     *
     * [--exclude-contexts=<flags>]
     * : Scan mode: context flags to leave alone, or "none".
     *
     * [--include-guid]
     * : Scan mode: also search posts.guid.
     *
     * [--include-transients]
     * : Scan mode: also search transients.
     *
     * [--include-identity]
     * : Scan mode: also search protected sign-in columns.
     *
     * [--include-pkless]
     * : Scan mode: also search tables without a primary key.
     *
     * [--case-insensitive]
     * : Scan mode: ignore letter case.
     *
     * [--budget=<seconds>]
     * : Seconds of work per step. Default 60.
     *
     * [--format=<format>]
     * : table (default) or json.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp besr apply 7f3a2c --yes
     *     wp besr apply 7f3a2c --skip=longer_domain --include=inside_email
     *     wp besr apply "https://old.com" "https://new.com" --yes
     */
    public function apply( array $args, array $assoc ): void {
        BESR_Schema::ensure();
        $budget = $this->budget( $assoc );
        $format = (string) ( $assoc['format'] ?? 'table' );

        if ( count( $args ) >= 2 ) {
            $run = $this->start_scan( $args, $assoc );
            $this->run( $run, $budget );
            $this->report_scan( $run, $format );
            if ( BESR_Run::STATUS_ERROR === $run->status() ) {
                WP_CLI::halt( 1 );
            }
            if ( ! empty( $assoc['dry-run'] ) ) {
                return;
            }
        } elseif ( 1 === count( $args ) ) {
            $run = $this->load_run( $args[0] );
        } else {
            WP_CLI::error( 'Give a run ID, or a search and a replace text.' );
            return;
        }

        if ( BESR_Run::STATUS_SCANNED !== $run->status() ) {
            WP_CLI::error( sprintf( 'Run %s is %s; only a finished scan can be applied.', $run->short(), $run->status() ) );
        }

        // Adjust exclusions before applying.
        if ( ! empty( $assoc['skip'] ) || ! empty( $assoc['include'] ) ) {
            $excluded = $run->excluded_contexts();
            $excluded = array_merge( $excluded, $this->csv( (string) ( $assoc['skip'] ?? '' ) ) );
            $excluded = array_diff( $excluded, $this->csv( (string) ( $assoc['include'] ?? '' ) ) );
            $run->set_excluded_contexts( array_values( array_unique( $excluded ) ) );
            BESR_Scanner::refresh_summary( $run );
            $run->save();
        }

        $summary = $run->summary();
        $counts  = (array) ( $summary['counts'] ?? array() );
        $rules   = BESR_Ruleset::from_run( $run );
        WP_CLI::log( sprintf( 'Run %s: "%s" → "%s" (%d variant(s))', $run->short(), $run->search(), $run->replace(), count( $rules->branches ) ) );
        WP_CLI::log( sprintf( 'Replacing %s matches in %s cells across %s tables.',
            number_format_i18n( (int) ( $counts['included'] ?? 0 ) ),
            number_format_i18n( (int) ( $counts['cells_included'] ?? 0 ) ),
            number_format_i18n( (int) ( $counts['tables_matched'] ?? 0 ) )
        ) );
        $skips = array();
        foreach ( (array) ( $summary['flags'] ?? array() ) as $f ) {
            if ( ! empty( $f['excluded'] ) && (int) $f['count'] > 0 ) {
                $skips[] = sprintf( '%s (%s)', $f['label'], number_format_i18n( (int) $f['count'] ) );
            }
        }
        if ( $skips ) {
            WP_CLI::log( 'Skipping: ' . implode( ', ', $skips ) . '.' );
        }
        foreach ( (array) ( $summary['flags'] ?? array() ) as $f ) {
            if ( empty( $f['excluded'] ) && (int) $f['count'] > 0 ) {
                WP_CLI::warning( sprintf( '%s matches %s will be replaced (not skipped).', number_format_i18n( (int) $f['count'] ), $f['label'] ) );
            }
        }
        if ( (int) ( $counts['included'] ?? 0 ) <= 0 ) {
            WP_CLI::success( 'Nothing to replace.' );
            return;
        }
        WP_CLI::log( ! empty( $assoc['no-journal'] ) ? 'No undo point will be saved (--no-journal).' : 'An undo point will be saved before anything changes.' );
        WP_CLI::confirm( 'Continue?', $assoc );

        $result = BESR_Engine::begin_apply( $run, array(
            'stale'        => (string) ( $assoc['stale'] ?? 'skip' ),
            'keep_siteurl' => ! empty( $assoc['keep-siteurl'] ),
            'no_journal'   => ! empty( $assoc['no-journal'] ),
        ) );
        $this->check( $result );
        $this->run( $run, $budget );
        $this->report_apply( $run, $format );
        if ( BESR_Run::STATUS_APPLIED !== $run->status() ) {
            WP_CLI::halt( 1 );
        }
    }

    /**
     * Revert an applied run using its undo journal.
     *
     * ## OPTIONS
     *
     * <run>
     * : Run ID or UUID.
     *
     * [--force]
     * : Restore cells even when they were edited after the run.
     *
     * [--budget=<seconds>]
     * : Seconds of work per step. Default 60.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp besr undo 7f3a2c --yes
     */
    public function undo( array $args, array $assoc ): void {
        BESR_Schema::ensure();
        $run = $this->load_run( $args[0] ?? '' );
        if ( BESR_Run::STATUS_APPLIED !== $run->status() ) {
            WP_CLI::error( sprintf( 'Run %s is %s; only an applied run can be undone.', $run->short(), $run->status() ) );
        }
        if ( $run->get( 'journal_pruned' ) ) {
            WP_CLI::error( 'The undo data for this run has been removed.' );
        }
        $preview = BESR_Journal::conflicts_preview( $run );
        WP_CLI::log( sprintf( 'Run %s applied %s by %s. %s cells in %d tables will be restored.',
            $run->short(),
            (string) $run->row( 'applied_at' ),
            $run->summary_payload()['user'] ?: 'unknown',
            number_format_i18n( (int) $preview['total'] ),
            (int) $preview['tables']
        ) );
        if ( $preview['changed'] > 0 ) {
            $sample = $preview['checked'] < $preview['total'] ? sprintf( ' (in a sample of %d)', $preview['checked'] ) : '';
            WP_CLI::warning( sprintf( '%d cells were edited after the run%s and will be left as they are now. Use --force to restore them too.', (int) $preview['changed'], $sample ) );
            foreach ( $preview['examples'] as $ex ) {
                WP_CLI::log( sprintf( '  %s %s %s', $ex['table'], $this->pk_label( $ex['pk'] ), $ex['column'] ) );
            }
        }
        if ( $preview['overlap_runs'] > 0 ) {
            WP_CLI::warning( sprintf( '%d later run(s) changed some of the same cells. Undo those first, or expect conflicts.', (int) $preview['overlap_runs'] ) );
        }
        WP_CLI::confirm( 'Continue?', $assoc );

        $this->check( BESR_Engine::begin_undo( $run, array( 'force' => ! empty( $assoc['force'] ) ) ) );
        $this->run( $run, $this->budget( $assoc ) );

        foreach ( $run->tables() as $t ) {
            if ( (int) ( $t['restored'] ?? 0 ) > 0 ) {
                WP_CLI::log( sprintf( '  %-40s %s cells restored', $t['name'], number_format_i18n( (int) $t['restored'] ) ) );
            }
        }
        if ( BESR_Run::STATUS_UNDONE === $run->status() ) {
            WP_CLI::success( sprintf( 'Undone. %s cells restored, %s left unchanged, %s errors.',
                number_format_i18n( $run->count_get( 'reverted' ) ),
                number_format_i18n( $run->count_get( 'conflicts' ) ),
                number_format_i18n( $run->count_get( 'undo_errors' ) )
            ) );
            if ( $run->count_get( 'conflicts' ) > 0 ) {
                foreach ( array_slice( (array) $run->get( 'conflict_list', array() ), 0, 10 ) as $c ) {
                    WP_CLI::log( sprintf( '  %s %s %s: %s', $c['table'], $this->pk_label( $c['pk'] ), $c['column'], $c['reason'] ) );
                }
            }
        } else {
            WP_CLI::error( $run->error() ?: 'Undo did not finish.' );
        }
    }

    /**
     * Show the active or a given run.
     *
     * ## OPTIONS
     *
     * [<run>]
     * : Run ID or UUID. Defaults to the running or failed run.
     *
     * @param array $args  Positional arguments; an optional run reference.
     * @param array $assoc Associative arguments; unused, WP-CLI always passes it.
     */
    public function status( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required by the WP-CLI command signature; this command takes no options.
        BESR_Schema::ensure();
        $run = ! empty( $args[0] ) ? $this->load_run( $args[0] ) : BESR_Run::unfinished();
        if ( ! $run ) {
            WP_CLI::log( 'No run is active.' );
            return;
        }
        $engine = new BESR_Engine( $run, 1 );
        $out    = $engine->response();
        WP_CLI::log( sprintf( 'Run %s: %s  [%d%%] %s', $run->short(), $run->status(), $out['progress'], $out['message'] ) );
        WP_CLI::log( sprintf( '"%s" → "%s"  (%d tables, started %s by %s)', $run->search(), $run->replace(), count( $run->tables() ), (string) $run->row( 'created_at' ), $out['run']['user'] ?: 'unknown' ) );
        foreach ( array_slice( $run->log_lines(), -10 ) as $line ) {
            WP_CLI::log( '  ' . gmdate( 'H:i:s', (int) $line['t'] ) . '  ' . $line['m'] );
        }
    }

    /**
     * List runs.
     *
     * ## OPTIONS
     *
     * [--status=<statuses>]
     * : Comma-separated statuses to include (scanning, scanned, applying, applied, undoing, undone, error, cancelled).
     *
     * [--limit=<n>]
     * : Maximum number of runs. Default 50.
     *
     * [--format=<format>]
     * : table (default), json, csv, yaml, count or ids.
     */
    public function runs( array $args, array $assoc ): void {
        BESR_Schema::ensure();
        $list_args = array( 'limit' => (int) ( $assoc['limit'] ?? 50 ) );
        if ( ! empty( $assoc['status'] ) ) {
            $list_args['status'] = $this->csv( (string) $assoc['status'] );
        }
        $items = array();
        foreach ( BESR_Run::list( $list_args ) as $run ) {
            $p       = $run->summary_payload();
            $counts  = (array) ( $run->summary()['counts'] ?? array() );
            $matches = in_array( $run->status(), array( BESR_Run::STATUS_APPLIED, BESR_Run::STATUS_UNDONE ), true )
                ? number_format_i18n( $run->count_get( 'applied_occurrences' ) ) . ' replaced'
                : number_format_i18n( (int) ( $counts['included'] ?? $run->count_get( 'included' ) ) ) . ' found';
            $items[] = array(
                'run'        => $run->short(),
                'date'       => (string) $run->row( 'created_at' ),
                'user'       => $p['user'],
                'search'     => $this->truncate( $run->search(), 40 ) . ' → ' . $this->truncate( $run->replace(), 40 ),
                'tables'     => sprintf( '%d/%d', (int) ( $counts['tables_matched'] ?? 0 ), count( $run->tables() ) ),
                'matches'    => $matches,
                'status'     => $run->status() . ( $p['partial'] ? ' (partial)' : '' ),
                'undo_until' => $p['journal_expired'] ? 'expired' : ( $p['undo_until'] ?: '—' ),
            );
        }
        if ( ! $items && empty( $assoc['format'] ) ) {
            WP_CLI::log( 'No runs yet.' );
            return;
        }
        WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $items, array( 'run', 'date', 'user', 'search', 'tables', 'matches', 'status', 'undo_until' ) );
    }

    /**
     * List the matches of a run.
     *
     * ## OPTIONS
     *
     * <run>
     * : Run ID or UUID.
     *
     * [--table=<table>]
     * : Only this table.
     *
     * [--flag=<flag>]
     * : Only matches carrying this context flag.
     *
     * [--status=<status>]
     * : Only matches with this status (pending, applied, noop, skipped, stale, error).
     *
     * [--view=<view>]
     * : all (default), flagged, skipped, errors or stale.
     *
     * [--limit=<n>]
     * : Maximum rows. Default 100.
     *
     * [--format=<format>]
     * : table (default), csv, json, yaml or count.
     *
     * ## EXAMPLES
     *
     *     wp besr matches 7f3a2c --flag=longer_domain --format=csv
     */
    public function matches( array $args, array $assoc ): void {
        BESR_Schema::ensure();
        $run    = $this->load_run( $args[0] ?? '' );
        $result = BESR_Matches::query( $run->id(), array(
            'table'    => (string) ( $assoc['table'] ?? '' ),
            'flag'     => (string) ( $assoc['flag'] ?? '' ),
            'status'   => (string) ( $assoc['status'] ?? '' ),
            'view'     => (string) ( $assoc['view'] ?? 'all' ),
            'page'     => 1,
            'per_page' => max( 1, min( 200, (int) ( $assoc['limit'] ?? 100 ) ) ),
            'excluded' => $run->excluded_contexts(),
        ) );
        $format = (string) ( $assoc['format'] ?? 'table' );
        $items  = array();
        foreach ( $result['rows'] as $r ) {
            $items[] = array(
                'table'     => $r['table_name'],
                'pk'        => $this->pk_label( $r['pk'] ),
                'column'    => $r['column_name'],
                'where'     => trim( ( $r['where']['type'] ?? '' ) . ' ' . ( $r['where']['label'] ?? '' ) ),
                'before'    => $this->plain( (string) $r['before'] ),
                'after'     => $this->plain( (string) $r['after'] ),
                'flags'     => implode( ',', (array) $r['flags'] ),
                'selection' => $r['selection'],
                'status'    => $r['status'] . ( '' !== $r['note'] ? ' (' . $r['note'] . ')' : '' ),
            );
        }
        if ( 'table' === $format && $result['total'] > count( $items ) ) {
            WP_CLI::log( sprintf( 'Showing %d of %s matches. Raise --limit or filter with --table/--flag.', count( $items ), number_format_i18n( (int) $result['total'] ) ) );
        }
        WP_CLI\Utils\format_items( $format, $items, array( 'table', 'pk', 'column', 'where', 'before', 'after', 'flags', 'selection', 'status' ) );
    }

    /**
     * Export the undo journal of a run as SQL.
     *
     * ## OPTIONS
     *
     * <run>
     * : Run ID or UUID.
     *
     * [--file=<path>]
     * : Write to this file instead of standard output.
     *
     * ## EXAMPLES
     *
     *     wp besr export 7f3a2c --file=undo.sql
     */
    public function export( array $args, array $assoc ): void {
        BESR_Schema::ensure();
        $run = $this->load_run( $args[0] ?? '' );
        if ( 0 === BESR_Journal::count( $run->id() ) ) {
            WP_CLI::error( 'This run has no undo data.' );
        }
        $fh = ! empty( $assoc['file'] ) ? @fopen( (string) $assoc['file'], 'w' ) : STDOUT; // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged -- WP-CLI writes the undo script to an operator-supplied path in a stream; WP_Filesystem cannot stream and is not loaded in CLI context.
        if ( ! $fh ) {
            WP_CLI::error( 'Could not open ' . $assoc['file'] . ' for writing.' );
        }
        $lines = 0;
        foreach ( BESR_Journal::export_sql( $run ) as $line ) {
            fwrite( $fh, $line ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Streaming write to an operator-supplied path; see the fopen() note above.
            ++$lines;
        }
        if ( STDOUT !== $fh ) {
            fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Streaming write to an operator-supplied path; see the fopen() note above.
            WP_CLI::success( sprintf( 'Wrote %d lines to %s', $lines, $assoc['file'] ) );
        }
    }

    /**
     * Delete a run and its undo data.
     *
     * ## OPTIONS
     *
     * <run>
     * : Run ID or UUID.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     */
    public function delete( array $args, array $assoc ): void {
        BESR_Schema::ensure();
        $run = $this->load_run( $args[0] ?? '' );
        if ( $run->is_running() ) {
            WP_CLI::error( 'That run is still active. Stop it first with `wp besr stop`.' );
        }
        WP_CLI::confirm( sprintf( 'Delete run %s ("%s" → "%s") and its undo data?', $run->short(), $run->search(), $run->replace() ), $assoc );
        $run->delete();
        WP_CLI::success( 'Deleted.' );
    }

    /**
     * Remove old undo data and finished runs.
     *
     * ## OPTIONS
     *
     * [--older-than=<days>]
     * : Remove undo data of runs applied more than this many days ago (overrides the retention setting for this call).
     *
     * [--all]
     * : Delete every run that is not currently active.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp besr cleanup
     *     wp besr cleanup --older-than=7
     *     wp besr cleanup --all --yes
     */
    public function cleanup( array $args, array $assoc ): void {
        BESR_Schema::ensure();
        if ( ! empty( $assoc['all'] ) ) {
            WP_CLI::confirm( 'Delete every run that is not active, including all undo data?', $assoc );
            $active = BESR_Run::active();
            $n      = 0;
            foreach ( BESR_Run::list( array( 'limit' => 100000 ) ) as $run ) {
                if ( $active && $active->id() === $run->id() ) {
                    continue;
                }
                $run->delete();
                ++$n;
            }
            WP_CLI::success( sprintf( 'Deleted %d run(s).', $n ) );
            return;
        }
        $override = null;
        if ( isset( $assoc['older-than'] ) ) {
            $days     = max( 0, (int) $assoc['older-than'] );
            $override = function ( $settings ) use ( $days ) {
                $settings['retention_days'] = $days;
                return $settings;
            };
            add_filter( 'option_' . BESR_Settings::OPTION, $override );
        }
        $report = BESR_Journal::prune();
        if ( $override ) {
            remove_filter( 'option_' . BESR_Settings::OPTION, $override );
        }
        WP_CLI::success( sprintf( 'Removed undo data of %d run(s) and deleted %d stale run(s). Undo data now uses %s.',
            (int) ( $report['journals'] ?? 0 ),
            (int) ( $report['runs'] ?? 0 ),
            size_format( BESR_Journal::total_bytes() )
        ) );
    }

    /**
     * Stop the active scan or replace.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip the confirmation prompt.
     */
    public function stop( array $args, array $assoc ): void {
        BESR_Schema::ensure();
        $run = BESR_Run::active();
        if ( ! $run ) {
            WP_CLI::log( 'No run is active.' );
            return;
        }
        if ( BESR_Run::STATUS_APPLYING === $run->status() ) {
            WP_CLI::confirm( sprintf( 'Stop replacing now? Tables already done stay changed (undo with `wp besr undo %s`).', $run->short() ), $assoc );
        }
        $this->check( BESR_Engine::cancel( $run ) );
        $fresh = BESR_Run::load( $run->id() );
        WP_CLI::success( sprintf( 'Run %s is now %s.', $run->short(), $fresh ? $fresh->status() : $run->status() ) );
    }

    /**
     * Continue a failed run.
     *
     * ## OPTIONS
     *
     * [<run>]
     * : Run ID or UUID. Defaults to the most recent failed run.
     *
     * [--budget=<seconds>]
     * : Seconds of work per step. Default 60.
     */
    public function retry( array $args, array $assoc ): void {
        BESR_Schema::ensure();
        $run = ! empty( $args[0] ) ? $this->load_run( $args[0] ) : BESR_Run::unfinished();
        if ( ! $run ) {
            WP_CLI::error( 'No failed run to retry.' );
        }
        if ( $run->is_running() ) {
            WP_CLI::log( sprintf( 'Run %s is still %s; continuing it.', $run->short(), $run->status() ) );
        } else {
            $this->check( BESR_Engine::retry( $run ) );
        }
        $this->run( $run, $this->budget( $assoc ) );
        switch ( $run->status() ) {
            case BESR_Run::STATUS_SCANNED:
                $this->report_scan( $run, 'table' );
                break;
            case BESR_Run::STATUS_APPLIED:
                $this->report_apply( $run, 'table' );
                break;
            case BESR_Run::STATUS_UNDONE:
                WP_CLI::success( sprintf( 'Undone. %s cells restored, %s left unchanged.', number_format_i18n( $run->count_get( 'reverted' ) ), number_format_i18n( $run->count_get( 'conflicts' ) ) ) );
                break;
            default:
                WP_CLI::error( $run->error() ?: sprintf( 'Run ended with status %s.', $run->status() ) );
        }
    }

    // ------------------------------------------------------------------

    /**
     * Build the scan input from CLI flags and start it.
     */
    private function start_scan( array $args, array $assoc ): BESR_Run {
        $input  = array(
            'search'             => (string) $args[0],
            'replace'            => (string) ( $args[1] ?? '' ),
            'case_insensitive'   => ! empty( $assoc['case-insensitive'] ),
            'include_global'     => ! empty( $assoc['include-global'] ),
            'include_guid'       => ! empty( $assoc['include-guid'] ),
            'include_transients' => ! empty( $assoc['include-transients'] ),
            'include_identity'   => ! empty( $assoc['include-identity'] ),
            'include_pkless'     => ! empty( $assoc['include-pkless'] ),
            'groups'             => array(),
            'tables'             => array(),
        );
        $tables = trim( (string) ( $assoc['tables'] ?? '' ) );
        if ( '' !== $tables && in_array( $tables, array( 'recommended', 'all', 'core' ), true ) ) {
            $input['groups'][] = $tables;
        } elseif ( '' !== $tables ) {
            $input['tables'] = $this->csv( $tables );
        }
        if ( ! empty( $assoc['groups'] ) ) {
            $input['groups'] = array_merge( $input['groups'], $this->csv( (string) $assoc['groups'] ) );
        }
        if ( ! $input['groups'] && ! $input['tables'] ) {
            $input['groups'] = array( 'recommended' );
        }
        if ( isset( $assoc['variants'] ) ) {
            $input['variants'] = 'none' === $assoc['variants'] ? array() : $this->csv( (string) $assoc['variants'] );
        }
        if ( isset( $assoc['exclude-contexts'] ) ) {
            $input['excluded_contexts'] = 'none' === $assoc['exclude-contexts'] ? array() : $this->csv( (string) $assoc['exclude-contexts'] );
        }

        $run = BESR_Engine::start_scan( $input );
        if ( is_wp_error( $run ) ) {
            if ( 'besr_busy' === $run->get_error_code() ) {
                WP_CLI::warning( $run->get_error_message() . ' Use `wp besr status` or `wp besr stop`.' );
                WP_CLI::halt( 2 );
            }
            WP_CLI::error( $run->get_error_message() );
        }
        $rules = BESR_Ruleset::from_run( $run );
        $t     = $rules->term;
        WP_CLI::log( sprintf( '%s detected. %d pattern(s): %s.', BESR_Term::type_label( $t['type'] ), count( $rules->branches ), implode( ', ', array_unique( array_column( $rules->branches, 'key' ) ) ) ) );
        WP_CLI::log( sprintf( 'Scanning %d table(s)…', count( $run->config( 'tables', array() ) ) ) );
        return $run;
    }

    /**
     * Tick the engine until the stage finishes, streaming log lines.
     */
    private function run( BESR_Run $run, float $budget ): void {
        $last_message = '';
        $log_from     = count( $run->log_lines() );
        $verbose      = WP_CLI::get_config( 'debug' ) || false !== getenv( 'BESR_VERBOSE' );

        while ( $run->is_running() ) {
            $engine = new BESR_Engine( $run, $budget );
            $out    = $engine->tick();
            if ( ! empty( $out['busy'] ) ) {
                WP_CLI::warning( 'Another process holds the lock on this run; waiting…' );
                sleep( 5 );
                continue;
            }
            foreach ( array_slice( $run->log_lines(), $log_from ) as $line ) {
                $m = (string) $line['m'];
                if ( 0 === strpos( $m, 'ERROR' ) ) {
                    WP_CLI::warning( $m );
                } elseif ( $verbose || preg_match( '/^[A-Za-z0-9_]+: [\d,.]+ rows scanned|^WARNING|^INFO|Not searchable|Site address|Undo|Skipp|restored|cache/i', $m ) ) {
                    WP_CLI::log( '  ' . $m );
                }
            }
            $log_from = count( $run->log_lines() );
            $message  = sprintf( '[%3d%%] %s', $out['progress'], $out['message'] );
            if ( $message !== $last_message && $run->is_running() ) {
                WP_CLI::log( $message );
                $last_message = $message;
            }
        }
    }

    private function report_scan( BESR_Run $run, string $format ): void {
        $summary = $run->summary();
        $counts  = (array) ( $summary['counts'] ?? array() );
        $flags   = (array) ( $summary['flags'] ?? array() );

        if ( BESR_Run::STATUS_ERROR === $run->status() ) {
            WP_CLI::warning( 'Scan failed: ' . $run->error() . sprintf( '  Continue with `wp besr retry %s`.', $run->short() ) );
            return;
        }
        if ( BESR_Run::STATUS_CANCELLED === $run->status() ) {
            WP_CLI::warning( 'Scan was stopped.' );
            return;
        }

        // Group summary.
        $groups = array();
        $tables = array();
        foreach ( $run->tables() as $t ) {
            $g   = BESR_Groups::classify( $t['name'] );
            $key = $g['subgroup'] ?: $g['group'];
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = array( 'group' => $g['label'], 'matches' => 0, 'cells' => 0, 'tables' => 0 );
            }
            $groups[ $key ]['matches'] += (int) $t['occurrences'];
            $groups[ $key ]['cells']   += (int) $t['cells'];
            if ( (int) $t['cells'] > 0 ) {
                ++$groups[ $key ]['tables'];
            }
            $tables[] = array(
                'table'   => $t['name'],
                'group'   => $g['label'],
                'status'  => 'skipped' === $t['status'] ? 'skipped: ' . BESR_Scanner::skip_label( $t['skip_reason'] ) : $t['status'],
                'rows'    => (int) $t['scanned_rows'],
                'cells'   => (int) $t['cells'],
                'matches' => (int) $t['occurrences'],
            );
        }

        if ( 'json' === $format || 'yaml' === $format ) {
            WP_CLI::print_value( array(
                'run'      => $run->summary_payload(),
                'counts'   => $counts,
                'tables'   => $tables,
                'flags'    => array_values( $flags ),
                'warnings' => (array) ( $summary['warnings'] ?? array() ),
                'skipped_tables'   => (array) ( $summary['skipped_tables'] ?? array() ),
                'excluded_columns' => (array) ( $summary['excluded_columns'] ?? array() ),
            ), array( 'format' => $format ) );
            return;
        }
        if ( 'csv' === $format ) {
            WP_CLI\Utils\format_items( 'csv', $tables, array( 'table', 'group', 'status', 'rows', 'cells', 'matches' ) );
            return;
        }

        WP_CLI::log( '' );
        WP_CLI::log( sprintf( 'Run %s: %s matches in %s cells across %d of %d tables.',
            $run->short(),
            number_format_i18n( (int) ( $counts['occurrences'] ?? 0 ) ),
            number_format_i18n( (int) ( $counts['cells'] ?? 0 ) ),
            (int) ( $counts['tables_matched'] ?? 0 ),
            (int) ( $counts['tables_total'] ?? count( $run->tables() ) )
        ) );
        $rows = array_values( array_filter( $groups, function ( $g ) {
            return $g['matches'] > 0;
        } ) );
        if ( $rows ) {
            WP_CLI::log( '' );
            WP_CLI\Utils\format_items( 'table', $rows, array( 'group', 'matches', 'cells', 'tables' ) );
        }
        WP_CLI::log( '' );

        foreach ( $flags as $f ) {
            if ( (int) $f['count'] <= 0 ) {
                continue;
            }
            $state   = ! empty( $f['hard'] ) ? 'never replaced' : ( ! empty( $f['excluded'] ) ? 'skipped by default' : 'NOT skipped' );
            $example = '';
            foreach ( BESR_Matches::examples( $run->id(), (string) $f['flag'], 1 ) as $ex ) {
                $example = sprintf( ' e.g. %s.%s %s', $ex['table_name'], $ex['column_name'], $this->truncate( $this->plain( (string) $ex['before'] ), 90 ) );
            }
            $line = sprintf( '%s matches %s (%s).%s', number_format_i18n( (int) $f['count'] ), $f['label'], $state, $example );
            if ( ! empty( $f['excluded'] ) ) {
                WP_CLI::log( WP_CLI::colorize( '%YNotice:%n ' ) . $line );
            } else {
                WP_CLI::warning( $line );
            }
        }
        foreach ( (array) ( $summary['skipped_tables'] ?? array() ) as $s ) {
            $hits = null === $s['like_hits'] ? '' : sprintf( ' — %s rows contain the text', number_format_i18n( (int) $s['like_hits'] ) );
            WP_CLI::log( WP_CLI::colorize( '%YNotice:%n ' ) . sprintf( 'Table %s was not searched (%s)%s.', $s['name'], $s['label'], $hits ) );
        }
        foreach ( (array) ( $summary['excluded_columns'] ?? array() ) as $c ) {
            if ( null !== $c['like_hits'] && (int) $c['like_hits'] <= 0 ) {
                continue;
            }
            $hits = null === $c['like_hits'] ? '' : sprintf( ', %s rows contain the text', number_format_i18n( (int) $c['like_hits'] ) );
            WP_CLI::log( WP_CLI::colorize( '%YNotice:%n ' ) . sprintf( 'Column %s.%s was not searched (%s%s). ', $c['table'], $c['column'], $c['label'], $hits ) );
        }
        if ( (int) ( $counts['deferred'] ?? 0 ) > 0 ) {
            WP_CLI::log( WP_CLI::colorize( '%YNotice:%n ' ) . 'siteurl/home will be updated last.' );
        }
        if ( (int) ( $counts['errors'] ?? 0 ) > 0 ) {
            WP_CLI::warning( sprintf( '%d cell(s) cannot be replaced safely (see `wp besr matches %s --view=errors`).', (int) $counts['errors'], $run->short() ) );
        }
        foreach ( (array) ( $summary['warnings'] ?? array() ) as $w ) {
            if ( 'warning' === ( $w['level'] ?? '' ) ) {
                WP_CLI::warning( $w['message'] );
            }
        }

        WP_CLI::log( '' );
        WP_CLI::log( sprintf( 'Will replace %s matches.', number_format_i18n( (int) ( $counts['included'] ?? 0 ) ) ) );
        WP_CLI::log( 'Review in the browser:  ' . admin_url( 'tools.php?page=best-search-replace&run=' . $run->id() ) );
        WP_CLI::log( sprintf( 'Or apply now:           wp besr apply %s [--skip=<flags>] [--include=<flags>] --yes', $run->short() ) );
    }

    private function report_apply( BESR_Run $run, string $format ): void {
        if ( 'json' === $format || 'yaml' === $format ) {
            WP_CLI::print_value( array( 'run' => $run->summary_payload(), 'counts' => $run->counts(), 'errors' => (array) $run->get( 'error_list', array() ) ), array( 'format' => $format ) );
            if ( BESR_Run::STATUS_APPLIED !== $run->status() ) {
                WP_CLI::halt( 1 );
            }
            return;
        }
        foreach ( $run->tables() as $t ) {
            if ( (int) ( $t['updated'] ?? 0 ) > 0 || (int) ( $t['errors'] ?? 0 ) > 0 ) {
                $err = (int) ( $t['errors'] ?? 0 ) > 0 ? sprintf( ', %d error(s)', (int) $t['errors'] ) : '';
                WP_CLI::log( sprintf( '  %-40s %s rows updated%s', $t['name'], number_format_i18n( (int) ( $t['updated'] ?? 0 ) ), $err ) );
            }
        }
        if ( $run->count_get( 'stale' ) > 0 ) {
            WP_CLI::log( sprintf( '  %s cell(s) changed since the scan and were skipped. Re-scan to catch them.', number_format_i18n( $run->count_get( 'stale' ) ) ) );
        }
        if ( $run->count_get( 'apply_skipped' ) > 0 ) {
            WP_CLI::log( sprintf( '  %s cell(s) skipped (see `wp besr matches %s --status=skipped`).', number_format_i18n( $run->count_get( 'apply_skipped' ) ), $run->short() ) );
        }
        if ( $run->get( 'new_home' ) ) {
            WP_CLI::log( '  Site address is now ' . $run->get( 'new_home' ) );
        }
        if ( BESR_Run::STATUS_APPLIED !== $run->status() ) {
            WP_CLI::error( sprintf( '%s  Continue with `wp besr retry %s` or revert with `wp besr undo %s`.', $run->error() ?: 'Replace did not finish.', $run->short(), $run->short() ) );
        }
        WP_CLI::success( sprintf( 'Replaced %s matches in %s rows across %s tables. %s error(s).%s',
            number_format_i18n( $run->count_get( 'applied_occurrences' ) ),
            number_format_i18n( $run->count_get( 'rows_updated' ) ),
            number_format_i18n( $run->count_get( 'tables_updated' ) ),
            number_format_i18n( $run->count_get( 'apply_errors' ) ),
            $run->get( 'partial' ) ? ' Stopped early (partial).' : ''
        ) );
        foreach ( array_slice( (array) $run->get( 'error_list', array() ), 0, 10 ) as $e ) {
            WP_CLI::log( sprintf( '  %s %s %s: %s', $e['table'], $this->pk_label( $e['pk'] ), $e['column'], $e['message'] ) );
        }
        if ( ! $run->config( 'no_journal' ) ) {
            $until = $run->undo_until();
            WP_CLI::log( sprintf( 'Undo with:  wp besr undo %s%s', $run->short(), $until ? '   (available until ' . $until . ' UTC)' : '' ) );
        }
        WP_CLI::log( 'Next: clear any page cache or CDN; run `wp rewrite flush`; check ' . ( $run->get( 'new_home' ) ?: home_url( '/' ) ) );
    }

    // ---- helpers ----

    /**
     * Resolve a run reference from the command line, or stop with an error.
     */
    private function load_run( string $ref ): BESR_Run {
        $run = '' !== $ref ? BESR_Run::load( $ref ) : null;
        if ( ! $run ) {
            WP_CLI::error( '' === $ref ? 'Give a run ID or UUID (see `wp besr runs`).' : 'Unknown run: ' . $ref );
        }
        return $run;
    }

    /**
     * Stop the command when a call returned a WP_Error.
     *
     * @param true|WP_Error $result Result of an engine call.
     */
    private function check( $result ): void {
        if ( is_wp_error( $result ) ) {
            if ( 'besr_busy' === $result->get_error_code() ) {
                WP_CLI::warning( $result->get_error_message() );
                WP_CLI::halt( 2 );
            }
            WP_CLI::error( $result->get_error_message() );
        }
    }

    private function budget( array $assoc ): float {
        return isset( $assoc['budget'] ) ? max( 0.1, (float) $assoc['budget'] ) : 60.0;
    }

    private function csv( string $s ): array {
        return array_values( array_filter( array_map( 'trim', explode( ',', $s ) ) ) );
    }

    private function plain( string $s ): string {
        return trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( str_replace( array( "\x01", "\x02" ), array( '‹', '›' ), $s ) ), ENT_QUOTES, 'UTF-8' ) ) );
    }

    private function truncate( string $s, int $len ): string {
        return mb_strlen( $s ) > $len ? mb_substr( $s, 0, $len - 1 ) . '…' : $s;
    }

    private function pk_label( $pk ): string {
        if ( ! is_array( $pk ) ) {
            return (string) $pk;
        }
        if ( isset( $pk['__row'] ) ) {
            return '(row)';
        }
        return '#' . implode( ',', array_map( 'strval', $pk ) );
    }
}
