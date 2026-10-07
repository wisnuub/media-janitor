/**
 * Media Janitor — Admin JavaScript
 */
(function ($) {
    'use strict';

    var cfg  = window.mediaJanitor;
    var i18n = cfg.i18n;

    var LS_KEY = 'media_janitor_state';

    function loadState() {
        try {
            return JSON.parse(localStorage.getItem(LS_KEY) || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    function saveState() {
        try {
            localStorage.setItem(LS_KEY, JSON.stringify({
                type:   state.type,
                filter: state.filter,
                search: state.search,
                paged:  state.paged
            }));
        } catch (e) {}
    }

    var saved = loadState();
    var state = {
        type:       saved.type   || 'all',
        filter:     saved.filter || 'unused',
        search:     saved.search || '',
        paged:      saved.paged  || 1,
        selected:   [],
        items:      [],
        dupItems:   [],
        scanStatus: cfg.scanStatus,
        busy:       false
    };

    var $grid, $emptyState, $pagination, $summary, $results, $progress, $dupPane, $notices;

    /* ----------------------------------------------------------------
     *  Init
     * ----------------------------------------------------------------*/

    $(function () {
        $grid       = $('#mj-grid');
        $emptyState = $('#mj-empty-state');
        $pagination = $('#mj-pagination');
        $summary    = $('#mj-summary');
        $results    = $('#mj-results');
        $progress   = $('#mj-progress');
        $dupPane    = $('#mj-duplicates-pane');
        $notices    = $('#mj-notices');

        bindEvents();
        showLastScan();
        renderNotices();

        if (cfg.lastScan > 0 || state.scanStatus === 'running' || state.scanStatus === 'aborted') {
            $('#mj-filter-status').val(state.filter);
            $('#mj-search').val(state.search);
            setActiveTab(state.type);
            $results.show();

            if (state.type === 'duplicates') {
                showDuplicatesPane();
            } else {
                loadResults();
            }
            refreshSummary();
        }
    });

    /* ----------------------------------------------------------------
     *  Events
     * ----------------------------------------------------------------*/

    function bindEvents() {
        $('#mj-scan-btn').on('click', startScan);
        $(document).on('click', '#mj-scan-dup-btn', startDuplicateScan);

        $(document).on('click', '.mj-tab', function () {
            state.type  = $(this).data('type');
            state.paged = 1;
            setActiveTab(state.type);
            clearSelection();
            saveState();

            if (state.type === 'duplicates') {
                showDuplicatesPane();
            } else {
                hideDuplicatesPane();
                loadResults();
            }
        });

        $('#mj-filter-status').on('change', function () {
            state.filter = $(this).val();
            state.paged  = 1;
            clearSelection();
            saveState();
            loadResults();
        });

        var searchTimer;
        $('#mj-search').on('input', function () {
            clearTimeout(searchTimer);
            var val = $(this).val();
            searchTimer = setTimeout(function () {
                state.search = val;
                state.paged  = 1;
                clearSelection();
                saveState();
                loadResults();
            }, 400);
        });

        $(document).on('click', '.mj-page-btn', function () {
            state.paged = parseInt($(this).data('page'), 10);
            saveState();
            loadResults();
            $('html, body').animate({ scrollTop: $results.offset().top - 40 }, 200);
        });

        $(document).on('change', '.mj-item__check', function () {
            var id    = parseInt($(this).val(), 10);
            var $item = $(this).closest('.mj-item, .mj-dup-item');
            $item.toggleClass('mj-item--selected', this.checked);
            if (this.checked) {
                if (state.selected.indexOf(id) === -1) state.selected.push(id);
            } else {
                state.selected = state.selected.filter(function (x) { return x !== id; });
            }
            updateDeleteButtons();
        });

        $('#mj-select-all').on('click', function () {
            var allChecked = $grid.find('.mj-item__check:not(:checked)').length === 0;
            $grid.find('.mj-item__check').prop('checked', !allChecked).trigger('change');
        });

        $(document).on('click', '.mj-item__thumb, .mj-item__usage-btn', function (e) {
            e.preventDefault();
            var $item = $(this).closest('.mj-item, .mj-dup-item');
            var id    = $item.length ? parseInt($item.data('id'), 10) : parseInt($(this).data('id'), 10);
            if (id) showUsageModal(id);
        });

        $(document).on('click', '.mj-modal__close, .mj-modal__overlay', closeModal);
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#mj-modal').is(':visible')) closeModal();
        });

        $('#mj-delete-selected').on('click', function () {
            deleteSelected(state.items);
        });

        $(document).on('click', '#mj-dup-delete-selected', function () {
            deleteSelected(state.dupItems);
        });

        $('#mj-delete-all-unused').on('click', deleteAllUnused);

        $(document).on('click', '.mj-rescan-btn', function () {
            startScan();
        });
    }

    /* ----------------------------------------------------------------
     *  Scan (usage) — repeated requests until the server reports complete
     * ----------------------------------------------------------------*/

    function startScan() {
        if (state.busy) return;
        state.busy = true;

        $('#mj-scan-btn').prop('disabled', true);
        $('#mj-scan-status').text(i18n.scanning);
        $progress.show();
        $results.hide();
        $summary.hide();
        $notices.empty();
        clearSelection();
        updateProgress(0, i18n.scanning);

        scanStep(true);
    }

    function scanStep(restart) {
        $.post(cfg.ajaxUrl, {
            action:  'media_janitor_scan',
            nonce:   cfg.nonce,
            restart: restart ? 1 : 0
        }).done(function (res) {
            if (!res.success) {
                scanFailed();
                return;
            }

            var data = res.data;
            state.scanStatus = data.status;

            if (data.status === 'running') {
                updateProgress(data.progress, i18n.scanStep[data.step] || i18n.scanning);
                scanStep(false);
                return;
            }

            if (data.status !== 'complete') {
                scanFailed();
                return;
            }

            updateProgress(100, i18n.scanComplete);
            $('#mj-scan-status').text(i18n.scanComplete);
            cfg.lastScan         = data.lastScan;
            cfg.newSinceLastScan = 0;
            showLastScan();
            renderNotices();
            if (data.summary) renderSummary(data.summary);
            finishScan();

            setTimeout(function () {
                $progress.hide();
                $results.show();
                if (state.type === 'duplicates') {
                    showDuplicatesPane();
                } else {
                    loadResults();
                }
            }, 600);
        }).fail(scanFailed);
    }

    function scanFailed() {
        state.scanStatus = 'aborted';
        $progress.hide();
        $('#mj-scan-status').text('');
        renderNotices();
        finishScan();
        toast(i18n.scanFailed, 'error');
    }

    function finishScan() {
        state.busy = false;
        $('#mj-scan-btn').prop('disabled', false);
        updateDeleteButtons();
    }

    /* ----------------------------------------------------------------
     *  Results (grid)
     * ----------------------------------------------------------------*/

    function loadResults() {
        if (state.type === 'duplicates') return;

        $results.show();
        $grid.html('');
        $emptyState.html('<span class="spinner is-active" style="float:none;"></span>').show();

        $.post(cfg.ajaxUrl, {
            action:   'media_janitor_results',
            nonce:    cfg.nonce,
            filter:   state.filter,
            type:     state.type,
            search:   state.search,
            paged:    state.paged,
            per_page: 40
        }).done(function (res) {
            if (!res.success) {
                $emptyState.html('<p>' + escHtml(i18n.error) + '</p>');
                return;
            }
            // Page past the end (e.g. after deleting) — step back.
            if (!res.data.items.length && state.paged > 1) {
                state.paged = res.data.pages;
                saveState();
                loadResults();
                return;
            }
            state.items = res.data.items;
            renderGrid(res.data.items);
            renderPagination(res.data.total, res.data.pages);
        }).fail(function () {
            $emptyState.html('<p>' + escHtml(i18n.error) + '</p>');
        });
    }

    function renderGrid(items) {
        $emptyState.hide().html('');

        if (!items.length) {
            var msg = state.search ? i18n.noMatch
                : state.filter === 'unused' ? i18n.noUnused
                : state.filter === 'used' ? i18n.noUsed
                : i18n.noFiles;
            $grid.html('');
            $emptyState.html('<span class="dashicons dashicons-yes-alt"></span><p>' + escHtml(msg) + '</p>').show();
            return;
        }

        var html = '';
        items.forEach(function (item) {
            html += '<div class="mj-item' + (isSelected(item.id) ? ' mj-item--selected' : '') + '" data-id="' + item.id + '">';
            html += checkboxHtml(item);
            html += '<div class="mj-item__thumb">' + thumbHtml(item) + '</div>';
            html += '<div class="mj-item__info">';
            html += '<div class="mj-item__name" title="' + escHtml(item.filename) + '">' + escHtml(item.filename) + '</div>';
            html += '<div class="mj-item__details">' + badgeHtml(item) + '<span>' + escHtml(item.size_hr) + '</span></div>';
            if (item.used && item.usage.length) {
                html += '<div style="margin-top:4px;">' + usageBtnHtml(item) + '</div>';
            }
            html += '</div></div>';
        });

        $grid.html(html);
    }

    function renderPagination(total, pages) {
        if (pages <= 1) {
            $pagination.html('');
            return;
        }

        var current = state.paged;
        var html = '<button class="mj-page-btn" data-page="' + (current - 1) + '"' + (current <= 1 ? ' disabled' : '') + '>&laquo;</button>';

        var start = Math.max(1, current - 2);
        var end   = Math.min(pages, current + 2);

        if (start > 1) {
            html += '<button class="mj-page-btn" data-page="1">1</button>';
            if (start > 2) html += '<span style="padding:6px 4px;">…</span>';
        }
        for (var i = start; i <= end; i++) {
            html += '<button class="mj-page-btn' + (i === current ? ' mj-page--active' : '') + '" data-page="' + i + '">' + i + '</button>';
        }
        if (end < pages) {
            if (end < pages - 1) html += '<span style="padding:6px 4px;">…</span>';
            html += '<button class="mj-page-btn" data-page="' + pages + '">' + pages + '</button>';
        }

        html += '<button class="mj-page-btn" data-page="' + (current + 1) + '"' + (current >= pages ? ' disabled' : '') + '>&raquo;</button>';
        html += '<span style="margin-left:12px;font-size:13px;color:#646970;">' + escHtml(sprintf(i18n.items, total)) + '</span>';

        $pagination.html(html);
    }

    function refreshSummary() {
        $.post(cfg.ajaxUrl, {
            action: 'media_janitor_summary',
            nonce:  cfg.nonce
        }).done(function (res) {
            if (res.success) renderSummary(res.data);
        });
    }

    function renderSummary(summary) {
        $summary.show();
        $('#mj-total').text(summary.total);
        $('#mj-used').text(summary.used);
        $('#mj-unused').text(summary.unused);
        $('#mj-size').text(humanSize(summary.unused_size));

        $('#mj-count-all').text(summary.total);
        $.each(summary.categories || {}, function (key, val) {
            $('#mj-count-' + key).text(val.total);
        });
    }

    /* ----------------------------------------------------------------
     *  Duplicates pane
     * ----------------------------------------------------------------*/

    function showDuplicatesPane() {
        $('.mj-filters').hide();
        $grid.hide();
        $pagination.hide();
        $emptyState.hide();
        $dupPane.show();
        $results.show();
        loadDuplicates();
    }

    function hideDuplicatesPane() {
        $dupPane.hide();
        $('.mj-filters').show();
        $grid.show();
        $pagination.show();
    }

    function startDuplicateScan() {
        if (state.busy) return;
        state.busy = true;

        $('#mj-scan-dup-btn').prop('disabled', true);
        $('#mj-dup-status').text('');
        $('#mj-dup-not-scanned, #mj-dup-results').hide();
        $('#mj-dup-loading-text').text(i18n.scanning);
        $('#mj-dup-loading').show();
        clearSelection();

        duplicateStep(true);
    }

    function duplicateStep(restart) {
        $.post(cfg.ajaxUrl, {
            action:  'media_janitor_scan_duplicates',
            nonce:   cfg.nonce,
            restart: restart ? 1 : 0
        }).done(function (res) {
            if (!res.success) {
                duplicateDone(false);
                return;
            }
            if (res.data.status === 'running') {
                $('#mj-dup-loading-text').text(sprintf(i18n.hashing, res.data.done, res.data.total));
                duplicateStep(false);
                return;
            }
            $('#mj-dup-status').text(i18n.dupScanComplete);
            renderDuplicates(res.data.results);
            duplicateDone(true);
        }).fail(function () {
            duplicateDone(false);
        });
    }

    function duplicateDone(ok) {
        state.busy = false;
        $('#mj-dup-loading').hide();
        $('#mj-scan-dup-btn').prop('disabled', false);
        if (!ok) {
            toast(i18n.error, 'error');
            $('#mj-dup-not-scanned').show();
        }
    }

    function loadDuplicates() {
        $('#mj-dup-not-scanned, #mj-dup-results').hide();
        $('#mj-dup-loading-text').text(i18n.scanning);
        $('#mj-dup-loading').show();

        $.post(cfg.ajaxUrl, {
            action: 'media_janitor_get_duplicates',
            nonce:  cfg.nonce
        }).done(function (res) {
            if (res.success) {
                renderDuplicates(res.data);
            } else {
                $('#mj-dup-not-scanned').show();
            }
        }).fail(function () {
            $('#mj-dup-not-scanned').show();
        }).always(function () {
            $('#mj-dup-loading').hide();
        });
    }

    function renderDuplicates(data) {
        state.dupItems = [];

        var totalGroups = data.exact.length + data.scale.length + data.visual.length;
        $('#mj-count-duplicates').text(totalGroups || '');
        $('#mj-dup-exact-count').text(data.exact.length);
        $('#mj-dup-scale-count').text(data.scale.length);
        $('#mj-dup-visual-count').text(data.visual.length);

        renderDuplicateSection(data.exact, 'mj-dup-exact-groups');
        renderDuplicateSection(data.scale, 'mj-dup-scale-groups');
        renderDuplicateSection(data.visual, 'mj-dup-visual-groups');

        $('#mj-dup-visual-skipped').text(i18n.visualSkipped).toggle(!!data.visual_skipped);
        $('#mj-dup-results').show();
        updateDeleteButtons();
    }

    function renderDuplicateSection(groups, containerId) {
        var $container = $('#' + containerId);

        if (!groups.length) {
            $container.html('<p class="mj-dup-none">' + escHtml(i18n.dupNoneFound) + '</p>');
            return;
        }

        var html = '';
        groups.forEach(function (group, idx) {
            html += '<div class="mj-dup-group">';
            html += '<div class="mj-dup-group__head">';
            html += '<span class="mj-dup-group__label">' + escHtml(sprintf(i18n.group, idx + 1)) + '</span>';
            html += '<span class="mj-dup-group__count">' + escHtml(sprintf(i18n.files, group.length)) + '</span>';
            html += '</div><div class="mj-dup-group__items">';

            group.forEach(function (item) {
                state.dupItems.push(item);
                html += '<div class="mj-dup-item' + (isSelected(item.id) ? ' mj-item--selected' : '') + '" data-id="' + item.id + '">';
                html += checkboxHtml(item);
                html += '<div class="mj-dup-item__thumb">' + thumbHtml(item) + '</div>';
                html += '<div class="mj-dup-item__meta">';
                html += '<div class="mj-item__name" title="' + escHtml(item.filename) + '">' + escHtml(item.filename) + '</div>';
                html += '<div class="mj-item__details">' + badgeHtml(item) + '<span>' + escHtml(item.size_hr) + '</span></div>';
                if (item.used && item.usage.length) html += usageBtnHtml(item);
                html += '</div></div>';
            });

            html += '</div></div>';
        });

        $container.html(html);
    }

    /* ----------------------------------------------------------------
     *  Usage modal
     * ----------------------------------------------------------------*/

    function showUsageModal(attachmentId) {
        var item = findItem(attachmentId);
        if (!item) return;

        $('#mj-modal-thumb').html(thumbHtml(item));
        $('#mj-modal-title').text(item.filename);
        $('#mj-modal-meta').text(item.mime + ' · ' + item.size_hr);

        var $list = $('#mj-modal-usage').empty();

        if (!item.usage.length) {
            $list.append('<li class="mj-no-usage">' + escHtml(i18n.notUsedAnywhere) + '</li>');
        } else {
            item.usage.forEach(function (u) {
                var linkHtml = escHtml(u.label);

                if (u.url) {
                    var isAdmin      = u.url.indexOf('/wp-admin/') !== -1;
                    var highlightUrl = isAdmin ? u.url : buildHighlightUrl(u.url, item.filename);

                    linkHtml = '<a href="' + escHtml(highlightUrl) + '" target="_blank" rel="noopener">' + escHtml(u.label) + '</a>';
                    if (!isAdmin) {
                        linkHtml += ' <a href="' + escHtml(highlightUrl) + '" target="_blank" rel="noopener" class="mj-find-btn" title="' + escHtml(i18n.findOnPageTitle) + '">' +
                            '<span class="dashicons dashicons-search" style="font-size:14px;width:14px;height:14px;vertical-align:-2px;"></span> ' + escHtml(i18n.findOnPage) + '</a>';
                    }
                }

                $list.append(
                    '<li>' +
                    '<span class="mj-usage-type">' + escHtml(formatSourceType(u.type)) + '</span>' +
                    '<span class="mj-usage-label">' + linkHtml + '</span>' +
                    '</li>'
                );
            });
        }

        $('#mj-modal').show();
        $('#mj-modal .mj-modal__close').trigger('focus');
    }

    function closeModal() {
        $('#mj-modal').hide();
    }

    function buildHighlightUrl(baseUrl, filename) {
        var separator = baseUrl.indexOf('?') !== -1 ? '&' : '?';
        return baseUrl + separator + 'media_janitor_highlight=' + encodeURIComponent(filename);
    }

    /* ----------------------------------------------------------------
     *  Delete
     * ----------------------------------------------------------------*/

    function deleteSelected(pool) {
        if (!state.selected.length || !canDelete()) return;

        var ids   = state.selected.slice();
        var used  = ids.filter(function (id) {
            var item = findItem(id, pool);
            return item && item.used;
        });
        var force = false;

        if (used.length) {
            if (!confirm(sprintf(i18n.confirmUsed, used.length))) return;
            force = true;
        }
        if (!confirm(sprintf(i18n.confirmDelete, ids.length))) return;

        runDelete(ids, force);
    }

    function deleteAllUnused() {
        if (!canDelete()) return;

        $.post(cfg.ajaxUrl, {
            action: 'media_janitor_unused_ids',
            nonce:  cfg.nonce,
            type:   state.type
        }).done(function (res) {
            if (!res.success) {
                toast(i18n.error, 'error');
                return;
            }
            if (!res.data.length) {
                toast(i18n.noUnused, 'success');
                return;
            }
            var category = i18n.categories[state.type] || i18n.categories.all;
            if (confirm(sprintf(i18n.confirmAll, res.data.length, category))) {
                runDelete(res.data, false);
            }
        }).fail(function () {
            toast(i18n.error, 'error');
        });
    }

    /**
     * Delete in batches of 20 so large selections don't time out.
     */
    function runDelete(ids, force) {
        state.busy = true;
        updateDeleteButtons();

        var deleted = 0;
        var skipped = 0;
        var offset  = 0;

        function next() {
            var batch = ids.slice(offset, offset + 20);
            if (!batch.length) {
                done();
                return;
            }
            $('#mj-scan-status').text(sprintf(i18n.deletingProgress, Math.min(offset + batch.length, ids.length), ids.length));

            $.post(cfg.ajaxUrl, {
                action: 'media_janitor_delete',
                nonce:  cfg.nonce,
                ids:    batch,
                force:  force ? 1 : 0
            }).done(function (res) {
                if (!res.success) {
                    toast(typeof res.data === 'string' ? res.data : i18n.error, 'error');
                    done();
                    return;
                }
                deleted += res.data.deleted.length;
                skipped += res.data.skipped.length;
                offset  += batch.length;
                next();
            }).fail(function () {
                toast(i18n.error, 'error');
                done();
            });
        }

        function done() {
            state.busy = false;
            $('#mj-scan-status').text('');
            clearSelection();
            if (deleted) toast(sprintf(i18n.deleted, deleted), 'success');
            if (skipped) toast(sprintf(i18n.skipped, skipped), 'error');
            if (state.type === 'duplicates') {
                loadDuplicates();
            } else {
                loadResults();
            }
            refreshSummary();
        }

        next();
    }

    function canDelete() {
        return !state.busy && state.scanStatus === 'complete';
    }

    function updateDeleteButtons() {
        var blocked = !canDelete();
        $('#mj-delete-selected, #mj-dup-delete-selected').prop('disabled', blocked || state.selected.length === 0);
        $('#mj-delete-all-unused').prop('disabled', blocked);
    }

    function clearSelection() {
        state.selected = [];
        $('.mj-item__check').prop('checked', false);
        $('.mj-item--selected').removeClass('mj-item--selected');
        updateDeleteButtons();
    }

    /* ----------------------------------------------------------------
     *  Notices
     * ----------------------------------------------------------------*/

    function renderNotices() {
        $notices.empty();

        if (state.scanStatus === 'running' || state.scanStatus === 'aborted') {
            addNotice(i18n.scanIncomplete, true);
        } else if (cfg.newSinceLastScan > 0) {
            addNotice(sprintf(i18n.newUploads, cfg.newSinceLastScan), true);
        }
        updateDeleteButtons();
    }

    function addNotice(text, withRescan) {
        var $n = $('<div class="mj-new-uploads-notice"></div>')
            .append('<span class="dashicons dashicons-info" style="margin-right:6px;color:var(--mj-accent);"></span>')
            .append($('<span></span>').text(text));
        if (withRescan) {
            $n.append(' <button type="button" class="button button-small mj-rescan-btn" style="margin-left:8px;">' + escHtml(i18n.rescan) + '</button>');
        }
        $notices.append($n);
    }

    /* ----------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    function setActiveTab(type) {
        $('.mj-tab').removeClass('mj-tab--active').attr('aria-selected', 'false');
        $('.mj-tab[data-type="' + type + '"]').addClass('mj-tab--active').attr('aria-selected', 'true');
    }

    function findItem(id, pool) {
        var list = pool || state.items.concat(state.dupItems);
        for (var i = 0; i < list.length; i++) {
            if (list[i].id === id) return list[i];
        }
        return null;
    }

    function isSelected(id) {
        return state.selected.indexOf(id) !== -1;
    }

    function checkboxHtml(item) {
        return '<input type="checkbox" class="mj-item__check" value="' + item.id + '"' + (isSelected(item.id) ? ' checked' : '') +
            ' aria-label="' + escHtml(item.filename) + '">';
    }

    function thumbHtml(item) {
        return item.thumb
            ? '<img src="' + escHtml(item.thumb) + '" alt="" loading="lazy">'
            : '<span class="dashicons ' + getIcon(item.category) + '"></span>';
    }

    function badgeHtml(item) {
        return '<span class="mj-item__badge ' + (item.used ? 'mj-item__badge--used' : 'mj-item__badge--unused') + '">' +
            escHtml(item.used ? i18n.used : i18n.unused) + '</span>';
    }

    function usageBtnHtml(item) {
        return '<button type="button" class="mj-item__usage-btn" data-id="' + item.id + '">' + escHtml(sprintf(item.usage.length === 1 ? i18n.reference : i18n.references, item.usage.length)) + '</button>';
    }

    function updateProgress(pct, text) {
        $('#mj-progress-fill').css('width', pct + '%');
        $('#mj-progress-text').text(text);
    }

    function showLastScan() {
        if (cfg.lastScan > 0) {
            $('#mj-last-scan').text(sprintf(i18n.lastScan, new Date(cfg.lastScan * 1000).toLocaleString()));
        }
    }

    function getIcon(category) {
        switch (category) {
            case 'image':    return 'dashicons-format-image';
            case 'video':    return 'dashicons-video-alt3';
            case 'audio':    return 'dashicons-format-audio';
            case 'document': return 'dashicons-media-document';
            default:         return 'dashicons-media-default';
        }
    }

    function formatSourceType(type) {
        if (type.indexOf('meta:') === 0) {
            return sprintf(i18n.customField, type.substring(5));
        }
        return i18n.sourceTypes[type] || type;
    }

    function humanSize(bytes) {
        if (!bytes) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = 0;
        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024;
            i++;
        }
        return bytes.toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
    }

    /**
     * Minimal sprintf for translated strings: %s, %d and positional %1$s / %2$d.
     */
    function sprintf(format) {
        var args = Array.prototype.slice.call(arguments, 1);
        var next = 0;
        return String(format).replace(/%(?:(\d+)\$)?([sd])/g, function (m, pos) {
            var value = pos ? args[parseInt(pos, 10) - 1] : args[next++];
            return value === undefined ? m : String(value);
        });
    }

    function escHtml(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML.replace(/"/g, '&quot;');
    }

    function toast(message, type) {
        var $stack = $('.mj-toasts');
        if (!$stack.length) {
            $stack = $('<div class="mj-toasts" aria-live="polite"></div>').appendTo('body');
        }
        var $t = $('<div class="mj-toast mj-toast--' + (type || 'success') + '"></div>').text(message);
        $stack.append($t);
        setTimeout(function () { $t.fadeOut(300, function () { $t.remove(); }); }, 5000);
    }

})(jQuery);
