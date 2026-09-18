/* global jQuery, BESR */
(function ($) {
    'use strict';

    var B = window.BESR;
    var i18n = B.i18n, esc = B.escapeHtml, fmt = B.fmt, sprintf = B.sprintf;
    var HARD = (B.data.flags && B.data.flags.hard) || [];

    var R = {
        runId: 0,
        summary: null,      // review_summary payload
        excluded: [],       // flags currently skipped
        filter: '',
        view: 'all',
        group: '',          // chip filter
        filterTimer: null,
        pages: {}           // table => current page
    };

    /* ------------------------------------------------------------------ */

    function autoIncluded(row) {
        // Occurrences that would be replaced under "auto" for the current exclusions.
        var n = 0, sets = row.flagsets || {};
        Object.keys(sets).forEach(function (set) {
            var flags = set === '' ? [] : set.split('|');
            if (flags.some(function (f) { return HARD.indexOf(f) !== -1; })) { return; }
            if (flags.some(function (f) { return R.excluded.indexOf(f) !== -1; })) { return; }
            n += sets[set];
        });
        return n;
    }

    function load() {
        return B.ajax('review_summary', { run_id: R.runId }).done(function (res) {
            if (!res.success) { B.notice(res.data && res.data.message ? res.data.message : i18n.unexpected); return; }
            applySummary(res.data);
            renderAll();
        }).fail(function (xhr) { B.notice(B.failMessage(xhr, i18n.requestFailed)); });
    }

    function applySummary(s) {
        R.summary = s;
        R.excluded = s.excluded || [];
        B.rememberLinks(R.runId, s.links);
    }

    function renderAll() {
        renderStrip();
        renderChips();
        renderWarnings();
        renderGroups();
        $('#besr-export-csv').attr('href', (R.summary.links && R.summary.links.export_csv) || '#');
        $('#besr-review-stale').prop('hidden', !R.summary.stale).text(R.summary.stale ? sprintf(i18n.staleScan, R.summary.age) : '');
    }

    function renderStrip() {
        var c = R.summary.counts || {};
        $('#besr-sum-matches').text(fmt(c.occurrences));
        $('#besr-sum-cells').text(fmt(c.cells));
        $('#besr-sum-tables').text(fmt(c.tables_matched) + ' ' + i18n.of + ' ' + fmt(c.tables_scanned));
        $('#besr-sum-effective').text(fmt(c.included));
        $('#besr-sum-skipped').text(fmt((c.occurrences || 0) - (c.included || 0)));
        $('#besr-apply-count').text(fmt(c.included));
        $('#besr-apply').prop('disabled', !(c.included > 0));
        $('#besr-review-empty').prop('hidden', (c.cells || 0) > 0);
    }

    function renderChips() {
        var html = '';
        (R.summary.groups || []).forEach(function (g) {
            html += '<button type="button" class="besr-chip' + (g.cells ? '' : ' is-empty') + (R.group === g.key ? ' is-on' : '') + '" data-group="' + esc(g.key) + '" aria-pressed="' + (R.group === g.key ? 'true' : 'false') + '">' + esc(g.label) + ' <b>' + fmt(g.occurrences) + '</b></button>';
        });
        $('#besr-group-chips').html(html);
    }

    function exampleHtml(ex) {
        var where = ex.where ? (ex.where.type ? ex.where.type + ' ' : '') + (ex.where.label || '') : '';
        return '<li><span class="besr-loc"><code>' + esc(ex.table_name) + '</code> · ' + esc(ex.column_name) + (where ? ' · ' + esc(where) : '') + '</span> ' +
            '<code class="besr-before">' + ex.before + '</code> <span class="besr-arrow" aria-hidden="true">→</span> <code class="besr-after">' + ex.after + '</code></li>';
    }

    function renderWarnings() {
        var s = R.summary, c = s.counts || {}, items = [], nWarn = 0;
        var flags = s.flags || {};
        var order = ['inside_email', 'longer_domain', 'inside_word', 'in_hash_like_string', 'in_serialized_key', 'case_differs', 'inside_serialized_class_name', 'inside_serialized_prop_name'];
        Object.keys(flags).sort(function (a, b) { return (order.indexOf(a) === -1 ? 99 : order.indexOf(a)) - (order.indexOf(b) === -1 ? 99 : order.indexOf(b)); }).forEach(function (flag) {
            var f = flags[flag];
            if (!f.count) { return; }
            var isInfo = flag === 'case_differs';
            if (!isInfo && !f.excluded) { nWarn++; }
            var icon = f.hard ? '🔒' : (isInfo ? 'ⓘ' : '⚠');
            var html = '<li class="besr-warning' + (f.excluded ? ' is-skipped' : '') + '" data-flag="' + esc(flag) + '">' +
                '<div class="besr-warning-head"><span class="besr-warning-icon" aria-hidden="true">' + icon + '</span>' +
                '<h3><span class="besr-count">' + fmt(f.count) + '</span> ' + esc(f.explain.title) + '</h3>';
            if (f.toggle) {
                html += '<label class="besr-skip-toggle"><input type="checkbox" class="besr-skip-cat" data-flag="' + esc(flag) + '"' + (f.excluded ? ' checked' : '') + ' /> ' + esc(sprintf(i18n.skipThese, fmt(f.count))) + '</label>';
            } else if (f.hard) {
                html += '<span class="besr-pill besr-pill-muted">' + esc(i18n.alwaysSkipped) + '</span>';
            }
            html += '</div><p class="besr-warning-text">' + esc(f.explain.text) + '</p>';
            if (f.examples && f.examples.length) {
                html += '<ul class="besr-examples">' + f.examples.map(exampleHtml).join('') + '</ul>';
            }
            html += '<button type="button" class="button-link besr-show-flag" data-flag="' + esc(flag) + '">' + esc(sprintf(i18n.showAll, fmt(f.count))) + '</button></li>';
            items.push(html);
        });
        if (c.errors) {
            items.push('<li class="besr-warning is-info" data-flag="errors"><div class="besr-warning-head"><span class="besr-warning-icon" aria-hidden="true">⚠</span><h3>' + esc(sprintf(i18n.errorsTitle, fmt(c.errors))) + '</h3></div><p class="besr-warning-text">' + esc(i18n.errorsText) + '</p><button type="button" class="button-link besr-show-view" data-view="errors">' + esc(i18n.showErrors) + '</button></li>');
        }
        if (c.deferred) {
            items.push('<li class="besr-warning is-info"><div class="besr-warning-head"><span class="besr-warning-icon" aria-hidden="true">ⓘ</span><h3>' + esc(i18n.deferredInfo) + '</h3></div><p class="besr-warning-text">' + esc(i18n.deferredText) + '</p></li>');
        }
        var skipped = s.skipped_tables || [];
        if (skipped.length) {
            var lis = skipped.map(function (t) {
                return '<li>' + esc(t.like_hits !== null && t.like_hits !== undefined ? sprintf(i18n.skippedTable, t.name, t.label, fmt(t.like_hits)) : sprintf(i18n.skippedTableNoCount, t.name, t.label)) + '</li>';
            }).join('');
            items.push('<li class="besr-warning is-info"><div class="besr-warning-head"><span class="besr-warning-icon" aria-hidden="true">ⓘ</span><h3>' + esc(sprintf(i18n.notSearchableTitle, fmt(skipped.length))) + '</h3></div><p class="besr-warning-text">' + esc(i18n.notSearchableText) + '</p><ul class="besr-plain-list">' + lis + '</ul></li>');
        }
        var cols = (s.excluded_columns || []).filter(function (c2) { return c2.like_hits === null || c2.like_hits === undefined || c2.like_hits > 0; });
        if (cols.length) {
            var lis2 = cols.map(function (c2) {
                return '<li>' + esc(c2.like_hits !== null && c2.like_hits !== undefined ? sprintf(i18n.excludedCols, c2.table, c2.column, c2.label, fmt(c2.like_hits)) : sprintf(i18n.excludedColsNoCount, c2.table, c2.column, c2.label)) + '</li>';
            }).join('');
            items.push('<li class="besr-warning is-info"><div class="besr-warning-head"><span class="besr-warning-icon" aria-hidden="true">ⓘ</span><h3>' + esc(sprintf(i18n.excludedColsTitle, fmt(cols.length))) + '</h3></div><p class="besr-warning-text">' + esc(i18n.excludedColsText) + '</p><ul class="besr-plain-list">' + lis2 + '</ul></li>');
        }
        if (c.skipped_transient) {
            items.push('<li class="besr-warning is-info"><div class="besr-warning-head"><span class="besr-warning-icon" aria-hidden="true">ⓘ</span><h3>' + esc(sprintf(i18n.transientsSkipped, fmt(c.skipped_transient))) + '</h3></div></li>');
        }
        (s.warnings || []).forEach(function (w) {
            items.push('<li class="besr-warning is-' + esc(w.level) + '"><div class="besr-warning-head"><span class="besr-warning-icon" aria-hidden="true">' + (w.level === 'info' ? 'ⓘ' : '⚠') + '</span><h3>' + esc(w.message) + '</h3></div></li>');
        });
        $('#besr-warnings-list').html(items.join(''));
        $('#besr-warnings-count').text(nWarn ? sprintf(i18n.warningsN, nWarn) : '');
        $('#besr-warnings').prop('hidden', !items.length);
    }

    /* ---- match browser ---- */

    function renderGroups() {
        var html = '', empties = [];
        (R.summary.groups || []).forEach(function (g) {
            if (R.group && R.group !== g.key) { return; }
            var tables = g.tables.filter(function (t) { return t.cells > 0; });
            g.tables.forEach(function (t) { if (!t.cells) { empties.push(t); } });
            if (!tables.length) { return; }
            var gid = 'besr-mgroup-' + g.key.replace(/[^a-z0-9_-]/gi, '-');
            html += '<section class="besr-mgroup" data-group="' + esc(g.key) + '"><button type="button" class="besr-mgroup-toggle" aria-expanded="true" aria-controls="' + gid + '">▾ ' + esc(g.label) + ' <span class="besr-muted">' + esc(sprintf(i18n.matchesIn, fmt(g.occurrences), fmt(g.cells))) + '</span></button><div id="' + gid + '">';
            tables.forEach(function (t, i) {
                var tid = 'besr-mtable-' + t.name.replace(/[^a-z0-9_-]/gi, '-');
                var open = i === 0;
                html += '<section class="besr-mtable" data-table="' + esc(t.name) + '"><button type="button" class="besr-mtable-toggle" aria-expanded="' + (open ? 'true' : 'false') + '" aria-controls="' + tid + '">' + (open ? '▾' : '▸') + ' <code>' + esc(t.name) + '</code> <span class="besr-muted">' + esc(sprintf(i18n.matchesIn, fmt(t.occurrences), fmt(t.cells))) + (t.plugin ? ' · ' + esc(t.plugin) : '') + '</span></button>' +
                    '<div id="' + tid + '" class="besr-mtable-body"' + (open ? '' : ' hidden') + ' data-loaded="0"></div></section>';
            });
            html += '</div></section>';
        });
        if (empties.length && !R.group) {
            html += '<details class="besr-empties"><summary>' + esc(sprintf(i18n.noMatchTables, fmt(empties.length))) + '</summary><ul class="besr-plain-list">' + empties.map(function (t) {
                return '<li><code>' + esc(t.name) + '</code>' + (t.status === 'skipped' ? ' <span class="besr-badge besr-badge-muted">' + esc(i18n.notSearchable) + (t.skip_label ? ' — ' + esc(t.skip_label) : '') + '</span>' : '') + '</li>';
            }).join('') + '</ul></details>';
        }
        $('#besr-matches').html(html);
        $('#besr-matches .besr-mtable-body:not([hidden])').each(function () { loadTable($(this).closest('.besr-mtable').data('table'), 1); });
    }

    function loadTable(table, page) {
        var $body = $('#besr-matches .besr-mtable[data-table="' + table + '"] .besr-mtable-body');
        if (!$body.length) { return; }
        R.pages[table] = page;
        $body.addClass('is-loading');
        var args = { run_id: R.runId, table: table, page: page, per_page: B.data.perPage || 50, q: R.filter, view: R.view, flag: R.flag || '' };
        B.ajax('review_matches', args).done(function (res) {
            $body.removeClass('is-loading').attr('data-loaded', '1');
            if (!res.success) { $body.html('<p class="besr-error-text">' + esc(res.data && res.data.message ? res.data.message : i18n.unexpected) + '</p>'); return; }
            $body.html(tableHtml(table, res.data));
        }).fail(function (xhr) { $body.removeClass('is-loading').html('<p class="besr-error-text">' + esc(B.failMessage(xhr, i18n.requestFailed)) + '</p>'); });
    }

    function rowHtml(r) {
        var willReplace = r.included > 0 && r.status === 'pending';
        var reason = '';
        if (r.status === 'error') { reason = '<span class="besr-flag besr-flag-error">' + esc(i18n.error) + ': ' + esc(r.note) + '</span>'; }
        else if (r.selection === 'skip') { reason = '<span class="besr-flag besr-flag-muted">' + esc(i18n.skipReason) + '</span>'; }
        else if (r.selection === 'include') { reason = '<span class="besr-flag besr-flag-force">' + esc(i18n.forced) + '</span>'; }
        else if (r.included === 0 && r.occurrences > 0) { reason = '<span class="besr-flag besr-flag-muted">' + esc(i18n.skipReason) + '</span>'; }
        var flags = (r.flags || []).map(function (f) { return '<span class="besr-flag besr-flag-' + esc(f) + '">' + esc(labelFor(f)) + '</span>'; }).join(' ');
        var where = r.where || {};
        var whereHtml = (where.type ? '<span class="besr-type">' + esc(where.type) + '</span> ' : '') + (where.url ? '<a href="' + esc(where.url) + '" target="_blank" rel="noopener">' + esc(where.label) + '</a>' : esc(where.label || ''));
        if (r.path) { whereHtml += '<br /><span class="besr-path">' + esc(r.path) + '</span>'; }
        var occ = r.occurrences > 1 ? '<span class="besr-occ">' + esc(sprintf(i18n.occ, fmt(r.occurrences))) + (r.included < r.occurrences && r.included > 0 ? ' · ' + esc(i18n.willReplace.toLowerCase()) + ' ' + fmt(r.included) : '') + '</span>' : '';
        return '<tr class="besr-match' + (willReplace ? '' : ' is-skipped') + '" data-id="' + r.id + '" data-selection="' + esc(r.selection) + '">' +
            '<th scope="row" class="check-column"><input type="checkbox" class="besr-row-cb"' + (willReplace ? ' checked' : '') + (r.status !== 'pending' ? ' disabled' : '') + ' aria-label="' + esc(i18n.replace + ' ' + (where.label || '') + ', ' + r.column_name) + '" /></th>' +
            '<td class="besr-where">' + whereHtml + ' ' + flags + ' ' + reason + '</td>' +
            '<td class="besr-col"><code>' + esc(r.column_name) + '</code>' + (r.encoding !== 'plain' ? '<br /><span class="besr-muted">' + esc(r.encoding) + '</span>' : '') + '</td>' +
            '<td class="besr-change"><code class="besr-before">' + r.before + '</code> <span class="besr-arrow" aria-hidden="true">→</span> <code class="besr-after">' + r.after + '</code> ' + occ +
            ' <button type="button" class="button-link besr-detail-toggle" aria-expanded="false">' + esc(i18n.details) + '</button></td></tr>' +
            '<tr class="besr-detail" hidden><td colspan="4"></td></tr>';
    }

    function labelFor(flag) {
        var f = R.summary.flags && R.summary.flags[flag];
        return f ? f.label : flag;
    }

    function tableHtml(table, data) {
        if (!data.rows.length) { return '<p class="besr-muted besr-pad">' + esc(i18n.noMatches) + '</p>'; }
        var html = '<table class="widefat striped besr-match-table"><thead><tr>' +
            '<th scope="col" class="check-column"><input type="checkbox" class="besr-page-cb" aria-label="' + esc(i18n.replace) + '" /></th>' +
            '<th scope="col">' + esc(i18n.where) + '</th><th scope="col">' + esc(i18n.column) + '</th><th scope="col">' + esc(i18n.change) + '</th></tr></thead><tbody>';
        data.rows.forEach(function (r) { html += rowHtml(r); });
        html += '</tbody></table>';
        if (data.pages > 1) {
            html += '<nav class="besr-pager" aria-label="' + esc(table) + '">';
            html += '<button type="button" class="button button-small besr-page-btn" data-page="' + (data.page - 1) + '"' + (data.page <= 1 ? ' disabled' : '') + '>‹ ' + esc(i18n.prev) + '</button> ';
            html += '<span>' + esc(sprintf(i18n.page, fmt(data.page), fmt(data.pages))) + '</span> ';
            html += '<button type="button" class="button button-small besr-page-btn" data-page="' + (data.page + 1) + '"' + (data.page >= data.pages ? ' disabled' : '') + '>' + esc(i18n.next) + ' ›</button></nav>';
        }
        return html;
    }

    function reloadLoaded() {
        $('#besr-matches .besr-mtable-body[data-loaded="1"]').each(function () {
            var t = $(this).closest('.besr-mtable').data('table');
            loadTable(t, R.pages[t] || 1);
        });
    }

    /* ---- actions ---- */

    function setExclusions() {
        var excluded = [];
        $('#besr-warnings-list .besr-skip-cat:checked').each(function () { excluded.push($(this).data('flag')); });
        $('#besr-warnings-list .besr-skip-cat').prop('disabled', true);
        B.ajax('set_exclusions', { run_id: R.runId, excluded: excluded.length ? excluded : [''] }).done(function (res) {
            if (!res.success) { B.notice(res.data && res.data.message ? res.data.message : i18n.unexpected); return; }
            applySummary(res.data);
            renderStrip();
            renderWarnings();
            renderChips();
            reloadLoaded();
        }).fail(function (xhr) { B.notice(B.failMessage(xhr, i18n.requestFailed)); $('#besr-warnings-list .besr-skip-cat').prop('disabled', false); });
    }

    function setSelection(ids, selection, $rows) {
        B.ajax('set_selection', { run_id: R.runId, ids: ids, selection: selection }).done(function (res) {
            if (!res.success) { B.notice(res.data && res.data.message ? res.data.message : i18n.unexpected); return; }
            R.summary.counts = res.data.counts;
            R.summary.flags = $.extend(R.summary.flags, res.data.flags);
            renderStrip();
            if ($rows) {
                var table = $rows.first().closest('.besr-mtable').data('table');
                loadTable(table, R.pages[table] || 1);
            }
        }).fail(function (xhr) { B.notice(B.failMessage(xhr, i18n.requestFailed)); });
    }

    function confirmApply() {
        var s = R.summary, c = s.counts || {}, run = s.run, flags = s.flags || {};
        var skipping = [], notSkipped = [];
        Object.keys(flags).forEach(function (k) {
            var f = flags[k];
            if (!f.count || k === 'case_differs') { return; }
            if (f.excluded) { skipping.push(fmt(f.count) + ' ' + f.label); }
            else if (f.toggle) { notSkipped.push(fmt(f.count) + ' ' + f.label); }
        });
        var groupsWithTables = (s.groups || []).filter(function (g) { return g.cells > 0; }).length;
        var body = '<table class="besr-confirm-table">' +
            '<tr><th>' + esc(i18n.searchFor) + '</th><td><code>' + esc(run.search) + '</code>' + (run.variants.length > 1 ? ' <span class="besr-muted">' + esc(sprintf(i18n.variantsN, run.variants.length - 1)) + '</span>' : '') + '</td></tr>' +
            '<tr><th>' + esc(i18n.replaceWith) + '</th><td><code>' + esc(run.replace === '' ? '(empty)' : run.replace) + '</code></td></tr>' +
            '<tr><th>' + esc(i18n.tablesLabel || i18n.tables) + '</th><td>' + esc(sprintf(i18n.tablesN, fmt(c.tables_matched), fmt(groupsWithTables))) + '</td></tr>';
        if (skipping.length || c.cells_skipped) {
            var parts = skipping.slice();
            if (c.cells_skipped) { parts.push(sprintf(i18n.individual, fmt(c.cells_skipped))); }
            body += '<tr><th>' + esc(i18n.skipping) + '</th><td>' + esc(parts.join(' · ')) + '</td></tr>';
        }
        body += '</table>';
        if (notSkipped.length) {
            body += '<p><strong>' + esc(i18n.notSkipped) + '</strong></p><ul class="besr-plain-list besr-warning-text">' + notSkipped.map(function (t) { return '<li>⚠ ' + esc(t) + '</li>'; }).join('') + '</ul>';
        }
        if (c.deferred && !run.keep_siteurl) {
            body += '<p class="besr-muted">ⓘ ' + esc(i18n.siteurlNote) + '</p><p><label><input type="checkbox" id="besr-keep-siteurl" /> ' + esc(i18n.keepSiteurl) + '</label></p>';
        }
        var days = B.data.settings.retentionDays;
        body += '<p class="besr-muted">ⓘ ' + esc(days > 0 ? sprintf(i18n.undoNote, days) : i18n.undoNoteForever) + (c.journal_estimate ? ' ' + esc(sprintf(i18n.journalEstimate, B.fmtBytes(c.journal_estimate))) : '') + '</p>';
        body += '<p class="besr-muted">' + esc(i18n.backupReminder) + '</p>';
        B.dialog.confirm({
            title: sprintf(i18n.confirmTitle, fmt(c.included)),
            body: body,
            okLabel: i18n.replaceNow,
            ack: notSkipped.length ? i18n.ack : null
        }).done(function (ok) {
            if (!ok) { return; }
            var keep = $('#besr-dialog #besr-keep-siteurl').is(':checked') ? 1 : 0;
            $('#besr-apply').prop('disabled', true);
            B.ajax('apply_start', { run_id: R.runId, stale: 'skip', keep_siteurl: keep }).done(function (res) {
                if (!res.success) { B.notice(res.data && res.data.message ? res.data.message : i18n.unexpected); $('#besr-apply').prop('disabled', false); return; }
                B.announce(i18n.replaceStarted);
                $('#besr-log').val('');
                B.state.logCount = 0;
                B.beginRun(res.data);
            }).fail(function (xhr) { B.notice(B.failMessage(xhr, i18n.requestFailed)); $('#besr-apply').prop('disabled', false); });
        });
    }

    function discard() {
        B.dialog.confirm({ title: i18n.discardTitle, body: '<p>' + esc(i18n.discardBody) + '</p>', okLabel: i18n.discard, danger: true }).done(function (ok) {
            if (!ok) { return; }
            B.ajax('delete_run', { run_id: R.runId }).done(function (res) {
                if (!res.success) { B.notice(res.data && res.data.message ? res.data.message : i18n.unexpected); return; }
                B.wizard.reset();
            }).fail(function (xhr) { B.notice(B.failMessage(xhr, i18n.requestFailed)); });
        });
    }

    function showDetail($btn) {
        var $row = $btn.closest('tr'), $detail = $row.next('.besr-detail'), $cell = $detail.find('td');
        var open = $detail.prop('hidden');
        $detail.prop('hidden', !open);
        $btn.attr('aria-expanded', open ? 'true' : 'false').text(open ? i18n.hideDetails : i18n.details);
        if (!open || $cell.data('loaded')) { return; }
        $cell.html('<span class="besr-spinner-inline"></span>');
        B.ajax('match_detail', { match_id: $row.data('id') }).done(function (res) {
            $cell.data('loaded', 1);
            if (!res.success) { $cell.html('<p class="besr-error-text">' + esc(res.data && res.data.message ? res.data.message : i18n.unexpected) + '</p>'); return; }
            var d = res.data, html = '';
            if (d.cell_changed) { html += '<p class="besr-warning-text">⚠ ' + esc(i18n.cellChanged) + '</p>'; }
            if (d.error) { html += '<p class="besr-error-text">' + esc(d.error) + '</p>'; }
            html += '<ol class="besr-occurrences">' + (d.occurrences || []).map(function (o) {
                return '<li class="' + (o.included ? 'is-included' : 'is-excluded') + '"><code class="besr-before">' + o.before + '</code> <span class="besr-arrow" aria-hidden="true">→</span> <code class="besr-after">' + o.after + '</code> ' +
                    (o.path ? '<span class="besr-path">' + esc(o.path) + '</span> ' : '') +
                    '<span class="besr-muted">' + esc(o.variant) + (o.encoding !== 'plain' ? '/' + esc(o.encoding) : '') + '</span> ' +
                    (o.labels || []).map(function (l) { return '<span class="besr-flag">' + esc(l) + '</span>'; }).join(' ') +
                    (o.included ? '' : ' <span class="besr-flag besr-flag-muted">' + esc(i18n.skipReason) + '</span>') + '</li>';
            }).join('') + '</ol>';
            $cell.html(html);
        }).fail(function (xhr) { $cell.html('<p class="besr-error-text">' + esc(B.failMessage(xhr, i18n.requestFailed)) + '</p>'); });
    }

    /* ------------------------------------------------------------------ */

    function bind() {
        var $m = $('#besr-matches');
        $('#besr-warnings-list').on('change', '.besr-skip-cat', setExclusions);
        $('#besr-warnings-list').on('click', '.besr-show-flag', function () {
            R.view = 'flagged'; R.filter = ''; R.flag = $(this).data('flag');
            $('#besr-match-filter').val('');
            $('input[name="besr-view"][value="flagged"]').prop('checked', true);
            expandAll();
        });
        $('#besr-warnings-list').on('click', '.besr-show-view', function () {
            R.view = $(this).data('view');
            $('input[name="besr-view"][value="' + R.view + '"]').prop('checked', true);
            expandAll();
        });
        $('#besr-group-chips').on('click', '.besr-chip', function () {
            var g = $(this).data('group');
            R.group = R.group === g ? '' : g;
            renderChips();
            renderGroups();
        });
        $m.on('click', '.besr-mgroup-toggle, .besr-mtable-toggle', function () {
            var $btn = $(this), $target = $('#' + $btn.attr('aria-controls'));
            var open = $target.prop('hidden');
            $target.prop('hidden', !open);
            $btn.attr('aria-expanded', open ? 'true' : 'false');
            $btn.html($btn.html().replace(/^[▾▸]/, open ? '▾' : '▸'));
            if (open && $target.hasClass('besr-mtable-body') && $target.attr('data-loaded') !== '1') {
                loadTable($btn.closest('.besr-mtable').data('table'), 1);
            }
        });
        $m.on('click', '.besr-page-btn', function () {
            loadTable($(this).closest('.besr-mtable').data('table'), parseInt($(this).data('page'), 10));
        });
        $m.on('change', '.besr-row-cb', function () {
            var $row = $(this).closest('tr'), id = $row.data('id');
            var selection;
            if (this.checked) {
                // Re-include: "auto" if the categories allow it, otherwise force.
                var rowData = $row.data('row');
                selection = rowData && autoIncluded(rowData) === 0 ? 'include' : 'auto';
            } else {
                selection = 'skip';
            }
            setSelection([id], selection, $row);
        });
        $m.on('change', '.besr-page-cb', function () {
            var $table = $(this).closest('table'), ids = [];
            $table.find('.besr-row-cb:not(:disabled)').each(function () { ids.push($(this).closest('tr').data('id')); });
            if (ids.length) { setSelection(ids, this.checked ? 'auto' : 'skip', $table.find('.besr-match').first()); }
        });
        $m.on('click', '.besr-detail-toggle', function () { showDetail($(this)); });
        $('#besr-match-filter').on('input', function () {
            clearTimeout(R.filterTimer);
            var v = this.value;
            R.filterTimer = setTimeout(function () { R.filter = v; R.flag = ''; reloadLoaded(); }, 400);
        });
        $('input[name="besr-view"]').on('change', function () { R.view = this.value; R.flag = ''; reloadLoaded(); });
        $('#besr-apply').on('click', confirmApply);
        $('#besr-discard').on('click', discard);
        $('#besr-back').on('click', function () {
            try { window.sessionStorage.setItem('besr_last', JSON.stringify({ search: R.summary.run.search, replace: R.summary.run.replace })); } catch (e) { /* ignore */ }
            B.wizard.reset();
        });
    }

    function expandAll() {
        $('#besr-matches .besr-mtable-body').prop('hidden', false).each(function () {
            $(this).closest('.besr-mtable').find('.besr-mtable-toggle').attr('aria-expanded', 'true');
            loadTable($(this).closest('.besr-mtable').data('table'), 1);
        });
    }

    // keep row data for the checkbox logic
    var origTableHtml = tableHtml;
    tableHtml = function (table, data) {
        var html = origTableHtml(table, data);
        setTimeout(function () {
            var $body = $('#besr-matches .besr-mtable[data-table="' + table + '"] .besr-mtable-body');
            data.rows.forEach(function (r) { $body.find('tr.besr-match[data-id="' + r.id + '"]').data('row', r); });
        }, 0);
        return html;
    };

    var bound = false;
    B.review = {
        mount: function (runId) {
            R.runId = runId;
            R.group = '';
            R.filter = '';
            R.view = 'all';
            R.pages = {};
            $('#besr-match-filter').val('');
            $('input[name="besr-view"][value="all"]').prop('checked', true);
            $('#besr-matches').empty();
            if (!bound) { bind(); bound = true; }
            load();
        },
        reload: load
    };
})(jQuery);
