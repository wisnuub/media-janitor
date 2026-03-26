/**
 * Media Janitor — Frontend Highlighter
 *
 * When a page is loaded with ?mj_highlight=<filename>, this script:
 * 1. Finds all elements referencing that media file (img, video, audio, a[href], background-image)
 * 2. Scrolls to the first match
 * 3. Adds a pulsing highlight overlay so the user can instantly see where the media is used
 */
(function () {
    'use strict';

    var params = new URLSearchParams(window.location.search);
    var filename = params.get('mj_highlight');
    if (!filename) return;

    // Wait for DOM + images to be ready.
    window.addEventListener('load', function () {
        var matches = findMediaElements(filename);

        if (!matches.length) {
            showFloatingBanner('Media file "' + filename + '" not found visually on this page. It may be used in metadata, CSS, or a page builder that renders dynamically.', 'warning');
            return;
        }

        showFloatingBanner(matches.length + ' occurrence' + (matches.length > 1 ? 's' : '') + ' found. Scrolling to first match.', 'success');

        // Highlight all matches.
        matches.forEach(function (el, index) {
            addHighlight(el, index + 1, matches.length);
        });

        // Scroll to the first one.
        scrollToElement(matches[0]);
    });

    /**
     * Find all DOM elements that reference the given filename.
     */
    function findMediaElements(filename) {
        var found = [];
        var seen = new Set();

        // Normalize: just the bare filename for matching.
        var bare = filename.replace(/^.*[/\\]/, '');

        // Also create a version without size suffix, e.g. photo-300x200.jpg → photo.jpg
        var noSize = bare.replace(/-\d+x\d+(\.\w+)$/, '$1');

        // 1. Images: <img src="..."> and <img srcset="...">
        document.querySelectorAll('img').forEach(function (img) {
            if (matchesUrl(img.src, bare, noSize) || matchesSrcset(img.srcset, bare, noSize)) {
                addUnique(found, seen, img);
            }
        });

        // 2. Videos: <video src="..."> and <source src="...">
        document.querySelectorAll('video, video source').forEach(function (el) {
            if (matchesUrl(el.src, bare, noSize)) {
                var target = el.tagName === 'SOURCE' ? el.closest('video') || el : el;
                addUnique(found, seen, target);
            }
        });

        // 3. Audio: <audio src="..."> and <source src="...">
        document.querySelectorAll('audio, audio source').forEach(function (el) {
            if (matchesUrl(el.src, bare, noSize)) {
                var target = el.tagName === 'SOURCE' ? el.closest('audio') || el : el;
                addUnique(found, seen, target);
            }
        });

        // 4. Links: <a href="...file.pdf"> etc.
        document.querySelectorAll('a[href]').forEach(function (a) {
            if (matchesUrl(a.href, bare, noSize)) {
                addUnique(found, seen, a);
            }
        });

        // 5. Background images in inline styles.
        document.querySelectorAll('[style*="background"]').forEach(function (el) {
            var bg = window.getComputedStyle(el).backgroundImage || '';
            if (matchesUrl(bg, bare, noSize)) {
                addUnique(found, seen, el);
            }
        });

        // 6. Background images in computed styles (catches CSS classes).
        document.querySelectorAll('*').forEach(function (el) {
            // Only check elements that might have a background (skip script, style, meta, etc.)
            if (['SCRIPT', 'STYLE', 'META', 'LINK', 'HEAD', 'TITLE', 'BR', 'HR'].indexOf(el.tagName) !== -1) return;
            if (seen.has(el)) return;

            var bg = '';
            try { bg = window.getComputedStyle(el).backgroundImage || ''; } catch (e) { return; }
            if (bg && bg !== 'none' && matchesUrl(bg, bare, noSize)) {
                addUnique(found, seen, el);
            }
        });

        // 7. Iframes (PDF embeds, etc.) — can't traverse cross-origin, but can check src.
        document.querySelectorAll('iframe[src], embed[src], object[data]').forEach(function (el) {
            var url = el.src || el.getAttribute('data') || '';
            if (matchesUrl(url, bare, noSize)) {
                addUnique(found, seen, el);
            }
        });

        return found;
    }

    function matchesUrl(url, bare, noSize) {
        if (!url) return false;
        // Decode and get just the filename portion.
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
        if (!seen.has(el)) {
            seen.add(el);
            found.push(el);
        }
    }

    /**
     * Scroll smoothly to an element with an offset.
     */
    function scrollToElement(el) {
        var rect = el.getBoundingClientRect();
        var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        var targetY = rect.top + scrollTop - Math.max(100, window.innerHeight * 0.25);

        window.scrollTo({ top: Math.max(0, targetY), behavior: 'smooth' });
    }

    /**
     * Add a visible highlight overlay around the element.
     */
    function addHighlight(el, index, total) {
        // Make sure the element is visible.
        el.style.position = el.style.position || 'relative';

        var overlay = document.createElement('div');
        overlay.className = 'mj-highlight-overlay';
        overlay.innerHTML =
            '<span class="mj-highlight-label">' +
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;">' +
            '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>' +
            '</svg>' +
            'Media Janitor — Match ' + index + ' of ' + total +
            '</span>';

        // Position overlay on top of the element.
        var rect = el.getBoundingClientRect();
        var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        var scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;

        overlay.style.cssText =
            'position:absolute;' +
            'top:' + (rect.top + scrollTop) + 'px;' +
            'left:' + (rect.left + scrollLeft) + 'px;' +
            'width:' + rect.width + 'px;' +
            'height:' + rect.height + 'px;' +
            'min-width:40px;min-height:40px;' +
            'pointer-events:none;' +
            'z-index:999999;' +
            'box-sizing:border-box;';

        document.body.appendChild(overlay);

        // Add navigation arrows if multiple matches.
        if (total > 1) {
            var nav = document.createElement('div');
            nav.className = 'mj-highlight-nav';
            nav.style.cssText =
                'position:absolute;' +
                'top:' + (rect.top + scrollTop - 36) + 'px;' +
                'left:' + (rect.left + scrollLeft) + 'px;' +
                'z-index:1000000;';
            nav.innerHTML =
                '<button class="mj-highlight-nav-btn" data-direction="prev" data-index="' + index + '" title="Previous match">&larr;</button>' +
                '<button class="mj-highlight-nav-btn" data-direction="next" data-index="' + index + '" title="Next match">&rarr;</button>';
            document.body.appendChild(nav);
        }
    }

    /**
     * Show a floating banner at the top of the page.
     */
    function showFloatingBanner(message, type) {
        var colors = {
            success: { bg: '#00a32a', text: '#fff' },
            warning: { bg: '#dba617', text: '#1d2327' },
        };
        var c = colors[type] || colors.success;

        var banner = document.createElement('div');
        banner.className = 'mj-highlight-banner';
        banner.style.cssText =
            'position:fixed;top:0;left:0;right:0;z-index:1000001;' +
            'background:' + c.bg + ';color:' + c.text + ';' +
            'padding:12px 20px;font-size:14px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;' +
            'text-align:center;box-shadow:0 2px 8px rgba(0,0,0,0.15);' +
            'display:flex;align-items:center;justify-content:center;gap:12px;';

        banner.innerHTML =
            '<span>' + message + '</span>' +
            '<button onclick="this.parentElement.remove()" style="background:rgba(255,255,255,0.2);border:none;color:inherit;padding:4px 12px;border-radius:4px;cursor:pointer;font-size:13px;">Dismiss</button>';

        document.body.appendChild(banner);

        // Auto-dismiss after 8 seconds.
        setTimeout(function () {
            if (banner.parentElement) {
                banner.style.transition = 'opacity 0.4s';
                banner.style.opacity = '0';
                setTimeout(function () { banner.remove(); }, 400);
            }
        }, 8000);
    }

    /**
     * Inject the highlight styles.
     */
    function injectStyles() {
        var style = document.createElement('style');
        style.textContent =
            '@keyframes mjPulse {' +
            '  0%, 100% { box-shadow: 0 0 0 3px rgba(34,113,177,0.7), 0 0 12px rgba(34,113,177,0.3); }' +
            '  50% { box-shadow: 0 0 0 6px rgba(34,113,177,0.4), 0 0 24px rgba(34,113,177,0.2); }' +
            '}' +
            '.mj-highlight-overlay {' +
            '  border: 3px solid #2271b1;' +
            '  border-radius: 4px;' +
            '  animation: mjPulse 1.5s ease-in-out 3;' +
            '  background: rgba(34,113,177,0.08);' +
            '}' +
            '.mj-highlight-label {' +
            '  position:absolute; bottom:100%; left:0;' +
            '  background:#2271b1; color:#fff;' +
            '  font-size:12px; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;' +
            '  padding:4px 10px; border-radius:4px 4px 0 0;' +
            '  white-space:nowrap; pointer-events:auto;' +
            '}' +
            '.mj-highlight-nav {' +
            '  display:flex; gap:4px;' +
            '}' +
            '.mj-highlight-nav-btn {' +
            '  background:#2271b1; color:#fff; border:none;' +
            '  width:28px; height:28px; border-radius:4px;' +
            '  cursor:pointer; font-size:16px; line-height:1;' +
            '  display:flex; align-items:center; justify-content:center;' +
            '}' +
            '.mj-highlight-nav-btn:hover { background:#135e96; }';
        document.head.appendChild(style);
    }

    injectStyles();

    // Handle navigation between matches.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.mj-highlight-nav-btn');
        if (!btn) return;

        var overlays = document.querySelectorAll('.mj-highlight-overlay');
        var currentIndex = parseInt(btn.dataset.index, 10) - 1;
        var direction = btn.dataset.direction;
        var nextIndex;

        if (direction === 'next') {
            nextIndex = (currentIndex + 1) % overlays.length;
        } else {
            nextIndex = (currentIndex - 1 + overlays.length) % overlays.length;
        }

        var target = overlays[nextIndex];
        if (target) {
            var rect = target.getBoundingClientRect();
            var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            window.scrollTo({
                top: Math.max(0, rect.top + scrollTop - window.innerHeight * 0.25),
                behavior: 'smooth',
            });
        }
    });

})();
