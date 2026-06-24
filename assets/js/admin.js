/**
 * Media Janitor — Admin JavaScript
 */
(function ($) {
    'use strict';

    var LS_KEY = 'mj_state';

    function loadState() {
        try {
            var saved = JSON.parse( localStorage.getItem( LS_KEY ) || '{}' );
            return {
                type:     saved.type   || 'all',
                filter:   saved.filter || 'unused',
                search:   saved.search || '',
                paged:    saved.paged  || 1,
            };
        } catch (e) { return {}; }
    }

    function saveState() {
        try {
            localStorage.setItem( LS_KEY, JSON.stringify({
                type:   state.type,
                filter: state.filter,
                search: state.search,
                paged:  state.paged,
            }) );
        } catch (e) {}
    }

    var saved = loadState();
    var state = {
        type:     saved.type   || 'all',
        filter:   saved.filter || 'unused',
        search:   saved.search || '',
        paged:    saved.paged  || 1,
        selected: [],
        items:    [],
        dupItems: [], // flat list of items currently shown in duplicates pane
        summary:  null,
    };

    var $grid, $emptyState, $pagination, $summary, $results, $progress, $dupPane;

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

        bindEvents();
        showLastScan();

        // If a scan was already done, restore last UI state and load results.
        if (mjData.lastScan > 0) {
            // Restore filter controls to saved state.
            $('#mj-filter-status').val(state.filter);
            $('#mj-search').val(state.search);
            $('.mj-tab').removeClass('mj-tab--active');
            $('.mj-tab[data-type="' + state.type + '"]').addClass('mj-tab--active');

            $results.show();

            if (state.type === 'duplicates') {
                showDuplicatesPane();
            } else {
                loadResults();
            }

            loadSummary();

            // Notify if new uploads exist since last scan.
            if (mjData.newSinceLastScan > 0) {
                showNewUploadsNotice(mjData.newSinceLastScan);
            }
        }
    });

    /* ----------------------------------------------------------------
     *  Events
     * ----------------------------------------------------------------*/

    function bindEvents() {
        // Scan button.
        $('#mj-scan-btn').on('click', startScan);

        // Scan for Duplicates button.
        $(document).on('click', '#mj-scan-dup-btn', startDuplicateScan);

        // Delete selected duplicates.
        $(document).on('click', '#mj-dup-delete-selected', function () {
            if (state.selected.length === 0) return;
            if (!confirm(mjData.i18n.confirmDelete)) return;
            deleteMedia(state.selected);
        });

        // Tabs.
        $(document).on('click', '.mj-tab', function () {
            $('.mj-tab').removeClass('mj-tab--active');
            $(this).addClass('mj-tab--active');
            state.type  = $(this).data('type');
            state.paged = 1;
            saveState();

            if (state.type === 'duplicates') {
                showDuplicatesPane();
            } else {
                hideDuplicatesPane();
                loadResults();
            }
        });

        // Filter dropdown.
        $('#mj-filter-status').on('change', function () {
            state.filter = $(this).val();
            state.paged  = 1;
            saveState();
            loadResults();
        });

        // Search.
        var searchTimer;
        $('#mj-search').on('input', function () {
            clearTimeout(searchTimer);
            var val = $(this).val();
            searchTimer = setTimeout(function () {
                state.search = val;
                state.paged  = 1;
                saveState();
                loadResults();
            }, 400);
        });

        // Pagination.
        $(document).on('click', '.mj-page-btn', function () {
            state.paged = parseInt($(this).data('page'), 10);
            saveState();
            loadResults();
            $('html, body').animate({ scrollTop: $results.offset().top - 40 }, 200);
        });

        // Item checkbox (grid and duplicates pane).
        $(document).on('change', '.mj-item__check', function () {
            var id    = parseInt($(this).val(), 10);
            var $item = $(this).closest('.mj-item, .mj-dup-item');
            if (this.checked) {
                $item.addClass('mj-item--selected');
                if (state.selected.indexOf(id) === -1) state.selected.push(id);
            } else {
                $item.removeClass('mj-item--selected');
                state.selected = state.selected.filter(function (x) { return x !== id; });
            }
            updateDeleteBtn();
        });

        // Select all (grid only).
        $('#mj-select-all').on('click', function () {
            var allChecked = $grid.find('.mj-item__check:not(:checked)').length === 0;
            $grid.find('.mj-item__check').prop('checked', !allChecked).trigger('change');
        });

        // View usage (click thumbnail or "Used in" button — grid and duplicates pane).
        $(document).on('click', '.mj-item__thumb, .mj-item__usage-btn', function (e) {
            e.preventDefault();
            var $item = $(this).closest('.mj-item, .mj-dup-item');
            var id    = $item.length ? $item.data('id') : parseInt($(this).data('id'), 10);
            if (id) showUsageModal(id);
        });

        // Close modal.
        $(document).on('click', '.mj-modal__close, .mj-modal__overlay', function () {
            $('#mj-modal').hide();
        });

        // Delete selected (grid).
        $('#mj-delete-selected').on('click', function () {
            if (state.selected.length === 0) return;
            if (!confirm(mjData.i18n.confirmDelete)) return;
            deleteMedia(state.selected);
        });

        // Delete all unused.
        $('#mj-delete-all-unused').on('click', function () {
            if (!confirm(mjData.i18n.confirmAll)) return;
            deleteAllUnused();
        });
    }

    /* ----------------------------------------------------------------
     *  Scan (usage)
     * ----------------------------------------------------------------*/

    function startScan() {
        var $btn = $('#mj-scan-btn').prop('disabled', true);
        var $status = $('#mj-scan-status').text(mjData.i18n.scanning);

        $progress.show();
        $results.hide();
        $summary.hide();

        updateProgress(0, 'Starting scan…');

        $.post(mjData.ajaxUrl, {
            action: 'mj_scan',
            nonce:  mjData.nonceScan,
            offset: 0,
        }).done(function (res) {
            if (res.success) {
                updateProgress(100, mjData.i18n.scanComplete);
                $status.text(mjData.i18n.scanComplete);
                mjData.lastScan = Math.floor(Date.now() / 1000);

                // Render summary.
                if (res.data.summary) {
                    renderSummary(res.data.summary);
                }

                // Load results.
                setTimeout(function () {
                    $progress.hide();
                    $results.show();
                    if (state.type === 'duplicates') {
                        showDuplicatesPane();
                    } else {
                        loadResults();
                    }
                }, 600);
            } else {
                toast(mjData.i18n.error, 'error');
            }
        }).fail(function () {
            toast(mjData.i18n.error, 'error');
        }).always(function () {
            $btn.prop('disabled', false);
            showLastScan();
        });
    }

    /* ----------------------------------------------------------------
     *  Results (grid)
     * ----------------------------------------------------------------*/

    function loadResults() {
        if (state.type === 'duplicates') return;

        $results.show();
        $grid.html('');
        $emptyState.html('<span class="spinner is-active" style="float:none;"></span>').show();

        $.post(mjData.ajaxUrl, {
            action:   'mj_results',
            nonce:    mjData.nonceResults,
            filter:   state.filter,
            type:     state.type,
            search:   state.search,
            paged:    state.paged,
            per_page: 40,
        }).done(function (res) {
            if (res.success) {
                state.items = res.data.items;
                renderGrid(res.data.items);
                renderPagination(res.data.total, res.data.pages);
            }
        });
    }

    function loadSummary() {
        if (mjData.lastScan > 0) {
            $summary.show();
        }
    }

    function renderGrid(items) {
        $emptyState.hide().html('');

        if (!items.length) {
            $grid.html('');
            $emptyState.html(
                '<span class="dashicons dashicons-yes-alt"></span>' +
                '<p>' + mjData.i18n.noUnused + '</p>'
            ).show();
            return;
        }

        var html = '';
        items.forEach(function (item) {
            var isSelected = state.selected.indexOf(item.id) !== -1;
            var thumbHtml;

            if (item.thumb) {
                thumbHtml = '<img src="' + escHtml(item.thumb) + '" alt="" loading="lazy">';
            } else {
                var icon = getIcon(item.category);
                thumbHtml = '<span class="dashicons ' + icon + '"></span>';
            }

            var badgeClass = item.used ? 'mj-item__badge--used' : 'mj-item__badge--unused';
            var badgeText  = item.used ? 'Used' : 'Unused';

            var usageInfo = '';
            if (item.used && item.usage.length) {
                usageInfo = '<button class="mj-item__usage-btn">' + item.usage.length + ' reference' + (item.usage.length > 1 ? 's' : '') + '</button>';
            }

            html += '<div class="mj-item' + (isSelected ? ' mj-item--selected' : '') + '" data-id="' + item.id + '">';
            html += '<input type="checkbox" class="mj-item__check" value="' + item.id + '"' + (isSelected ? ' checked' : '') + '>';
            html += '<div class="mj-item__thumb">' + thumbHtml + '</div>';
            html += '<div class="mj-item__info">';
            html += '<div class="mj-item__name" title="' + escHtml(item.filename) + '">' + escHtml(item.filename) + '</div>';
            html += '<div class="mj-item__details">';
            html += '<span class="mj-item__badge ' + badgeClass + '">' + badgeText + '</span>';
            html += '<span>' + escHtml(item.size_hr) + '</span>';
            html += '</div>';
            if (usageInfo) {
                html += '<div style="margin-top:4px;">' + usageInfo + '</div>';
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

        var html = '';
        var current = state.paged;

        // Prev button.
        html += '<button class="mj-page-btn" data-page="' + (current - 1) + '"' + (current <= 1 ? ' disabled' : '') + '>&laquo;</button>';

        // Page numbers.
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

        // Next button.
        html += '<button class="mj-page-btn" data-page="' + (current + 1) + '"' + (current >= pages ? ' disabled' : '') + '>&raquo;</button>';

        // Total count.
        html += '<span style="margin-left:12px;font-size:13px;color:#646970;">' + total + ' items</span>';

        $pagination.html(html);
    }

    function renderSummary(summary) {
        $summary.show();
        $('#mj-total').text(summary.total);
        $('#mj-used').text(summary.used);
        $('#mj-unused').text(summary.unused);
        $('#mj-size').text(humanSize(summary.unused_size));

        // Update tab counts.
        $('#mj-count-all').text(summary.total);
        var cats = summary.categories || {};
        $.each(cats, function (key, val) {
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
    }

    function startDuplicateScan() {
        var $btn    = $('#mj-scan-dup-btn').prop('disabled', true);
        var $status = $('#mj-dup-status').text(mjData.i18n.scanningDuplicates);

        $('#mj-dup-not-scanned').hide();
        $('#mj-dup-results').hide();
        $('#mj-dup-loading').show();

        $.post(mjData.ajaxUrl, {
            action: 'mj_scan_duplicates',
            nonce:  mjData.nonceDuplicates,
        }).done(function (res) {
            if (res.success) {
                $status.text(mjData.i18n.dupScanComplete);
                renderDuplicates(res.data);
            } else {
                toast(mjData.i18n.error, 'error');
                $status.text('');
            }
        }).fail(function () {
            toast(mjData.i18n.error, 'error');
            $status.text('');
        }).always(function () {
            $('#mj-dup-loading').hide();
            $btn.prop('disabled', false);
        });
    }

    function loadDuplicates() {
        $('#mj-dup-not-scanned').hide();
        $('#mj-dup-results').hide();
        $('#mj-dup-loading').show();

        $.post(mjData.ajaxUrl, {
            action: 'mj_get_duplicates',
            nonce:  mjData.nonceDuplicates,
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

        renderDuplicateSection(data.exact,  'mj-dup-exact-groups');
        renderDuplicateSection(data.scale,  'mj-dup-scale-groups');
        renderDuplicateSection(data.visual, 'mj-dup-visual-groups');

        $('#mj-dup-results').show();
    }

    function renderDuplicateSection(groups, containerId) {
        var $container = $('#' + containerId);

        if (!groups.length) {
            $container.html('<p class="mj-dup-none">' + mjData.i18n.dupNoneFound + '</p>');
            return;
        }

        var html = '';
        groups.forEach(function (group, idx) {
            html += '<div class="mj-dup-group">';
            html += '<div class="mj-dup-group__head">';
            html += '<span class="mj-dup-group__label">Group ' + (idx + 1) + '</span>';
            html += '<span class="mj-dup-group__count">' + group.length + ' files</span>';
            html += '</div>';
            html += '<div class="mj-dup-group__items">';

            group.forEach(function (item) {
                state.dupItems.push(item);

                var isSelected = state.selected.indexOf(item.id) !== -1;
                var thumbHtml  = item.thumb
                    ? '<img src="' + escHtml(item.thumb) + '" alt="" loading="lazy">'
                    : '<span class="dashicons ' + getIcon(item.category) + '"></span>';
                var badgeClass = item.used ? 'mj-item__badge--used' : 'mj-item__badge--unused';
                var badgeText  = item.used ? 'Used' : 'Unused';

                html += '<div class="mj-dup-item' + (isSelected ? ' mj-item--selected' : '') + '" data-id="' + item.id + '">';
                html += '<input type="checkbox" class="mj-item__check" value="' + item.id + '"' + (isSelected ? ' checked' : '') + '>';
                html += '<div class="mj-dup-item__thumb">' + thumbHtml + '</div>';
                html += '<div class="mj-dup-item__meta">';
                html += '<div class="mj-item__name" title="' + escHtml(item.filename) + '">' + escHtml(item.filename) + '</div>';
                html += '<div class="mj-item__details">';
                html += '<span class="mj-item__badge ' + badgeClass + '">' + badgeText + '</span>';
                html += '<span>' + escHtml(item.size_hr) + '</span>';
                html += '</div>';
                if (item.used && item.usage.length) {
                    html += '<button class="mj-item__usage-btn" data-id="' + item.id + '">' +
                        item.usage.length + ' reference' + (item.usage.length !== 1 ? 's' : '') + '</button>';
                }
                html += '</div>';
                html += '</div>'; // .mj-dup-item
            });

            html += '</div>'; // .mj-dup-group__items
            html += '</div>'; // .mj-dup-group
        });

        $container.html(html);
    }

    /* ----------------------------------------------------------------
     *  Usage modal
     * ----------------------------------------------------------------*/

    function showUsageModal(attachmentId) {
        var item = null;
        var all  = state.items.concat(state.dupItems);
        all.forEach(function (i) {
            if (i.id === attachmentId) item = i;
        });

        if (!item) return;

        var $modal = $('#mj-modal');
        var $thumb = $('#mj-modal-thumb');

        if (item.thumb) {
            $thumb.html('<img src="' + escHtml(item.thumb) + '" alt="">');
        } else {
            $thumb.html('<span class="dashicons ' + getIcon(item.category) + '"></span>');
        }

        $('#mj-modal-title').text(item.filename);
        $('#mj-modal-meta').text(item.mime + ' · ' + item.size_hr);

        var $list = $('#mj-modal-usage').empty();

        if (!item.usage.length) {
            $list.append('<li class="mj-no-usage">This media file is not used anywhere.</li>');
        } else {
            item.usage.forEach(function (u) {
                var typeLabel = formatSourceType(u.type);
                var linkHtml;

                if (u.url) {
                    var highlightUrl = buildHighlightUrl(u.url, item.filename);
                    var isAdmin = u.url.indexOf('/wp-admin/') !== -1;

                    linkHtml = '<a href="' + escHtml(highlightUrl) + '" target="_blank">' + escHtml(u.label) + '</a>';

                    if (!isAdmin) {
                        linkHtml += ' <a href="' + escHtml(highlightUrl) + '" target="_blank" class="mj-find-btn" title="Open page and scroll to this media">' +
                            '<span class="dashicons dashicons-search" style="font-size:14px;width:14px;height:14px;vertical-align:-2px;"></span> Find on page</a>';
                    }
                } else {
                    linkHtml = escHtml(u.label);
                }

                $list.append(
                    '<li>' +
                    '<span class="mj-usage-type">' + escHtml(typeLabel) + '</span>' +
                    '<span class="mj-usage-label">' + linkHtml + '</span>' +
                    '</li>'
                );
            });
        }

        $modal.show();
    }

    function buildHighlightUrl(baseUrl, filename) {
        if (!baseUrl) return baseUrl;
        var separator = baseUrl.indexOf('?') !== -1 ? '&' : '?';
        return baseUrl + separator + 'mj_highlight=' + encodeURIComponent(filename);
    }

    /* ----------------------------------------------------------------
     *  Delete
     * ----------------------------------------------------------------*/

    function deleteMedia(ids) {
        var $btn = ( state.type === 'duplicates' ? $('#mj-dup-delete-selected') : $('#mj-delete-selected') )
            .prop('disabled', true).text(mjData.i18n.deleting);

        $.post(mjData.ajaxUrl, {
            action: 'mj_delete',
            nonce:  mjData.nonceDelete,
            ids:    ids,
        }).done(function (res) {
            if (res.success) {
                toast(res.data.deleted + ' file(s) deleted.', 'success');
                state.selected = [];
                if (state.type === 'duplicates') {
                    startDuplicateScan();
                } else {
                    loadResults();
                }
                refreshSummary();
            } else {
                toast(mjData.i18n.error, 'error');
            }
        }).fail(function () {
            toast(mjData.i18n.error, 'error');
        }).always(function () {
            $btn.prop('disabled', false).html(
                '<span class="dashicons dashicons-trash" style="margin-top:4px;"></span> Delete Selected'
            );
            updateDeleteBtn();
        });
    }

    function deleteAllUnused() {
        var $btn = $('#mj-delete-all-unused').prop('disabled', true).text(mjData.i18n.deleting);

        $.post(mjData.ajaxUrl, {
            action:   'mj_results',
            nonce:    mjData.nonceResults,
            filter:   'unused',
            type:     state.type,
            search:   '',
            paged:    1,
            per_page: 9999,
        }).done(function (res) {
            if (res.success && res.data.items.length) {
                var ids = res.data.items.map(function (i) { return i.id; });
                deleteBatch(ids, 0, $btn);
            } else {
                toast(mjData.i18n.noUnused, 'success');
                $btn.prop('disabled', false).text('Delete All Unused');
            }
        });
    }

    function deleteBatch(ids, offset, $btn) {
        var batchSize = 20;
        var batch = ids.slice(offset, offset + batchSize);

        if (!batch.length) {
            toast('All unused media deleted!', 'success');
            $btn.prop('disabled', false).text('Delete All Unused');
            state.selected = [];
            loadResults();
            refreshSummary();
            return;
        }

        $btn.text('Deleting ' + (offset + batch.length) + '/' + ids.length + '…');

        $.post(mjData.ajaxUrl, {
            action: 'mj_delete',
            nonce:  mjData.nonceDelete,
            ids:    batch,
        }).done(function () {
            deleteBatch(ids, offset + batchSize, $btn);
        }).fail(function () {
            toast(mjData.i18n.error, 'error');
            $btn.prop('disabled', false).text('Delete All Unused');
        });
    }

    function refreshSummary() {
        $.post(mjData.ajaxUrl, {
            action: 'mj_scan',
            nonce:  mjData.nonceScan,
            offset: 0,
        }).done(function (res) {
            if (res.success && res.data.summary) {
                renderSummary(res.data.summary);
            }
        });
    }

    /* ----------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    function showNewUploadsNotice(count) {
        var $notice = $(
            '<div class="mj-new-uploads-notice">' +
            '<span class="dashicons dashicons-info" style="margin-right:6px;color:var(--mj-accent);"></span>' +
            '<strong>' + count + ' new file' + (count > 1 ? 's' : '') + '</strong> uploaded since last scan — results may be incomplete.' +
            ' <button class="button button-small" id="mj-rescan-btn" style="margin-left:8px;">Rescan now</button>' +
            '</div>'
        );
        $results.before($notice);
        $notice.find('#mj-rescan-btn').on('click', function () {
            $notice.remove();
            $('#mj-scan-btn').trigger('click');
        });
    }

    function updateDeleteBtn() {
        var disabled = state.selected.length === 0;
        $('#mj-delete-selected').prop('disabled', disabled);
        $('#mj-dup-delete-selected').prop('disabled', disabled);
    }

    function updateProgress(pct, text) {
        $('#mj-progress-fill').css('width', pct + '%');
        $('#mj-progress-text').text(text);
    }

    function showLastScan() {
        if (mjData.lastScan > 0) {
            var d = new Date(mjData.lastScan * 1000);
            $('#mj-last-scan').text('Last scan: ' + d.toLocaleString());
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
        var map = {
            'page':           'Page',
            'post':           'Post',
            'product':        'Product',
            'featured_image': 'Featured Image',
            'woo_gallery':    'Product Gallery',
            'widget':         'Widget',
            'theme_mod':      'Customizer',
            'option':         'Site Option',
            'nav_menu':       'Menu',
            'elementor':      'Elementor',
            'custom_css':     'Custom CSS',
        };

        if (type.indexOf('meta:') === 0) {
            return 'Meta: ' + type.substring(5);
        }

        return map[type] || type;
    }

    function humanSize(bytes) {
        if (!bytes) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = 0;
        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024;
            i++;
        }
        return bytes.toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function toast(message, type) {
        var $t = $('<div class="mj-toast mj-toast--' + (type || 'success') + '">' + escHtml(message) + '</div>');
        $('body').append($t);
        setTimeout(function () { $t.fadeOut(300, function () { $t.remove(); }); }, 3500);
    }

})(jQuery);
