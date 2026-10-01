/**
 * EXC Archive Filters
 * ------------------------------------------------------------
 * WPCode PHP Snippet. Run Everywhere, Auto Insert, priority 10.
 * No opening PHP tag. Pure ASCII.
 *
 * REPLACES FIVE SNIPPETS. Deactivate these when this goes live:
 *   Pop Out Filters
 *   Scroll to Top Pagination
 *   Remember Page via Storage
 *   JS Price Slider
 *   Hide Filters when Empty
 *
 * Each feature behaves as before. What changes:
 *   - Loads only on product listing pages. Three of the five used to
 *     load on every page of the site.
 *   - Hide Filters when Empty now listens for JetSmartFilters' redraw
 *     events properly (the old listeners never fired; its DOM watcher
 *     was doing the work), and only writes when a group's visibility
 *     actually changes.
 *   - Each feature starts on its own, so one failing cannot stop the
 *     other four.
 *   - Written so the Cloudflare firewall lets WPCode save it: the code
 *     is attached with wp_add_inline_script and builds the pop-out
 *     icons as DOM nodes rather than markup strings.
 *
 * Pages: shop, product categories and tags, other product taxonomies,
 * product search, plus any page slugs in exc_af_extra_pages().
 * Remember Page and the pop-out panel keep their original, narrower
 * scopes (see exc_af_config).
 */

// Extra page slugs that list products with filters or pagination.
if ( ! function_exists( 'exc_af_extra_pages' ) ) {
    function exc_af_extra_pages() {
        return array( 'latest-products' );
    }
}

// Which features run on this page. Empty array = do not load at all.
if ( ! function_exists( 'exc_af_config' ) ) {
    function exc_af_config() {
        $needs = array( 'is_shop', 'is_product_taxonomy', 'is_product_category', 'is_product_tag', 'is_tax', 'is_search', 'is_page' );
        foreach ( $needs as $fn ) {
            if ( ! function_exists( $fn ) ) {
                return array();
            }
        }

        // Original scope of Pop Out Filters.
        $popout = is_shop() || is_product_taxonomy();

        // Original scope of Remember Page via Storage.
        $remember = is_shop() || is_product_category() || is_product_tag() || is_tax();

        $load = $popout || $remember || is_search() || is_page( exc_af_extra_pages() );

        if ( ! $load ) {
            return array();
        }

        return array(
            'popout'   => $popout,
            'remember' => $remember,
        );
    }
}

