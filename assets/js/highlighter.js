/**
 * Media Janitor — Frontend Highlighter
 *
 * When a page is loaded with ?mj_highlight=<filename>, this script:
 * 1. Finds all elements referencing that media file (img, video, audio, a[href], background-image)
 * 2. Scrolls to the first match
 * 3. Adds a pulsing highlight border so the user can instantly see where the media is used
 * 4. Shows a single fixed navigation bar to cycle between matches
 */
(function () {
    'use strict';

    var params   = new URLSearchParams(window.location.search);
    var filename = params.get('mj_highlight');
    if (!filename) return;

    injectStyles();

    // Wait for DOM + images to be ready.
    window.addEventListener('load', function () {
        var matches = findMediaElements(filename);

        if (!matches.length) {
            showFloatingBanner(
                'Media file "' + filename + '" not found visually on this page. ' +
                'It may be used in metadata, CSS, or a page builder that renders dynamically.',
                'warning'
            );
            return;
        }

        // Highlight all matches with a border only (no per-item label).
        matches.forEach(function (el) {
            addHighlightBorder(el);
        });

        // Show a single fixed nav bar and scroll to the first match.
        showNavBar(matches, 0, filename);
        scrollToElement(matches[0]);

        // Watch for new elements added by Load More buttons (e.g. Elementor gallery).
        observeNewMatches(filename, matches);
    });

    /* ----------------------------------------------------------------
     *  Find media elements
     * ----------------------------------------------------------------*/

    function findMediaElements(filename) {
        var found = [];
        var seen  = new Set();

        var bare   = filename.replace(/^.*[/\\]/, '');
        var noSize = bare.replace(/-\d+x\d+(\.\w+)$/, '$1');

        // 1. <img src / srcset>
        document.querySelectorAll('img').forEach(function (img) {
            if (matchesUrl(img.src, bare, noSize) || matchesSrcset(img.srcset, bare, noSize)) {
                addUnique(found, seen, img);
            }
        });

        // 2. <video> / <video source>
        document.querySelectorAll('video, video source').forEach(function (el) {
            if (matchesUrl(el.src, bare, noSize)) {
                addUnique(found, seen, el.tagName === 'SOURCE' ? (el.closest('video') || el) : el);
            }
        });

        // 3. <audio> / <audio source>
        document.querySelectorAll('audio, audio source').forEach(function (el) {
            if (matchesUrl(el.src, bare, noSize)) {
                addUnique(found, seen, el.tagName === 'SOURCE' ? (el.closest('audio') || el) : el);
            }
        });

        // 4. <a href> — use the raw attribute value, not a.href (which resolves to an absolute URL
        //    including the current page path, causing false matches on fragment/anchor links).
        //    Also skip pure fragment links (#...), javascript:, mailto:, tel:.
        document.querySelectorAll('a[href]').forEach(function (a) {
            var raw = a.getAttribute('href') || '';
            if (!raw || /^(#|javascript:|mailto:|tel:)/i.test(raw)) return;
            if (!matchesUrl(raw, bare, noSize)) return;
            var wrapsMatch = found.some(function (matched) { return a.contains(matched); });
            if (!wrapsMatch) addUnique(found, seen, a);
        });

        // 5. Inline background-image
        document.querySelectorAll('[style*="background"]').forEach(function (el) {
            var bg = window.getComputedStyle(el).backgroundImage || '';
            if (matchesUrl(bg, bare, noSize)) addUnique(found, seen, el);
        });

        // 6. CSS-class background-image (all elements)
        document.querySelectorAll('*').forEach(function (el) {
            if (['SCRIPT','STYLE','META','LINK','HEAD','TITLE','BR','HR'].indexOf(el.tagName) !== -1) return;
            if (seen.has(el)) return;
            var bg = '';
            try { bg = window.getComputedStyle(el).backgroundImage || ''; } catch (e) { return; }
            if (bg && bg !== 'none' && matchesUrl(bg, bare, noSize)) addUnique(found, seen, el);
        });

        // 7. <iframe src>, <embed src>, <object data>
        document.querySelectorAll('iframe[src], embed[src], object[data]').forEach(function (el) {
            var url = el.src || el.getAttribute('data') || '';
            if (matchesUrl(url, bare, noSize)) addUnique(found, seen, el);
        });

        return found;
    }

    function matchesUrl(url, bare, noSize) {
        if (!url) return false;
        try { url = decodeURIComponent(url); } catch (e) {}
        return url.indexOf(bare) !== -1 || url.indexOf(noSize) !== -1;
    }

    function matchesSrcset(srcset, bare, noSize) {
        if (!srcset) return false;
        return srcset.split(',').some(function (entry) {
            return matchesUrl(entry.trim().split(/\s+/)[0], bare, noSize);
        });
    }

    function addUnique(found, seen, el) {
        if (!seen.has(el)) { seen.add(el); found.push(el); }
    }

    /* ----------------------------------------------------------------
     *  Highlight border (no overlay, no label — avoids DOM-position drift)
     * ----------------------------------------------------------------*/

    function addHighlightBorder(el) {
        el.classList.add('mj-highlight-active');
    }

    /* ----------------------------------------------------------------
     *  Single fixed navigation bar
     * ----------------------------------------------------------------*/

    var currentIndex = 0;
    var navBar       = null;

    function showNavBar(matches, startIndex, filenameLabel) {
        currentIndex = startIndex;

        navBar = document.createElement('div');
        navBar.className = 'mj-highlight-banner mj-highlight-nav-bar';

        var bare = (filenameLabel || '').replace(/^.*[/\\]/, '');

        function render() {
            var total = matches.length;
            navBar.innerHTML =
                '<span class="mj-hl-icon">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;">' +
                '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>' +
                '</svg>' +
                'Media Janitor</span>' +
                (bare ? '<span class="mj-hl-filename" title="' + bare + '">' + bare + '</span>' : '') +
                '<span class="mj-hl-counter">Match ' + (currentIndex + 1) + ' of ' + total + '</span>' +
                (total > 1
                    ? '<button class="mj-hl-btn" id="mj-hl-prev">&larr; Prev</button>' +
                      '<button class="mj-hl-btn" id="mj-hl-next">Next &rarr;</button>'
                    : '') +
                '<button class="mj-hl-btn mj-hl-dismiss" id="mj-hl-dismiss">Dismiss</button>';

            // Wire up buttons after innerHTML is set.
            var prevBtn    = navBar.querySelector('#mj-hl-prev');
            var nextBtn    = navBar.querySelector('#mj-hl-next');
            var dismissBtn = navBar.querySelector('#mj-hl-dismiss');

            if (prevBtn) {
                prevBtn.addEventListener('click', function () {
                    currentIndex = (currentIndex - 1 + matches.length) % matches.length;
                    render();
                    scrollToElement(matches[currentIndex]);
                });
            }
            if (nextBtn) {
                nextBtn.addEventListener('click', function () {
                    currentIndex = (currentIndex + 1) % matches.length;
                    render();
                    scrollToElement(matches[currentIndex]);
                });
            }
            if (dismissBtn) {
                dismissBtn.addEventListener('click', function () {
                    navBar.remove();
                });
            }
        }

        render();
        document.body.appendChild(navBar);
    }

    /* ----------------------------------------------------------------
     *  MutationObserver — catch elements added by Load More buttons
     *  (Elementor gallery, WooCommerce, etc.)
     * ----------------------------------------------------------------*/

    function observeNewMatches(filename, matchList) {
        if (!window.MutationObserver) return;

        var bare   = filename.replace(/^.*[/\\]/, '');
        var noSize = bare.replace(/-\d+x\d+(\.\w+)$/, '$1');
        var seen   = new Set(matchList);

        var observer = new MutationObserver(function (mutations) {
            var newMatches = [];

            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) return; // elements only

                    // Check the node itself and all descendants.
                    var candidates = [node].concat(Array.prototype.slice.call(node.querySelectorAll('img, video, audio, a[href], iframe[src]')));

                    candidates.forEach(function (el) {
                        if (seen.has(el)) return;
                        var url = el.src || el.href || el.getAttribute('data') || '';
                        var srcset = el.srcset || '';
                        if (matchesUrl(url, bare, noSize) || matchesSrcset(srcset, bare, noSize)) {
                            seen.add(el);
                            newMatches.push(el);
                        }
                    });
                });
            });

            if (!newMatches.length) return;

            // Highlight newly found elements and update the nav bar.
            newMatches.forEach(function (el) {
                addHighlightBorder(el);
                matchList.push(el);
            });

            // Re-render the nav bar with the updated total.
            if (navBar) {
                // Re-invoke render by replacing the navBar.
                var parent = navBar.parentElement;
                if (parent) {
                    navBar.remove();
                    showNavBar(matchList, currentIndex, filename);
                }
            }
        });

        observer.observe(document.body, { childList: true, subtree: true });

        // Stop observing after 60 s to avoid memory leaks.
        setTimeout(function () { observer.disconnect(); }, 60000);
    }

    /* ----------------------------------------------------------------
     *  Scroll to the actual DOM element (not an overlay)
     * ----------------------------------------------------------------*/

    function scrollToElement(el) {
        // Use scrollIntoView for reliability — it accounts for fixed bars, transforms, etc.
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    /* ----------------------------------------------------------------
     *  Warning banner (no matches case)
     * ----------------------------------------------------------------*/

    function showFloatingBanner(message, type) {
        var bg   = type === 'warning' ? '#dba617' : '#00a32a';
        var text = type === 'warning' ? '#1d2327' : '#fff';

        var banner = document.createElement('div');
        banner.className = 'mj-highlight-banner';
        banner.style.cssText =
            'background:' + bg + ';color:' + text + ';';
        banner.innerHTML =
            '<span>' + message + '</span>' +
            '<button class="mj-hl-btn" onclick="this.parentElement.remove()">Dismiss</button>';

        document.body.appendChild(banner);

        setTimeout(function () {
            if (banner.parentElement) {
                banner.style.transition = 'opacity 0.4s';
                banner.style.opacity = '0';
                setTimeout(function () { banner.remove(); }, 400);
            }
        }, 8000);
    }

    /* ----------------------------------------------------------------
     *  Styles
     * ----------------------------------------------------------------*/

    function injectStyles() {
        var style = document.createElement('style');
        style.textContent =
            '@keyframes mjPulse {' +
            '  0%,100% { box-shadow:0 0 0 3px rgba(255,36,98,.7),0 0 12px rgba(255,36,98,.3); }' +
            '  50%      { box-shadow:0 0 0 6px rgba(255,36,98,.4),0 0 24px rgba(255,36,98,.2); }' +
            '}' +
            '.mj-highlight-active {' +
            '  outline: 3px solid #FF2462 !important;' +
            '  outline-offset: 2px;' +
            '  border-radius: 4px;' +
            '  animation: mjPulse 1.5s ease-in-out 4;' +
            '}' +
            '.mj-highlight-banner {' +
            '  position:fixed;top:0;left:0;right:0;z-index:1000001;' +
            '  background:#1d2327;color:#fff;' +
            '  padding:10px 20px;font-size:13px;' +
            '  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;' +
            '  display:flex;align-items:center;gap:12px;flex-wrap:wrap;' +
            '  box-shadow:0 2px 8px rgba(0,0,0,.25);' +
            '}' +
            '.mj-hl-icon { font-weight:600; color:#FF2462; }' +
            '.mj-hl-filename { font-size:12px; color:rgba(255,255,255,.65); max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }' +
            '.mj-hl-counter { font-weight:700; }' +
            '.mj-hl-btn {' +
            '  background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);' +
            '  color:#fff;padding:4px 12px;border-radius:4px;cursor:pointer;' +
            '  font-size:12px;font-family:inherit;white-space:nowrap;' +
            '}' +
            '.mj-hl-btn:hover { background:rgba(255,36,98,.7); border-color:transparent; }' +
            '.mj-hl-dismiss { margin-left:auto; }';
        document.head.appendChild(style);
    }

})();
