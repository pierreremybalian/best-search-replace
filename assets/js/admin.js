/* global besrData, jQuery */
(function ($) {
    'use strict';

    var D = window.besrData || {};
    var i18n = D.i18n || {};

    var state = {
        run: null,          // last run summary payload
        payload: null,      // last full response
        logCount: 0,
        running: false,
        retries: 0,
        timer: null,
        analyzeTimer: null,
        analysisOk: false,
        analysis: null,
        mode: 'scan'        // scan|apply|undo (what the progress card shows)
    };

    /* ------------------------------------------------------------------ */
    /* Shared helpers, exposed for review.js                              */
    /* ------------------------------------------------------------------ */

    function escapeHtml(s) {
        return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function sprintf(str) {
        var args = Array.prototype.slice.call(arguments, 1), n = 0;
        return String(str).replace(/%(\d+\$)?[sd]/g, function (m, pos) {
            var idx = pos ? parseInt(pos, 10) - 1 : n++;
            return args[idx] === undefined ? '' : args[idx];
        });
    }

    function fmt(n) {
        n = Number(n || 0);
        return isNaN(n) ? '0' : n.toLocaleString();
    }

    function fmtBytes(b) {
        b = Number(b || 0);
        if (b < 1024) { return b + ' B'; }
        var u = ['KB', 'MB', 'GB', 'TB'], i = -1;
        do { b /= 1024; i++; } while (b >= 1024 && i < u.length - 1);
        return (b >= 10 ? Math.round(b) : b.toFixed(1)) + ' ' + u[i];
    }

    function ajax(action, data) {
        data = data || {};
        data.action = 'besr_' + action;
        data._ajax_nonce = D.nonce;
        return $.post(D.ajaxUrl, data);
    }

    function failMessage(xhr, fallback) {
        if (xhr && xhr.status === 403) {
            return i18n.sessionExpired;
        }
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            return xhr.responseJSON.data.message;
        }
        if (xhr && xhr.responseText) {
            var text = $('<div>').html(xhr.responseText).text().replace(/\s+/g, ' ').trim();
            if (text) { return text.substring(0, 300); }
        }
        return fallback;
    }

    function announce(text) {
        $('#besr-status').text(text);
    }

    function notice(text, type) {
        var $n = $('#besr-notice');
        if (!text) { $n.prop('hidden', true); return; }
        $n.attr('class', 'notice inline besr-notice notice-' + (type || 'error')).prop('hidden', false).find('p').text(text);
    }

    function focusTitle($section) {
        var $h = $section.find('.besr-step-title').first();
        if ($h.length) {
            $h.attr('tabindex', '-1').trigger('focus');
            if ($h[0].scrollIntoView) { $h[0].scrollIntoView({ block: 'start', behavior: 'smooth' }); }
        }
    }

    /* Dialog: confirm({title, body(html), okLabel, danger, ack(text), onOpen}) -> Promise<bool> */
    var dialog = (function () {
        var $d, resolveFn;
        function el() { if (!$d) { $d = $('#besr-dialog'); } return $d; }
        function close(result) {
            if ($d && $d[0] && $d[0].open) { $d[0].close(); }
            if (resolveFn) { var r = resolveFn; resolveFn = null; r(result); }
        }
        function confirm(opts) {
            var $dlg = el();
            if (!$dlg.length || !window.HTMLDialogElement || !$dlg[0].showModal) {
                var text = $('<div>').html(opts.body || '').text();
                return $.Deferred().resolve(window.confirm((opts.title || '') + '\n\n' + text)).promise();
            }
            var dfd = $.Deferred();
            resolveFn = dfd.resolve;
            $dlg.find('#besr-dialog-title').text(opts.title || '');
            $dlg.find('#besr-dialog-body').html(opts.body || '');
            var $ok = $dlg.find('#besr-dialog-ok').text(opts.okLabel || i18n.ok).toggleClass('besr-danger', !!opts.danger);
            var $ackWrap = $dlg.find('#besr-dialog-ack-wrap');
            var $ack = $dlg.find('#besr-dialog-ack').prop('checked', false);
            if (opts.ack) {
                $ackWrap.prop('hidden', false);
                $dlg.find('#besr-dialog-ack-text').text(opts.ack);
                $ok.prop('disabled', true);
            } else {
                $ackWrap.prop('hidden', true);
                $ok.prop('disabled', false);
            }
            $dlg.find('#besr-dialog-cancel').toggle(opts.cancel !== false);
            $dlg[0].showModal();
            if (opts.onOpen) { opts.onOpen($dlg); }
            return dfd.promise();
        }
        $(function () {
            var $dlg = el();
            if (!$dlg.length) { return; }
            $dlg.on('change', '#besr-dialog-ack', function () { $dlg.find('#besr-dialog-ok').prop('disabled', !this.checked); });
            $dlg.on('click', '#besr-dialog-ok', function () { close(true); });
            $dlg.on('click', '#besr-dialog-cancel', function () { close(false); });
            $dlg.on('cancel', function (e) { e.preventDefault(); close(false); });
            $dlg.on('close', function () { if (resolveFn) { close(false); } });
        });
        return { confirm: confirm, close: close, el: el };
    })();

    var BESR = window.BESR = {
        data: D, i18n: i18n, state: state,
        ajax: ajax, escapeHtml: escapeHtml, sprintf: sprintf, fmt: fmt, fmtBytes: fmtBytes,
        failMessage: failMessage, announce: announce, notice: notice, dialog: dialog, focusTitle: focusTitle
    };

    /* ------------------------------------------------------------------ */
    /* Stages                                                             */
    /* ------------------------------------------------------------------ */

    var STEP_INDEX = { what: 0, where: 1, scan: 2, review: 3, replace: 4 };

    function setStep(step, done) {
        var idx = STEP_INDEX[step];
        $('#besr-steps li').each(function (i) {
            var $li = $(this);
            $li.removeAttr('aria-current').toggleClass('is-done', i < idx || (done && i <= idx)).toggleClass('is-current', i === idx && !done);
            if (i === idx && !done) { $li.attr('aria-current', 'step'); }
        });
    }

    function showStage(stage) {
        $('#besr-wizard').prop('hidden', stage !== 'wizard');
        $('#besr-summary-bar').prop('hidden', stage === 'wizard');
        $('#besr-progress').prop('hidden', stage !== 'progress');
        $('#besr-review').prop('hidden', stage !== 'review');
        $('#besr-review-actions').prop('hidden', stage !== 'review');
        $('#besr-result').prop('hidden', stage !== 'result');
    }

    function renderSummaryBar(run) {
        if (!run) { return; }
        $('#besr-bar-search').text(run.search);
        $('#besr-bar-replace').text(run.replace === '' ? '(empty)' : run.replace);
        var extra = (run.variants || []).length - 1;
        $('#besr-bar-variants').prop('hidden', extra <= 0).text(sprintf(i18n.variantsN, extra));
        $('#besr-bar-case').prop('hidden', !run.case_insensitive);
        var meta = fmt((run.tables || []).length) + ' ' + i18n.tables;
        if (run.user) { meta += ' · ' + run.user; }
        $('#besr-bar-meta').text(meta);
    }

    BESR.showStage = showStage;
    BESR.setStep = setStep;

    /** Route a payload to the right stage. */
    function render(payload) {
        if (!payload || !payload.run) { return; }
        state.payload = payload;
        state.run = payload.run;
        renderSummaryBar(payload.run);
        var s = payload.status;

        if (s === 'scanning' || s === 'applying' || s === 'undoing') {
            state.mode = s === 'scanning' ? 'scan' : (s === 'applying' ? 'apply' : 'undo');
            setStep(state.mode === 'scan' ? 'scan' : 'replace');
            showStage('progress');
            renderProgress(payload);
            if (!state.running) { state.running = true; state.retries = 0; tick(); }
            return;
        }
        state.running = false;
        if (s === 'scanned') {
            setStep('review');
            showStage('review');
            if (window.BESR.review) { window.BESR.review.mount(payload.run.id, payload); }
            return;
        }
        if (s === 'error') {
            state.mode = payload.stage === 'scan' ? 'scan' : (payload.stage === 'apply' ? 'apply' : 'undo');
            setStep(state.mode === 'scan' ? 'scan' : 'replace');
            showStage('progress');
            renderProgress(payload);
            return;
        }
        setStep('replace', s !== 'cancelled');
        showStage('result');
        renderResult(payload);
    }
    BESR.render = render;

    /* ------------------------------------------------------------------ */
    /* Progress                                                           */
    /* ------------------------------------------------------------------ */

    function appendLog(lines, $target) {
        if (!lines || !lines.length) { return; }
        $target = $target || $('#besr-log');
        var text = lines.map(function (l) { return '[' + l.t + '] ' + l.m; }).join('\n');
        $target.val(($target.val() ? $target.val() + '\n' : '') + text);
        $target.scrollTop($target[0].scrollHeight);
    }

    function renderProgress(p) {
        var $card = $('#besr-progress');
        var running = p.status === 'scanning' || p.status === 'applying' || p.status === 'undoing';
        $card.attr('class', 'besr-card besr-progress besr-stage is-' + state.mode + (p.status === 'error' ? ' is-error' : ''));
        var title = p.status === 'error' ? i18n.failed : (state.mode === 'scan' ? i18n.scanning : (state.mode === 'apply' ? i18n.replacing : i18n.undoing));
        $('#besr-progress-title').text(title);
        $('#besr-bar').css('width', p.progress + '%');
        $('#besr-bar-wrap').attr('aria-valuenow', p.progress).attr('aria-valuetext', p.progress + '%, ' + p.message);
        $('#besr-percent').text(p.progress + '%');
        $('#besr-phase').text(p.busy ? i18n.busy : p.message);

        $('#besr-error').prop('hidden', p.status !== 'error');
        if (p.status === 'error') { $('#besr-error-text').text(p.message || p.run.error); }
        $('#besr-progress-stop').prop('hidden', !running && !(p.status === 'error' && p.stage !== 'undo')).text(state.mode === 'scan' ? i18n.stopScan : (state.mode === 'apply' ? i18n.stopReplace : i18n.confirmStop)).prop('disabled', state.mode === 'undo');
        $('#besr-progress-retry').prop('hidden', p.status !== 'error');
        $('#besr-progress-back').prop('hidden', p.status !== 'error');
        $('#besr-progress-reload').prop('hidden', true);

        // per-table live counters
        var $tbody = $('#besr-progress-tables');
        var $wrap = $('#besr-progress-tables-wrap');
        $wrap.find('[data-col="scanned"]').toggle(state.mode === 'scan');
        $wrap.find('[data-col="updated"]').toggle(state.mode !== 'scan');
        $wrap.find('[data-col="errors"]').toggle(state.mode !== 'scan');
        var names = Object.keys(p.tables || {}).sort();
        if (!names.length) { $wrap.prop('hidden', true); }
        else {
            $wrap.prop('hidden', false);
            var html = '';
            names.forEach(function (name) {
                var t = p.tables[name];
                var status = t.status === 'skipped' ? '<span class="besr-badge besr-badge-muted">' + escapeHtml(i18n.notSearchable) + '</span>' : (t.status === 'done' ? '<span class="besr-ok" aria-label="done">✓</span>' : (t.status === 'scanning' ? '<span class="besr-spinner-inline"></span>' : ''));
                html += '<tr class="is-' + escapeHtml(t.status) + '"><td><code>' + escapeHtml(name) + '</code> ' + status + '</td>';
                if (state.mode === 'scan') {
                    html += '<td class="besr-num">' + (t.status === 'pending' ? '–' : fmt(t.scanned)) + ' / ' + fmt(t.rows) + '</td>';
                    html += '<td class="besr-num">' + (t.status === 'pending' ? '–' : fmt(t.occurrences)) + '</td><td class="besr-num" style="display:none"></td><td class="besr-num" style="display:none"></td>';
                } else {
                    html += '<td class="besr-num" style="display:none"></td><td class="besr-num">' + fmt(t.occurrences) + '</td><td class="besr-num">' + fmt(t.updated) + '</td><td class="besr-num">' + (t.errors ? '<span class="besr-danger-text">' + fmt(t.errors) + '</span>' : '0') + '</td>';
                }
                html += '</tr>';
            });
            $tbody.html(html);
        }
        $('#besr-log-count').text(p.log_count ? '(' + p.log_count + ')' : '');
    }

    function tick() {
        if (!state.run || !state.running) { return; }
        ajax('tick', { run_id: state.run.id, log_from: state.logCount }).done(function (res) {
            if (!res.success) {
                state.running = false;
                $('#besr-error').prop('hidden', false);
                $('#besr-error-text').text(res.data && res.data.message ? res.data.message : i18n.unexpected);
                $('#besr-progress-retry').prop('hidden', false);
                return;
            }
            state.retries = 0;
            var p = res.data;
            appendLog(p.log);
            state.logCount = p.log_count;
            if (p.status === 'scanning' || p.status === 'applying' || p.status === 'undoing') {
                state.payload = p;
                state.run = p.run;
                renderProgress(p);
                var pct = p.progress;
                if (pct % 25 === 0 && pct !== state.lastAnnounced) { state.lastAnnounced = pct; announce(p.message); }
                state.timer = setTimeout(tick, p.busy ? 1500 : 60);
            } else {
                state.running = false;
                if (p.status === 'scanned') {
                    announce(sprintf(i18n.scanFinished, fmt(p.counts.occurrences), fmt(p.summary && p.summary.counts ? p.summary.counts.tables_matched : 0)));
                }
                render(p);
                focusTitle(p.status === 'scanned' ? $('#besr-review') : (p.status === 'error' ? $('#besr-progress') : $('#besr-result')));
            }
        }).fail(function (xhr) {
            if (xhr && xhr.status === 403) {
                state.running = false;
                $('#besr-error').prop('hidden', false);
                $('#besr-error-text').text(i18n.sessionExpired);
                $('#besr-progress-reload').prop('hidden', false);
                return;
            }
            // The last apply tick may fail because the site address just changed.
            if (state.mode === 'apply' && state.payload && (state.payload.phase === 'apply_deferred' || state.payload.phase === 'apply_finalize') && state.retries >= 2) {
                state.running = false;
                var home = (state.payload.links && state.payload.links.home_after) || '';
                showStage('result');
                $('#besr-result-title').text(i18n.siteurlLost);
                $('#besr-result-notes').html(home ? '<li><a class="button button-primary" href="' + escapeHtml(home.replace(/\/$/, '') + '/wp-admin/tools.php?page=best-search-replace&run=' + state.run.id) + '">' + escapeHtml(i18n.openNewDashboard) + '</a></li>' : '');
                return;
            }
            state.retries++;
            if (state.retries <= 5) {
                appendLog([{ t: '--', m: i18n.networkError + ' (' + state.retries + '/5)' }]);
                state.timer = setTimeout(tick, Math.min(15000, 1000 * Math.pow(2, state.retries - 1)));
                return;
            }
            state.running = false;
            $('#besr-error').prop('hidden', false);
            $('#besr-error-text').text(failMessage(xhr, i18n.gaveUp));
            $('#besr-progress-retry').prop('hidden', false);
        });
    }

    function beginRun(payload) {
        state.logCount = 0;
        state.retries = 0;
        $('#besr-log').val('');
        appendLog(payload.log);
        state.logCount = payload.log_count;
        if (window.history && window.history.replaceState && payload.run) {
            var url = D.pageUrl + '&run=' + payload.run.id;
            window.history.replaceState(null, '', url);
        }
        render(payload);
        focusTitle($('#besr-progress'));
    }
    BESR.beginRun = beginRun;

    function resumeTick() {
        state.retries = 0;
        state.running = true;
        $('#besr-error').prop('hidden', true);
        tick();
    }

    function doAction(action, extra) {
        clearTimeout(state.timer);
        state.running = false;
        return ajax(action, $.extend({ run_id: state.run.id, log_from: state.logCount }, extra || {})).done(function (res) {
            if (!res.success) {
                $('#besr-error').prop('hidden', false);
                $('#besr-error-text').text(res.data && res.data.message ? res.data.message : i18n.unexpected);
                return;
            }
            appendLog(res.data.log);
            state.logCount = res.data.log_count;
            render(res.data);
        }).fail(function (xhr) {
            $('#besr-error').prop('hidden', false);
            $('#besr-error-text').text(failMessage(xhr, i18n.requestFailed));
        });
    }
    BESR.doAction = doAction;

    /* ------------------------------------------------------------------ */
    /* Result                                                             */
    /* ------------------------------------------------------------------ */

    function renderResult(p) {
        var run = p.run, c = p.counts || {}, s = p.status;
        var $card = $('#besr-result').attr('class', 'besr-card besr-result besr-stage is-' + s);
        var title, notes = [];
        if (s === 'applied') {
            title = sprintf(run.partial ? i18n.donePartial : i18n.done, fmt(c.applied_occurrences), fmt(c.rows_updated), fmt(c.tables_updated));
            if (c.stale) { notes.push(sprintf(i18n.staleSkipped, fmt(c.stale))); }
            if (c.apply_skipped) { notes.push(sprintf(i18n.rowsSkipped, fmt(c.apply_skipped))); }
            notes.push(c.apply_errors ? sprintf(i18n.errorsN, fmt(c.apply_errors)) : i18n.noErrors);
        } else if (s === 'undone') {
            title = sprintf(i18n.undone, fmt(c.reverted), fmt(c.conflicts));
            if (c.conflicts) { notes.push(sprintf(i18n.conflictsN, fmt(c.conflicts))); }
            if (c.undo_errors) { notes.push(sprintf(i18n.errorsN, fmt(c.undo_errors))); }
        } else {
            title = i18n.cancelled;
        }
        $('#besr-result-title').text(title);
        $('#besr-result-notes').html(notes.map(function (n) { return '<li>' + escapeHtml(n) + '</li>'; }).join(''));

        // errors
        var errs = p.errors || [];
        $('#besr-result-errors').prop('hidden', !errs.length);
        if (errs.length) {
            $('#besr-result-errors-title').text(sprintf(i18n.errorsN, fmt(errs.length)));
            $('#besr-result-errors-list').html(errs.slice(0, 50).map(function (e) {
                return '<li><code>' + escapeHtml(e.table) + '</code> ' + escapeHtml(JSON.stringify(e.pk)) + ' (' + escapeHtml(e.column) + '): ' + escapeHtml(e.message) + '</li>';
            }).join(''));
        }

        // per-table
        var names = Object.keys(p.tables || {}).filter(function (n) { return p.tables[n].updated || p.tables[n].errors; }).sort();
        $('#besr-result-tables-wrap').prop('hidden', !names.length || s !== 'applied');
        $('#besr-result-tables').html(names.map(function (n) {
            var t = p.tables[n];
            return '<tr><td><code>' + escapeHtml(n) + '</code></td><td class="besr-num">' + fmt(t.updated) + '</td><td class="besr-num">' + fmt(t.occurrences) + '</td><td class="besr-num">' + fmt(t.errors) + '</td></tr>';
        }).join(''));

        // next steps
        var next = [];
        if (s === 'applied' || s === 'undone') {
            next.push('<li>' + escapeHtml(i18n.cacheFlushed) + '</li><li>' + escapeHtml(i18n.pageCache) + '</li>');
            if (p.links && p.links.home_after) {
                next.push('<li><strong>' + escapeHtml(sprintf(i18n.siteurlChanged, p.links.home_after)) + '</strong> <a class="button button-primary" href="' + escapeHtml(p.links.admin_after) + '">' + escapeHtml(i18n.openNewDashboard) + '</a></li>');
            }
            next.push('<li><a href="' + escapeHtml(D.links.permalinks) + '">' + escapeHtml(i18n.savePermalinks) + '</a> <span class="besr-muted">' + escapeHtml(i18n.permalinksHint) + '</span></li>');
            next.push('<li><a href="' + escapeHtml((p.links && p.links.home_after) || D.links.home) + '" target="_blank" rel="noopener">' + escapeHtml(i18n.openHomepage) + ' ↗</a></li>');
        }
        $('#besr-result-next').prop('hidden', !next.length);
        $('#besr-result-next-list').html(next.join(''));

        // actions
        var actions = '';
        if (s === 'applied' && !run.journal_expired) {
            actions += '<button type="button" class="button besr-danger-link" id="besr-undo">' + escapeHtml(i18n.undoThisRun) + '</button> ';
            var undoUrl = exportUrl(run.id, 'export_undo');
            if (undoUrl) { actions += '<a class="button" href="' + escapeHtml(undoUrl) + '">' + escapeHtml(i18n.downloadUndo) + '</a> '; }
        }
        if (s === 'undone') {
            actions += '<button type="button" class="button" id="besr-reapply">' + escapeHtml(i18n.reapply) + '</button> ';
        }
        actions += '<a class="button" href="' + escapeHtml(D.links.history) + '">' + escapeHtml(i18n.viewHistory) + '</a> ';
        actions += '<button type="button" class="button button-primary" id="besr-new-search">' + escapeHtml(i18n.newSearch) + '</button>';
        $('#besr-result-actions').html(actions);
        $('#besr-undo-until').text(s === 'applied' && run.undo_until ? sprintf(i18n.undoUntil, run.undo_until) : (s === 'applied' && !run.journal_expired ? i18n.undoNoteForever : ''));

        var $log = $('#besr-result-log');
        if (!$log.val()) { $log.val($('#besr-log').val()); }
        appendLog(p.log_count && state.logCount < p.log_count ? p.log : [], $log);
    }

    // Download URLs carry a per-run nonce only the server can make: the page localizes them for the
    // requested run, and review_summary supplies them for runs started in this session.
    function exportUrl(runId, kind) {
        state.exportLinks = state.exportLinks || {};
        if (state.exportLinks[runId] && state.exportLinks[runId][kind]) { return state.exportLinks[runId][kind]; }
        if (D.runDownloads && D.run && D.run.run && D.run.run.id === runId && D.runDownloads[kind]) { return D.runDownloads[kind]; }
        return '';
    }
    BESR.rememberLinks = function (runId, links) {
        state.exportLinks = state.exportLinks || {};
        state.exportLinks[runId] = links || {};
    };
    BESR.exportUrl = exportUrl;

    function startUndo() {
        var run = state.run;
        ajax('undo_preview', { run_id: run.id }).done(function (res) {
            if (!res.success) { notice(res.data && res.data.message ? res.data.message : i18n.unexpected); return; }
            var pv = res.data;
            var body = '<p>' + escapeHtml(sprintf(i18n.undoBody, fmt(pv.total), fmt(pv.tables), run.applied_at || '')) + '</p>';
            if (pv.changed) {
                body += '<p class="besr-warning-text">⚠ ' + escapeHtml(sprintf(i18n.undoChanged, fmt(pv.changed)));
                if (pv.checked < pv.total) { body += ' ' + escapeHtml(sprintf(i18n.undoChangedPartial, fmt(pv.checked))); }
                body += '</p><p><label><input type="radio" name="besr-undo-mode" value="0" checked /> ' + escapeHtml(i18n.undoLeave) + '</label><br /><label><input type="radio" name="besr-undo-mode" value="1" /> ' + escapeHtml(i18n.undoForce) + '</label></p>';
            }
            if (pv.overlap_runs) { body += '<p class="besr-warning-text">⚠ ' + escapeHtml(sprintf(i18n.undoOverlap, fmt(pv.overlap_runs))) + '</p>'; }
            if (state.payload && state.payload.counts && state.payload.counts.deferred) { body += '<p class="besr-muted">ⓘ ' + escapeHtml(i18n.undoSiteurl) + '</p>'; }
            dialog.confirm({ title: i18n.undoTitle, body: body, okLabel: i18n.undoRun, danger: true }).done(function (ok) {
                if (!ok) { return; }
                var force = $('#besr-dialog input[name="besr-undo-mode"]:checked').val() === '1' ? 1 : 0;
                ajax('undo_start', { run_id: run.id, force: force }).done(function (r2) {
                    if (!r2.success) { notice(r2.data && r2.data.message ? r2.data.message : i18n.unexpected); return; }
                    $('#besr-log').val('');
                    state.logCount = 0;
                    beginRun(r2.data);
                }).fail(function (xhr) { notice(failMessage(xhr, i18n.requestFailed)); });
            });
        }).fail(function (xhr) { notice(failMessage(xhr, i18n.requestFailed)); });
    }
    BESR.startUndo = startUndo;

    /* ------------------------------------------------------------------ */
    /* Wizard                                                             */
    /* ------------------------------------------------------------------ */

    var wizard = {};

    wizard.analyze = function () {
        clearTimeout(state.analyzeTimer);
        var search = $('#besr-search').val();
        if (!search) {
            state.analysisOk = false;
            $('#besr-analysis').prop('hidden', true);
            wizard.lock(true);
            wizard.updateScan();
            return;
        }
        $('#besr-analysis').prop('hidden', false);
        $('#besr-analysis-text').text(i18n.checking);
        state.analyzeTimer = setTimeout(wizard.runAnalyze, 350);
    };

    wizard.checkedVariants = function () {
        var on = [];
        $('#besr-variants input:checked').each(function () { on.push(this.value); });
        return on;
    };

    wizard.runAnalyze = function () {
        var search = $('#besr-search').val(), replace = $('#besr-replace').val();
        var on = state.analysis ? wizard.checkedVariants() : (D.settings.variants || []);
        ajax('analyze', { search: search, replace: replace, case_insensitive: $('#besr-case').is(':checked') ? 1 : 0, variants: on }).done(function (res) {
            if ($('#besr-search').val() !== search || $('#besr-replace').val() !== replace) { return; } // stale
            if (!res.success) { $('#besr-analysis-text').text(res.data && res.data.message ? res.data.message : i18n.analysisError); return; }
            var a = res.data;
            state.analysis = a;
            state.analysisOk = !!a.ok;
            $('#besr-analysis-type').text(a.label);
            var $v = $('#besr-variants');
            var keep = {};
            $v.find('input').each(function () { keep[this.value] = this.checked; });
            var multi = a.variants && a.variants.length > 1;
            $('#besr-analysis-text').text(multi ? i18n.variantsIntro : '');
            $v.prop('hidden', !multi).empty();
            if (multi) {
                a.variants.forEach(function (v) {
                    var checked = keep.hasOwnProperty(v.key) ? keep[v.key] : v.on;
                    if (v.key === 'exact') { checked = true; }
                    var id = 'besr-variant-' + v.key;
                    var html = '<label class="besr-variant' + (checked ? ' is-on' : '') + '" for="' + id + '">' +
                        '<input type="checkbox" id="' + id + '" value="' + escapeHtml(v.key) + '"' + (checked ? ' checked' : '') + (v.key === 'exact' ? ' disabled' : '') + ' />' +
                        '<code class="besr-variant-from">' + escapeHtml(v.search) + '</code> <span class="besr-arrow" aria-hidden="true">→</span> <code class="besr-variant-to">' + escapeHtml(v.replace === '' ? '(empty)' : v.replace) + '</code>' +
                        '<span class="besr-variant-note">' + escapeHtml(v.key === 'exact' ? i18n.exactNote : (v.label || v.note)) + '</span>' +
                        (v.key === 'bare' && v.note ? '<span class="besr-variant-help">' + escapeHtml(v.note) + '</span>' : '') +
                        '</label>';
                    $v.append(html);
                });
            }
            var $w = $('#besr-prewarnings').empty();
            (a.warnings || []).forEach(function (w) {
                $w.append('<li class="besr-prewarning is-' + escapeHtml(w.level) + '"><span class="dashicons dashicons-' + (w.level === 'info' ? 'info' : 'warning') + '" aria-hidden="true"></span> <strong>' + escapeHtml(w.level === 'info' ? '' : i18n.headsUp) + '</strong> ' + escapeHtml(w.message) + '</li>');
            });
            wizard.lock(!a.ok);
            wizard.updateScan();
        }).fail(function (xhr) {
            $('#besr-analysis-text').text(failMessage(xhr, i18n.analysisError));
        });
    };

    wizard.lock = function (locked) {
        $('#besr-step-where, #besr-step-scan').toggleClass('is-locked', locked).find('input,button').not('#besr-scan').prop('disabled', locked);
        if (!locked) { setStep('where'); } else { setStep('what'); }
    };

    wizard.tableBoxes = function ($scope) {
        return ($scope || $('#besr-groups')).find('input[name="tables[]"]');
    };

    wizard.syncGroup = function ($group) {
        var $boxes = wizard.tableBoxes($group).not(':disabled');
        var total = $boxes.length, on = $boxes.filter(':checked').length;
        var $cb = $group.find('.besr-group-cb');
        $cb.prop('checked', on > 0 && on === total);
        $cb.prop('indeterminate', on > 0 && on < total);
    };

    wizard.updateCounts = function () {
        var $all = wizard.tableBoxes().not(':disabled'), rows = 0, on = 0;
        $all.each(function () { if (this.checked) { on++; rows += parseInt($(this).data('rows'), 10) || 0; } });
        $('#besr-where-count').text(sprintf(i18n.selectedTables, fmt(on), fmt($all.length), fmt(rows)));
        $('#besr-groups .besr-group').each(function () { wizard.syncGroup($(this)); });
        wizard.updateScan();
    };

    wizard.updateScan = function () {
        var on = wizard.tableBoxes().filter(':checked').length;
        var blocked = '';
        if (D.activeRun && D.activeRun.run && (D.activeRun.status === 'scanning' || D.activeRun.status === 'applying' || D.activeRun.status === 'undoing')) { blocked = i18n.runActive; }
        else if (state.analysis && !state.analysisOk) { blocked = i18n.sameText; }
        else if (state.analysisOk && !on) { blocked = i18n.noTables; }
        $('#besr-scan-blocked').prop('hidden', !blocked || !state.analysisOk && !state.analysis).text(blocked);
        $('#besr-scan').prop('disabled', !(state.analysisOk && on > 0 && !blocked) || state.running);
        if (state.analysisOk && on > 0 && !blocked) { setStep('scan'); }
    };

    wizard.select = function (mode) {
        $('#besr-groups .besr-group').each(function () {
            var $g = $(this);
            var on = mode === 'all' || (mode === 'recommended' && $g.data('default') === 1);
            wizard.tableBoxes($g).not(':disabled').prop('checked', on);
        });
        wizard.updateCounts();
    };

    wizard.filter = function (q) {
        q = (q || '').toLowerCase();
        $('#besr-groups .besr-group').each(function () {
            var $g = $(this), any = false;
            var groupHit = $g.find('.besr-group-name').text().toLowerCase().indexOf(q) !== -1;
            $g.find('li[data-table]').each(function () {
                var hit = !q || groupHit || String($(this).data('table')).toLowerCase().indexOf(q) !== -1;
                $(this).prop('hidden', !hit);
                if (hit) { any = true; }
            });
            $g.find('.besr-subgroup').each(function () {
                $(this).prop('hidden', !$(this).find('li[data-table]:not([hidden])').length);
            });
            $g.prop('hidden', !any && !groupHit);
            if (q && any && !groupHit) { wizard.expand($g, true); }
        });
    };

    wizard.expand = function ($g, open) {
        var $btn = $g.find('.besr-group-toggle');
        var $list = $g.children('.besr-tables');
        if (open === undefined) { open = $list.prop('hidden'); }
        $list.prop('hidden', !open);
        $btn.attr('aria-expanded', open ? 'true' : 'false').text(open ? '▾' : '▸');
    };

    wizard.startScan = function () {
        if ($('#besr-scan').prop('disabled')) { return; }
        var tables = [];
        wizard.tableBoxes().filter(':checked').each(function () { tables.push(this.value); });
        var data = {
            search: $('#besr-search').val(),
            replace: $('#besr-replace').val(),
            case_insensitive: $('#besr-case').is(':checked') ? 1 : 0,
            variants: wizard.checkedVariants(),
            excluded_contexts: D.settings.excluded || [],
            tables: tables,
            include_guid: $('#besr-include-guid').is(':checked') ? 1 : 0,
            include_transients: $('#besr-include-transients').is(':checked') ? 1 : 0,
            include_identity: $('#besr-include-identity').is(':checked') ? 1 : 0,
            include_pkless: $('#besr-include-pkless').is(':checked') ? 1 : 0,
            include_global: $('#besr-include-global').is(':checked') ? 1 : 0
        };
        $('#besr-scan').prop('disabled', true);
        $('#besr-spinner').addClass('is-active');
        try { window.sessionStorage.setItem('besr_last', JSON.stringify({ search: data.search, replace: data.replace })); } catch (e) { /* ignore */ }
        ajax('scan_start', data).done(function (res) {
            $('#besr-spinner').removeClass('is-active');
            if (!res.success) {
                notice(res.data && res.data.message ? res.data.message : i18n.startError);
                if (res.data && res.data.run) { D.activeRun = { run: res.data.run, status: res.data.run.status }; showResumeBanner(D.activeRun); }
                wizard.updateScan();
                return;
            }
            notice('');
            announce(i18n.scanStarted);
            beginRun(res.data);
        }).fail(function (xhr) {
            $('#besr-spinner').removeClass('is-active');
            notice(failMessage(xhr, i18n.startError));
            wizard.updateScan();
        });
    };

    wizard.reset = function () {
        state.run = null;
        state.payload = null;
        state.running = false;
        clearTimeout(state.timer);
        if (window.history && window.history.replaceState) { window.history.replaceState(null, '', D.pageUrl); }
        showStage('wizard');
        setStep('what');
        try {
            var last = JSON.parse(window.sessionStorage.getItem('besr_last') || 'null');
            if (last && !$('#besr-search').val()) { $('#besr-search').val(last.search); $('#besr-replace').val(last.replace); }
        } catch (e) { /* ignore */ }
        wizard.analyze();
        wizard.updateCounts();
        focusTitle($('#besr-form'));
    };
    BESR.wizard = wizard;

    /* ------------------------------------------------------------------ */
    /* Resume banner                                                      */
    /* ------------------------------------------------------------------ */

    function showResumeBanner(p) {
        if (!p || !p.run) { return; }
        var run = p.run;
        var when = run.created_at ? run.created_at : '';
        $('#besr-resume-text').text(sprintf(i18n.startedBy, run.search, run.replace, p.message || run.status, run.user || '') + (when ? ' (' + when + ' UTC)' : ''));
        $('#besr-resume-view').attr('href', D.pageUrl + '&run=' + run.id);
        $('#besr-resume').prop('hidden', false);
        $('#besr-resume-continue').off('click').on('click', function () {
            if (D.tab !== 'search') { window.location.href = D.pageUrl + '&run=' + run.id; return; }
            $('#besr-resume').prop('hidden', true);
            state.logCount = 0;
            $('#besr-log').val('');
            appendLog(p.log);
            state.logCount = p.log_count;
            if (p.status === 'error') {
                state.run = run;
                render(p);
                doAction('retry');
            } else {
                render(p);
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* Init                                                               */
    /* ------------------------------------------------------------------ */

    $(function () {
        // Wizard
        $('#besr-search, #besr-replace').on('input', wizard.analyze);
        $('#besr-case').on('change', function () { if ($('#besr-search').val()) { wizard.runAnalyze(); } });
        $('#besr-variants').on('change', 'input', function () {
            $(this).closest('.besr-variant').toggleClass('is-on', this.checked);
            wizard.runAnalyze();
        });
        $('#besr-swap').on('click', function () {
            var s = $('#besr-search').val();
            $('#besr-search').val($('#besr-replace').val());
            $('#besr-replace').val(s);
            wizard.analyze();
        });
        $('#besr-form').on('submit', function (e) { e.preventDefault(); });
        $('#besr-groups').on('change', '.besr-group-cb', function () {
            wizard.tableBoxes($(this).closest('.besr-group')).not(':disabled').prop('checked', this.checked);
            wizard.updateCounts();
        });
        $('#besr-groups').on('change', 'input[name="tables[]"]', wizard.updateCounts);
        $('#besr-groups').on('click', '.besr-group-toggle', function () { wizard.expand($(this).closest('.besr-group')); });
        $('.besr-where-toolbar [data-select]').on('click', function () { wizard.select($(this).data('select')); });
        $('#besr-table-filter').on('input', function () { wizard.filter(this.value); });
        $('#besr-scan').on('click', wizard.startScan);
        $('#besr-edit-search, #besr-new-search').on('click', wizard.reset);
        $(document).on('click', '#besr-new-search', wizard.reset);
        $(document).on('click', '#besr-reapply', function () {
            if (state.run) { $('#besr-search').val(state.run.search); $('#besr-replace').val(state.run.replace); }
            wizard.reset();
        });
        $(document).on('click', '#besr-undo', startUndo);

        // Progress actions
        $('#besr-progress-stop').on('click', function () {
            var text = state.mode === 'scan' ? i18n.stopConfirmScan : i18n.stopConfirmApply;
            dialog.confirm({ title: i18n.confirmStop, body: '<p>' + escapeHtml(text) + '</p>', okLabel: i18n.confirmStop, danger: true }).done(function (ok) {
                if (ok) { doAction('cancel'); }
            });
        });
        $('#besr-progress-retry').on('click', function () { doAction('retry').done(function () { if (state.run && !state.running && state.payload && state.payload.status !== 'error') { resumeTick(); } }); });
        $('#besr-progress-reload').on('click', function () { window.location.reload(); });
        $('#besr-progress-back').on('click', wizard.reset);

        // History
        $(document).on('click', '.besr-run-delete', function () {
            var $btn = $(this), id = $btn.data('run');
            dialog.confirm({ title: i18n.deleteTitle, body: '<p>' + escapeHtml(i18n.deleteBody) + '</p>', okLabel: i18n.delete, danger: true }).done(function (ok) {
                if (!ok) { return; }
                ajax('delete_run', { run_id: id }).done(function (res) {
                    if (!res.success) { notice(res.data && res.data.message ? res.data.message : i18n.unexpected); return; }
                    $btn.closest('tr').fadeOut(200, function () { $(this).remove(); });
                    announce(i18n.deleted);
                }).fail(function (xhr) { notice(failMessage(xhr, i18n.requestFailed)); });
            });
        });

        // Settings
        $('#besr-settings-form').on('submit', function (e) {
            e.preventDefault();
            var $f = $(this);
            var data = {};
            $f.serializeArray().forEach(function (kv) {
                if (/\[\]$/.test(kv.name)) { var k = kv.name.slice(0, -2); (data[k] = data[k] || []).push(kv.value); }
                else { data[kv.name] = kv.value; }
            });
            ['default_variants', 'default_excluded'].forEach(function (k) { if (!data[k]) { data[k] = ['']; } });
            $('#besr-settings-spinner').addClass('is-active');
            $('#besr-settings-saved').prop('hidden', true);
            ajax('save_settings', data).done(function (res) {
                $('#besr-settings-spinner').removeClass('is-active');
                if (!res.success) { notice(res.data && res.data.message ? res.data.message : i18n.saveFailed); return; }
                notice('');
                $('#besr-settings-saved').prop('hidden', false);
                announce(i18n.saved);
            }).fail(function (xhr) { $('#besr-settings-spinner').removeClass('is-active'); notice(failMessage(xhr, i18n.saveFailed)); });
        });

        $(window).on('beforeunload', function () { if (state.running) { return i18n.leaving; } });

        if (D.activeRun && D.activeRun.run) { showResumeBanner(D.activeRun); }

        if (D.tab === 'search') {
            wizard.updateCounts();
            if (D.run && D.run.run && D.runRequested) {
                state.logCount = 0;
                appendLog(D.run.log);
                state.logCount = D.run.log_count;
                render(D.run);
                var q = window.location.search;
                if (/[?&]do=undo/.test(q) && D.run.status === 'applied') { startUndo(); }
                if (/[?&]do=retry/.test(q) && D.run.status === 'error') { doAction('retry'); }
            } else {
                showStage('wizard');
                try {
                    var last = JSON.parse(window.sessionStorage.getItem('besr_last') || 'null');
                    if (last && last.search && !$('#besr-search').val()) { $('#besr-search').val(last.search); $('#besr-replace').val(last.replace || ''); wizard.analyze(); }
                } catch (e) { /* ignore */ }
            }
        }
    });
})(jQuery);