if ( ! function_exists( 'exc_af_js' ) ) {
    function exc_af_js() {
        return '
(function ($) {
    "use strict";

    var CFG = window.excArchiveFilters || {};
    var SIDEBAR = "#exc-filter-sidebar";

    // Run one feature without letting an error stop the others.
    function run(fn) {
        try { fn(); } catch (err) {
            if (window.console) { console.error("EXC Archive Filters:", err); }
        }
    }

    function store(action, key, value) {
        try {
            if (action === "get") { return window.sessionStorage.getItem(key); }
            if (action === "set") { window.sessionStorage.setItem(key, value); }
            if (action === "remove") { window.sessionStorage.removeItem(key); }
        } catch (err) { return null; }
        return null;
    }

    /* ---- 1. Price slider dots (was: JS Price Slider) ---- */

    function updateRangeDots() {
        document.querySelectorAll(SIDEBAR + " .jet-range").forEach(function (range) {
            var slider = range.querySelector(".jet-range__slider");
            if (!slider) { return; }
            var inputs = slider.querySelectorAll("input[type=range]");
            if (inputs.length < 2) { return; }

            var min = parseFloat(slider.dataset.min || inputs[0].min || 0);
            var max = parseFloat(slider.dataset.max || inputs[0].max || 100);
            var total = max - min;
            if (total <= 0) { return; }

            var a = parseFloat(inputs[0].value);
            var b = parseFloat(inputs[1].value);
            slider.style.setProperty("--exc-min-pct", ((Math.min(a, b) - min) / total * 100) + "%");
            slider.style.setProperty("--exc-max-pct", ((Math.max(a, b) - min) / total * 100) + "%");
        });
    }

    /* ---- 2. Hide empty filter groups (was: Hide Filters when Empty) ---- */

    function hideEmptyFilters() {
        document.querySelectorAll(SIDEBAR + " .elementor-widget-jet-smart-filters-checkboxes").forEach(function (widget) {
            var rows = widget.querySelectorAll(".jet-checkboxes-list__row");
            var visible = 0;
            for (var i = 0; i < rows.length; i++) {
                var cs = window.getComputedStyle(rows[i]);
                if (cs.display !== "none" && cs.visibility !== "hidden") { visible++; }
            }
            var want = (rows.length === 0 || visible === 0) ? "none" : "";
            // Write only on change, so the watcher below cannot loop.
            if (widget.style.display !== want) { widget.style.display = want; }
        });
    }

    var hideQueued = false;
    function queueHide() {
        if (hideQueued) { return; }
        hideQueued = true;
        window.requestAnimationFrame(function () {
            hideQueued = false;
            run(hideEmptyFilters);
        });
    }

    /* ---- 3. Pop-out filter panel (was: Pop Out Filters) ---- */

    var SVGNS = "http://www.w3.org/2000/svg";

    function icon(lines) {
        var svg = document.createElementNS(SVGNS, "svg");
        svg.setAttribute("viewBox", "0 0 24 24");
        svg.setAttribute("fill", "none");
        svg.setAttribute("stroke", "currentColor");
        svg.setAttribute("stroke-width", "2");
        svg.setAttribute("stroke-linecap", "round");
        svg.setAttribute("stroke-linejoin", "round");
        svg.setAttribute("aria-hidden", "true");
        lines.forEach(function (p) {
            var line = document.createElementNS(SVGNS, "line");
            line.setAttribute("x1", p[0]);
            line.setAttribute("y1", p[1]);
            line.setAttribute("x2", p[2]);
            line.setAttribute("y2", p[3]);
            svg.appendChild(line);
        });
        return svg;
    }

    function popOut() {
        var sidebar = document.querySelector(SIDEBAR);
        if (!sidebar) { return; }

        // Only relocate to <body> on mobile, where the panel is off-canvas.
        if (window.matchMedia("(max-width: 1024px)").matches) {
            document.body.appendChild(sidebar);
        }

        var backdrop = document.createElement("div");
        backdrop.className = "exc-filter-backdrop";
        document.body.appendChild(backdrop);

        var closeBtn = document.createElement("button");
        closeBtn.type = "button";
        closeBtn.className = "exc-filter-close";
        closeBtn.setAttribute("aria-label", "Close filters");
        closeBtn.appendChild(icon([[18, 6, 6, 18], [6, 6, 18, 18]]));
        sidebar.insertBefore(closeBtn, sidebar.firstChild);

        // Always the floating button, clear of the sort dropdown.
        var toggle = document.createElement("button");
        toggle.type = "button";
        toggle.className = "exc-filter-toggle exc-filter-toggle--floating";
        toggle.appendChild(icon([[4, 6, 20, 6], [7, 12, 17, 12], [10, 18, 14, 18]]));
        toggle.appendChild(document.createTextNode(" Filters"));
        document.body.appendChild(toggle);

        function open() { document.body.classList.add("exc-filters-open"); }
        function close() { document.body.classList.remove("exc-filters-open"); }

        toggle.addEventListener("click", open);
        closeBtn.addEventListener("click", close);
        backdrop.addEventListener("click", close);
        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape") { close(); }
        });
    }

    /* ---- 4. Scroll to the grid after AJAX pagination (was: Scroll to Top Pagination) ---- */

    var scrollPending = false;

    document.addEventListener("click", function (e) {
        var t = e.target;
        if (t && t.closest && t.closest("nav.woocommerce-pagination a, .jet-filters-pagination__item, .jet-filters-pagination__link")) {
            scrollPending = true;
        }
    }, true);

    function scrollToProducts() {
        if (!scrollPending) { return; }
        scrollPending = false;
        var target = document.querySelector("ul.products")
                  || document.querySelector(".exc-search-main")
                  || document.body;
        var offset = 110; // allowance for the sticky header
        var top = target.getBoundingClientRect().top + window.pageYOffset - offset;
        var reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        window.scrollTo({ top: Math.max(top, 0), behavior: reduce ? "auto" : "smooth" });
    }

    /* ---- 5. Remember the page across back navigation (was: Remember Page via Storage) ---- */

    var PAGE_KEY = "excArchivePage:" + location.pathname;

    function currentPage() {
        var cur = document.querySelector(".woocommerce-pagination .page-numbers.current");
        if (cur) {
            var n = parseInt(cur.textContent.trim(), 10);
            if (!isNaN(n)) { return n; }
        }
        return 1;
    }

    function savePage() {
        var p = currentPage();
        if (p > 1) { store("set", PAGE_KEY, p); }
        else { store("remove", PAGE_KEY); }
    }

    function restorePage() {
        var want = parseInt(store("get", PAGE_KEY), 10);
        if (isNaN(want) || want <= 1 || currentPage() === want) { return; }
        var links = document.querySelectorAll(".woocommerce-pagination a.page-numbers");
        for (var i = 0; i < links.length; i++) {
            // Match by visible number, skipping prev/next arrows.
            if (links[i].textContent.trim() === String(want)) {
                links[i].click();
                return;
            }
        }
    }

    if (CFG.remember) {
        window.addEventListener("pagehide", function () { run(savePage); });
        document.addEventListener("click", function (e) {
            var t = e.target;
            if (t && t.closest && t.closest("ul.products li.product a")) { run(savePage); }
        }, true);
        window.addEventListener("pageshow", function () {
            setTimeout(function () { run(restorePage); }, 350);
        });
    }

    /* ---- Wiring ---- */

    document.addEventListener("input", function (e) {
        var t = e.target;
        if (t && t.matches && t.matches(SIDEBAR + " .jet-range input[type=range]")) {
            run(updateRangeDots);
        }
    });

    // JetSmartFilters fires these as jQuery events after redrawing.
    $(document).on("jet-filter-content-rendered", function () {
        setTimeout(function () { run(scrollToProducts); }, 50);
    });
    $(document).on("jet-filter-content-rendered jet-smart-filters-content-rendered jet-smart-filters/filters/inited", function () {
        run(updateRangeDots);
        queueHide();
    });

    $(function () {
        if (CFG.popout) { run(popOut); }
        run(updateRangeDots);
        run(hideEmptyFilters);

        // Filter options can also change without a redraw event
        // (live counts), so watch the sidebar as the old snippet did.
        var sidebar = document.querySelector(SIDEBAR);
        if (sidebar && window.MutationObserver) {
            new MutationObserver(queueHide).observe(sidebar, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ["style", "class"]
            });
        }
    });
})(jQuery);
';
    }
}

if ( ! function_exists( 'exc_af_enqueue' ) ) {
    function exc_af_enqueue() {
        if ( ! function_exists( 'exc_af_config' ) || ! function_exists( 'exc_af_js' ) ) {
            return;
        }

        $config = exc_af_config();
        if ( empty( $config ) ) {
            return;
        }

        // A handle with no file, so the inline code loads after jQuery.
        wp_register_script( 'exc-archive-filters', false, array( 'jquery' ), '1', true );
        wp_enqueue_script( 'exc-archive-filters' );
        wp_add_inline_script( 'exc-archive-filters', 'window.excArchiveFilters = ' . wp_json_encode( $config ) . ';', 'before' );
        wp_add_inline_script( 'exc-archive-filters', exc_af_js() );
    }
}

add_action( 'wp_enqueue_scripts', 'exc_af_enqueue', 20 );
