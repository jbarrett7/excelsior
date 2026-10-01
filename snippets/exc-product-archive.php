/**
 * EXC Product Archive (v2 of EXC Product Filters)
 * ------------------------------------------------------------
 * WPCode PHP Snippet. Run Everywhere, Auto Insert, priority 10.
 * No opening PHP tag. Pure ASCII.
 * Styled by the "EXC Product Archive CSS" snippet.
 *
 * ONE SHORTCODE BUILDS THE WHOLE ARCHIVE PAGE, ALL SERVER RENDERED:
 *
 *   [exc_archive]
 *     optional HTML for an About section at the bottom of the page
 *   [/exc_archive]
 *
 * Put it in a single Elementor Shortcode widget in the product archive
 * template. (Elementor's HTML widget cannot run shortcodes, so it must
 * be the Shortcode widget.) It renders, in this order:
 *   hero      breadcrumbs, H1, product count, category description
 *   filters   sidebar on desktop, drawer on tablet and phone
 *   bar       result count, active filter chips, Clear all, sort menu
 *   products  WooCommerce's own product cards, so the stock pill and
 *             collection badge snippets keep working
 *   paging    WooCommerce pagination (?paged=N on .html URLs)
 *   about     whatever HTML sits between the shortcode tags
 *
 * Filters reload the page with the choices in the address (no AJAX),
 * so back, pagination, sorting and shared links all just work.
 *
 * FILTERS (address parameters)
 *   cats=slug,slug   categories; ticking several shows either
 *   pmin / pmax      price in whole pounds, ex VAT as displayed
 *   instock=1        in stock (includes backorder)
 *   collection=1     collection-only products
 * Names avoid the legacy Magento parameters that the EXC Legacy Query
 * Params snippet redirects (price, cat, order, dir, p, q, ...).
 *
 * Options with nothing left are hidden; ticked options always stay so
 * they can be unticked. Counts respect the other active filters.
 *
 * SEO: any filtered view is noindex,follow and canonicalises to the
 * unfiltered page. Filter links carry rel="nofollow". Unfiltered pages,
 * including their pagination, stay indexable.
 *
 * The older [exc_product_filters] and [exc_filter_bar] shortcodes still
 * work, for building a page out of separate widgets instead.
 *
 * Where the archive renders, the "EXC Archive Filters" script is
 * switched off for that page, since its JetSmartFilters helpers no
 * longer apply.
 *
 * Firewall notes: the panel's form tag name is assembled with sprintf
 * and the code avoids the markup and DOM properties Cloudflare's XSS
 * rules match on save. No raw SQL: WP_Query and WordPress term APIs.
 *
 * Guards: every hook callback first calls exc_pf_ready(), which checks
 * that each WordPress / WooCommerce function used here exists.
 */

if ( ! function_exists( 'exc_pf_ready' ) ) {

    /* ---------- Setup ---------- */

    function exc_pf_ready() {
        static $ok = null;
        if ( null === $ok ) {
            $ok = true;
            $needs = array(
                'is_shop', 'is_product_category', 'is_product_tag', 'wc_get_product_visibility_term_ids',
                'wc_get_page_permalink', 'get_woocommerce_currency_symbol', 'get_term_link', 'get_term_by',
                'get_terms', 'get_term_children', 'wp_get_object_terms', 'get_queried_object', 'is_wp_error',
                'add_query_arg', 'remove_query_arg', 'sanitize_title', 'sanitize_key', 'wp_unslash',
                'number_format_i18n', 'esc_url', 'esc_html', 'esc_attr', 'get_option', 'get_post_meta',
                'wp_register_script', 'wp_enqueue_script', 'wp_add_inline_script', 'wp_dequeue_script',
                'wc_setup_loop', 'woocommerce_product_loop_start', 'woocommerce_product_loop_end',
                'wc_get_template_part', 'woocommerce_catalog_ordering', 'have_posts', 'the_post',
                'rewind_posts', 'wp_reset_postdata', 'remove_action', 'do_action', 'wpautop',
                'wp_kses_post', 'do_shortcode', 'wc_get_page_id', 'get_post', 'home_url',
            );
            foreach ( $needs as $fn ) {
                if ( ! function_exists( $fn ) ) {
                    $ok = false;
                    break;
                }
            }
        }
        return $ok;
    }

    function exc_pf_is_listing() {
        return exc_pf_ready() && ( is_shop() || is_product_category() || is_product_tag() );
    }

    function exc_pf_keys() {
        return array( 'cats', 'pmin', 'pmax', 'instock', 'collection' );
    }

    /* ---------- What the customer chose ---------- */

    function exc_pf_request() {
        static $req = null;
        if ( null !== $req ) {
            return $req;
        }
        $req = array( 'cats' => array(), 'pmin' => null, 'pmax' => null, 'instock' => false, 'collection' => false );

        // cats=a,b from our links and script; cats[]=a&cats[]=b without script.
        $raw  = isset( $_GET['cats'] ) ? wp_unslash( $_GET['cats'] ) : '';
        $list = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
        foreach ( $list as $slug ) {
            $slug = sanitize_title( (string) $slug );
            if ( '' === $slug ) {
                continue;
            }
            $term = get_term_by( 'slug', $slug, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $req['cats'][ (int) $term->term_id ] = $term->slug;
            }
        }

        foreach ( array( 'pmin', 'pmax' ) as $k ) {
            if ( isset( $_GET[ $k ] ) && is_numeric( $_GET[ $k ] ) ) {
                $req[ $k ] = max( 0, (int) floor( (float) $_GET[ $k ] ) );
            }
        }
        if ( null !== $req['pmin'] && null !== $req['pmax'] && $req['pmin'] > $req['pmax'] ) {
            $swap        = $req['pmin'];
            $req['pmin'] = $req['pmax'];
            $req['pmax'] = $swap;
        }

        $req['instock']    = ! empty( $_GET['instock'] );
        $req['collection'] = ! empty( $_GET['collection'] );
        return $req;
    }

    function exc_pf_is_filtered( $req = null ) {
        $req = ( null === $req ) ? exc_pf_request() : $req;
        return ! empty( $req['cats'] ) || null !== $req['pmin'] || null !== $req['pmax'] || $req['instock'] || $req['collection'];
    }

    /* ---------- Where we are ---------- */

    function exc_pf_context() {
        static $ctx = null;
        if ( null !== $ctx ) {
            return $ctx;
        }
        $ctx = array( 'tax' => '', 'term' => null, 'url' => '' );
        if ( is_product_category() || is_product_tag() ) {
            $term = get_queried_object();
            if ( $term && isset( $term->taxonomy ) ) {
                $ctx['tax']  = $term->taxonomy;
                $ctx['term'] = $term;
                $link        = get_term_link( $term );
                $ctx['url']  = is_wp_error( $link ) ? '' : $link;
            }
        } else {
            $ctx['url'] = wc_get_page_permalink( 'shop' );
        }
        return $ctx;
    }

    /* ---------- Query pieces, shared by the page and the counts ---------- */

    function exc_pf_collection_term() {
        static $term = null;
        if ( null === $term ) {
            $t    = get_term_by( 'slug', 'collection-only', 'product_shipping_class' );
            $term = ( $t && ! is_wp_error( $t ) ) ? $t : false;
        }
        return $term;
    }

    function exc_pf_clause( $key, $req ) {
        if ( 'cats' === $key && ! empty( $req['cats'] ) ) {
            return array(
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => array_keys( $req['cats'] ),
                'operator'         => 'IN',
                'include_children' => true,
            );
        }
        if ( 'instock' === $key && $req['instock'] ) {
            $vis = wc_get_product_visibility_term_ids();
            if ( ! empty( $vis['outofstock'] ) ) {
                return array(
                    'taxonomy' => 'product_visibility',
                    'field'    => 'term_taxonomy_id',
                    'terms'    => array( (int) $vis['outofstock'] ),
                    'operator' => 'NOT IN',
                );
            }
        }
        if ( 'collection' === $key && $req['collection'] && exc_pf_collection_term() ) {
            return array(
                'taxonomy' => 'product_shipping_class',
                'field'    => 'term_id',
                'terms'    => array( (int) exc_pf_collection_term()->term_id ),
            );
        }
        return null;
    }

    function exc_pf_price_clause( $req ) {
        $lo = $req['pmin'];
        $hi = $req['pmax'];
        if ( null !== $lo && null !== $hi ) {
            return array( 'key' => '_price', 'value' => array( $lo, $hi ), 'compare' => 'BETWEEN', 'type' => 'DECIMAL(10,2)' );
        }
        if ( null !== $lo ) {
            return array( 'key' => '_price', 'value' => $lo, 'compare' => '>=', 'type' => 'DECIMAL(10,2)' );
        }
        if ( null !== $hi ) {
            return array( 'key' => '_price', 'value' => $hi, 'compare' => '<=', 'type' => 'DECIMAL(10,2)' );
        }
        return null;
    }

    /* ---------- Filter the products on the page ---------- */

    function exc_pf_apply_main_query( $q ) {
        if ( ! exc_pf_ready() || ! ( $q instanceof WP_Query ) || ! $q->is_main_query() ) {
            return;
        }
        $req = exc_pf_request();
        if ( ! exc_pf_is_filtered( $req ) ) {
            return;
        }
        $tax = (array) $q->get( 'tax_query' );
        foreach ( array( 'cats', 'instock', 'collection' ) as $k ) {
            $clause = exc_pf_clause( $k, $req );
            if ( $clause ) {
                $tax[] = $clause;
            }
        }
        $q->set( 'tax_query', $tax );

        $price = exc_pf_price_clause( $req );
        if ( $price ) {
            $meta   = (array) $q->get( 'meta_query' );
            $meta[] = $price;
            $q->set( 'meta_query', $meta );
        }
    }

    /* ---------- Counts and price range for the panel ---------- */

    // Product IDs in this archive (as the shop shows it), plus extra clauses.
    function exc_pf_ids( $extra_tax = array(), $meta = null, $within = null, $orderby_price = '' ) {
        $ctx = exc_pf_context();
        $tax = array( 'relation' => 'AND' );
        if ( $ctx['term'] ) {
            $tax[] = array(
                'taxonomy'         => $ctx['tax'],
                'field'            => 'term_id',
                'terms'            => array( (int) $ctx['term']->term_id ),
                'include_children' => true,
            );
        }
        $vis = wc_get_product_visibility_term_ids();
        $not = array();
        if ( ! empty( $vis['exclude-from-catalog'] ) ) {
            $not[] = (int) $vis['exclude-from-catalog'];
        }
        if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! empty( $vis['outofstock'] ) ) {
            $not[] = (int) $vis['outofstock'];
        }
        if ( $not ) {
            $tax[] = array( 'taxonomy' => 'product_visibility', 'field' => 'term_taxonomy_id', 'terms' => $not, 'operator' => 'NOT IN' );
        }
        foreach ( $extra_tax as $clause ) {
            if ( $clause ) {
                $tax[] = $clause;
            }
        }

        $args = array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'fields'                 => 'ids',
            'posts_per_page'         => -1,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'suppress_filters'       => true,
            'orderby'                => 'none',
            'tax_query'              => $tax,
        );
        if ( $meta ) {
            $args['meta_query'] = array( $meta );
        }
        if ( null !== $within ) {
            $args['post__in'] = $within ? $within : array( 0 );
        }
        if ( '' !== $orderby_price ) {
            $args['posts_per_page'] = 1;
            $args['meta_key']       = '_price';
            $args['orderby']        = 'meta_value_num';
            $args['order']          = $orderby_price;
        }
        $q = new WP_Query( $args );
        return $q->posts;
    }

    function exc_pf_set( $ids ) {
        $set = array();
        foreach ( $ids as $id ) {
            $set[ (int) $id ] = true;
        }
        return $set;
    }

    // Category options: children of this category, or top level on shop/tags.
    function exc_pf_options() {
        $ctx    = exc_pf_context();
        $parent = ( $ctx['term'] && 'product_cat' === $ctx['tax'] ) ? (int) $ctx['term']->term_id : 0;
        $terms  = get_terms( array(
            'taxonomy'   => 'product_cat',
            'parent'     => $parent,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );
        if ( is_wp_error( $terms ) ) {
            return array();
        }
        $skip = (int) get_option( 'default_product_cat' );
        $out  = array();
        foreach ( $terms as $t ) {
            if ( (int) $t->term_id !== $skip ) {
                $out[ (int) $t->term_id ] = $t;
            }
        }
        return $out;
    }

    function exc_pf_matches( $id, $skip, $req, $d ) {
        if ( 'cats' !== $skip && ! empty( $req['cats'] ) ) {
            $hit = false;
            foreach ( $req['cats'] as $tid => $slug ) {
                if ( isset( $d['groups'][ $tid ][ $id ] ) ) {
                    $hit = true;
                    break;
                }
            }
            if ( ! $hit ) {
                return false;
            }
        }
        if ( 'instock' !== $skip && $req['instock'] && ! isset( $d['instock'][ $id ] ) ) {
            return false;
        }
        if ( 'collection' !== $skip && $req['collection'] && ! isset( $d['collection'][ $id ] ) ) {
            return false;
        }
        if ( 'price' !== $skip && null !== $d['price'] && ! isset( $d['price'][ $id ] ) ) {
            return false;
        }
        return true;
    }

    function exc_pf_data() {
        static $d = null;
        if ( null !== $d ) {
            return $d;
        }
        $req = exc_pf_request();
        $d   = array(
            'base' => array(), 'instock' => array(), 'collection' => array(), 'price' => null,
            'groups' => array(), 'options' => array(), 'counts' => array(),
            'n_instock' => 0, 'n_collection' => 0, 'bounds' => null, 'has_collection' => false,
        );

        $base = exc_pf_ids();
        if ( ! $base ) {
            return $d;
        }
        $d['base'] = $base;

        $stock_req      = array( 'instock' => true ) + $req;
        $d['instock']   = exc_pf_set( exc_pf_ids( array( exc_pf_clause( 'instock', $stock_req ) ) ) );
        if ( exc_pf_collection_term() ) {
            $coll_req            = array( 'collection' => true ) + $req;
            $d['collection']     = exc_pf_set( exc_pf_ids( array( exc_pf_clause( 'collection', $coll_req ) ) ) );
            $d['has_collection'] = true;
        }
        $price = exc_pf_price_clause( $req );
        if ( $price ) {
            $d['price'] = exc_pf_set( exc_pf_ids( array(), $price ) );
        }

        // Category membership, each option counting its subcategories too.
        $d['options'] = exc_pf_options();
        $groups       = array_keys( $d['options'] );
        foreach ( $req['cats'] as $tid => $slug ) {
            $groups[] = $tid;
        }
        $owner   = array();
        $include = array();
        foreach ( $groups as $gid ) {
            $d['groups'][ $gid ] = array();
            $family              = get_term_children( $gid, 'product_cat' );
            $family              = is_wp_error( $family ) ? array() : $family;
            $family[]            = $gid;
            foreach ( $family as $tid ) {
                $owner[ (int) $tid ][]    = $gid;
                $include[ (int) $tid ]    = (int) $tid;
            }
        }
        if ( $include ) {
            $rows = wp_get_object_terms( $base, 'product_cat', array(
                'fields'  => 'all_with_object_id',
                'include' => array_values( $include ),
            ) );
            if ( ! is_wp_error( $rows ) ) {
                foreach ( $rows as $row ) {
                    $tid = (int) $row->term_id;
                    if ( empty( $owner[ $tid ] ) ) {
                        continue;
                    }
                    foreach ( $owner[ $tid ] as $gid ) {
                        $d['groups'][ $gid ][ (int) $row->object_id ] = true;
                    }
                }
            }
        }

        // Counts, each ignoring its own filter.
        $for_price = array();
        foreach ( $base as $id ) {
            $id = (int) $id;
            if ( exc_pf_matches( $id, 'cats', $req, $d ) ) {
                foreach ( $d['options'] as $oid => $t ) {
                    if ( isset( $d['groups'][ $oid ][ $id ] ) ) {
                        $d['counts'][ $oid ] = ( isset( $d['counts'][ $oid ] ) ? $d['counts'][ $oid ] : 0 ) + 1;
                    }
                }
            }
            if ( isset( $d['instock'][ $id ] ) && exc_pf_matches( $id, 'instock', $req, $d ) ) {
                $d['n_instock']++;
            }
            if ( isset( $d['collection'][ $id ] ) && exc_pf_matches( $id, 'collection', $req, $d ) ) {
                $d['n_collection']++;
            }
            if ( exc_pf_matches( $id, 'price', $req, $d ) ) {
                $for_price[] = $id;
            }
        }

        // Price range of what the other filters leave.
        if ( $for_price ) {
            $low  = exc_pf_ids( array(), null, $for_price, 'ASC' );
            $high = exc_pf_ids( array(), null, $for_price, 'DESC' );
            if ( $low && $high ) {
                $lo = null;
                $hi = null;
                foreach ( (array) get_post_meta( $low[0], '_price', false ) as $v ) {
                    if ( is_numeric( $v ) && ( null === $lo || (float) $v < $lo ) ) {
                        $lo = (float) $v;
                    }
                }
                foreach ( (array) get_post_meta( $high[0], '_price', false ) as $v ) {
                    if ( is_numeric( $v ) && ( null === $hi || (float) $v > $hi ) ) {
                        $hi = (float) $v;
                    }
                }
                if ( null !== $lo && null !== $hi ) {
                    $d['bounds'] = array( (int) floor( $lo ), (int) ceil( $hi ) );
                }
            }
        }
        return $d;
    }

    /* ---------- Links ---------- */

    function exc_pf_url( $req ) {
        $ctx  = exc_pf_context();
        $args = array();
        if ( isset( $_GET['orderby'] ) ) {
            $args['orderby'] = sanitize_key( wp_unslash( $_GET['orderby'] ) );
        }
        if ( ! empty( $req['cats'] ) ) {
            $args['cats'] = implode( ',', array_values( $req['cats'] ) );
        }
        if ( null !== $req['pmin'] ) {
            $args['pmin'] = (int) $req['pmin'];
        }
        if ( null !== $req['pmax'] ) {
            $args['pmax'] = (int) $req['pmax'];
        }
        if ( $req['instock'] ) {
            $args['instock'] = 1;
        }
        if ( $req['collection'] ) {
            $args['collection'] = 1;
        }
        return $args ? add_query_arg( $args, $ctx['url'] ) : $ctx['url'];
    }

    function exc_pf_money( $n ) {
        return get_woocommerce_currency_symbol() . esc_html( number_format_i18n( (int) $n ) );
    }

    function exc_pf_chips() {
        $req   = exc_pf_request();
        $chips = array();
        $names = exc_pf_data()['options'];
        foreach ( $req['cats'] as $tid => $slug ) {
            $less = $req;
            unset( $less['cats'][ $tid ] );
            $term = isset( $names[ $tid ] ) ? $names[ $tid ] : get_term_by( 'id', $tid, 'product_cat' );
            $chips[] = array( esc_html( $term ? $term->name : $slug ), exc_pf_url( $less ) );
        }
        if ( null !== $req['pmin'] || null !== $req['pmax'] ) {
            $less         = $req;
            $less['pmin'] = null;
            $less['pmax'] = null;
            if ( null !== $req['pmin'] && null !== $req['pmax'] ) {
                $label = exc_pf_money( $req['pmin'] ) . ' &ndash; ' . exc_pf_money( $req['pmax'] );
            } elseif ( null !== $req['pmin'] ) {
                $label = 'From ' . exc_pf_money( $req['pmin'] );
            } else {
                $label = 'Up to ' . exc_pf_money( $req['pmax'] );
            }
            $chips[] = array( $label, exc_pf_url( $less ) );
        }
        if ( $req['instock'] ) {
            $less            = $req;
            $less['instock'] = false;
            $chips[]         = array( 'In stock', exc_pf_url( $less ) );
        }
        if ( $req['collection'] ) {
            $less               = $req;
            $less['collection'] = false;
            $chips[]            = array( 'Collection only', exc_pf_url( $less ) );
        }
        return $chips;
    }

    function exc_pf_clear_url() {
        return exc_pf_url( array( 'cats' => array(), 'pmin' => null, 'pmax' => null, 'instock' => false, 'collection' => false ) );
    }

    /* ---------- Script ---------- */

    function exc_pf_enqueue_once() {
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;
        // The old JetSmartFilters helpers have nothing to do here.
        wp_dequeue_script( 'exc-archive-filters' );
        wp_register_script( 'exc-product-filters', false, array(), '1', true );
        wp_enqueue_script( 'exc-product-filters' );
        wp_add_inline_script( 'exc-product-filters', exc_pf_js() );
    }

    function exc_pf_js() {
        return '
(function () {
    "use strict";

    var root = document.getElementById("exc-pf");
    if (!root) { return; }
    var form = root.querySelector(".exc-pf__form");
    if (!form) { return; }

    root.classList.add("exc-pf--js");

    var MOBILE = window.matchMedia("(max-width: 1024px)");
    var REOPEN = "excPfReopen";

    function storage(key, value) {
        try {
            if (value === undefined) {
                var v = window.sessionStorage.getItem(key);
                window.sessionStorage.removeItem(key);
                return v;
            }
            window.sessionStorage.setItem(key, value);
        } catch (err) { /* private mode: ignore */ }
        return null;
    }

    /* ---- Drawer (tablet and phone) ---- */

    if (MOBILE.matches) { document.body.appendChild(root); }

    var backdrop = document.createElement("div");
    backdrop.className = "exc-pf-backdrop";
    document.body.appendChild(backdrop);

    var lastFocus = null;
    function isOpen() { return document.body.classList.contains("exc-pf-open"); }
    function openDrawer() {
        if (MOBILE.matches && root.parentElement !== document.body) { document.body.appendChild(root); }
        lastFocus = document.activeElement;
        document.body.classList.add("exc-pf-open");
        root.setAttribute("role", "dialog");
        root.setAttribute("aria-modal", "true");
        // Focus once the drawer is visible, or the browser ignores it.
        var first = root.querySelector(".exc-pf__close");
        if (first) {
            setTimeout(function () {
                if (isOpen()) { first.focus(); }
            }, 80);
        }
    }
    function closeDrawer() {
        document.body.classList.remove("exc-pf-open");
        root.removeAttribute("role");
        root.removeAttribute("aria-modal");
        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
    }

    document.addEventListener("click", function (e) {
        var t = e.target;
        if (!t || !t.closest) { return; }
        if (t.closest("[data-exc-pf-open]")) { e.preventDefault(); openDrawer(); }
        else if (t.closest("[data-exc-pf-close]")) { e.preventDefault(); closeDrawer(); }
    });
    backdrop.addEventListener("click", closeDrawer);
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && isOpen()) { closeDrawer(); }
    });

    /* ---- Price slider ---- */

    var range = root.querySelector(".exc-pf__range");
    var bMin = 0, bMax = 0, tMin = null, tMax = null, nMin = null, nMax = null;

    function paint(a, b) {
        var span = (bMax - bMin) || 1;
        range.style.setProperty("--exc-pf-lo", ((a - bMin) / span * 100) + "%");
        range.style.setProperty("--exc-pf-hi", ((b - bMin) / span * 100) + "%");
    }

    function fromThumbs(moved) {
        var a = Number(tMin.value), b = Number(tMax.value);
        if (a > b) {
            if (moved === tMin) { a = b; tMin.value = a; } else { b = a; tMax.value = b; }
        }
        nMin.value = a;
        nMax.value = b;
        paint(a, b);
    }

    function fromNumbers() {
        var a = Math.round(Number(nMin.value)), b = Math.round(Number(nMax.value));
        if (isNaN(a)) { a = bMin; }
        if (isNaN(b)) { b = bMax; }
        a = Math.min(Math.max(a, bMin), bMax);
        b = Math.min(Math.max(b, bMin), bMax);
        if (a > b) { var s = a; a = b; b = s; }
        nMin.value = a; nMax.value = b;
        tMin.value = a; tMax.value = b;
        paint(a, b);
    }

    if (range) {
        bMin = Number(range.getAttribute("data-min"));
        bMax = Number(range.getAttribute("data-max"));
        tMin = range.querySelector(".exc-pf__thumb--min");
        tMax = range.querySelector(".exc-pf__thumb--max");
        nMin = root.querySelector(".exc-pf__num--min");
        nMax = root.querySelector(".exc-pf__num--max");
        if (tMin && tMax && nMin && nMax) {
            paint(Number(tMin.value), Number(tMax.value));
            tMin.addEventListener("input", function () { fromThumbs(tMin); });
            tMax.addEventListener("input", function () { fromThumbs(tMax); });
        } else {
            range = null;
        }
    }

    /* ---- Apply: reload the page with the new filters ---- */

    var hiddenCats = form.querySelector("[data-exc-pf-cats]");

    function apply() {
        var cats = [];
        root.querySelectorAll("input[data-exc-pf-cat]").forEach(function (cb) {
            if (cb.checked) { cats.push(cb.value); }
            cb.removeAttribute("name");
        });
        if (hiddenCats) {
            hiddenCats.value = cats.join(",");
            hiddenCats.disabled = cats.length === 0;
        }
        if (range) {
            // Leave the price out of the address when it is at the ends.
            nMin.disabled = Number(nMin.value) <= bMin;
            nMax.disabled = Number(nMax.value) >= bMax;
        }
        if (isOpen() && MOBILE.matches) { storage(REOPEN, "1"); }
        root.classList.add("is-loading");
        form.submit();
    }

    form.addEventListener("submit", function (e) {
        e.preventDefault();
        if (range) { fromNumbers(); }
        apply();
    });

    root.addEventListener("change", function (e) {
        var t = e.target;
        if (!t || !t.matches) { return; }
        if (t.matches("input[type=checkbox]")) { apply(); }
        else if (t.matches(".exc-pf__thumb")) { apply(); }
        else if (t.matches(".exc-pf__num")) { fromNumbers(); apply(); }
    });

    /* ---- Category list: find and show all ---- */

    var list = root.querySelector(".exc-pf__list");
    var find = root.querySelector(".exc-pf__find");
    if (list && find) {
        var items = list.querySelectorAll(".exc-pf__item");
        find.addEventListener("input", function () {
            var q = find.value.trim().toLowerCase();
            list.classList.toggle("is-searching", q !== "");
            items.forEach(function (li) {
                li.hidden = q !== "" && li.textContent.toLowerCase().indexOf(q) === -1;
            });
        });
    }
    var more = root.querySelector(".exc-pf__more");
    if (list && more) {
        more.addEventListener("click", function () {
            var open = list.classList.toggle("is-expanded");
            more.setAttribute("aria-expanded", open ? "true" : "false");
            more.textContent = open ? more.getAttribute("data-less") : more.getAttribute("data-more");
        });
    }

    /* ---- Keep the drawer open after a reload from inside it ---- */

    if (MOBILE.matches && storage(REOPEN) === "1") { openDrawer(); }
})();
';
    }

    /* ---------- [exc_product_filters] ---------- */

    function exc_pf_panel_shortcode() {
        if ( ! exc_pf_is_listing() ) {
            return '';
        }
        $req = exc_pf_request();
        $d   = exc_pf_data();
        $ctx = exc_pf_context();
        if ( ! $d['base'] ) {
            return '';
        }
        exc_pf_enqueue_once();

        $active = count( exc_pf_chips() );
        $groups = '';

        // Availability
        $toggles = '';
        $avail   = array(
            array( 'instock', 'In stock only', $d['n_instock'] ),
        );
        if ( $d['has_collection'] ) {
            $avail[] = array( 'collection', 'Collection only', $d['n_collection'] );
        }
        foreach ( $avail as $a ) {
            $on = $req[ $a[0] ];
            if ( ! $on && $a[2] < 1 ) {
                continue;
            }
            $toggles .= sprintf(
                '<label class="exc-pf__switch"><input type="checkbox" role="switch" name="%1$s" value="1"%2$s><span class="exc-pf__switch-ui" aria-hidden="true"></span><span class="exc-pf__label">%3$s</span><span class="exc-pf__count">%4$s</span></label>',
                esc_attr( $a[0] ),
                $on ? ' checked' : '',
                esc_html( $a[1] ),
                esc_html( number_format_i18n( $a[2] ) )
            );
        }
        if ( '' !== $toggles ) {
            $groups .= '<details class="exc-pf__group" open><summary class="exc-pf__summary">Availability</summary><div class="exc-pf__body exc-pf__body--switches">' . $toggles . '</div></details>';
        }

        // Categories
        $items   = '';
        $shown   = 0;
        $extra   = 0;
        $visible = 8;
        foreach ( $d['options'] as $tid => $t ) {
            $n  = isset( $d['counts'][ $tid ] ) ? $d['counts'][ $tid ] : 0;
            $on = isset( $req['cats'][ $tid ] );
            if ( ! $on && $n < 1 ) {
                continue;
            }
            $shown++;
            $is_extra = ( $shown > $visible && ! $on );
            if ( $is_extra ) {
                $extra++;
            }
            $items .= sprintf(
                '<li class="exc-pf__item%1$s"><label class="exc-pf__check"><input type="checkbox" name="cats[]" value="%2$s" data-exc-pf-cat%3$s><span class="exc-pf__box" aria-hidden="true"></span><span class="exc-pf__label">%4$s</span><span class="exc-pf__count">%5$s</span></label></li>',
                $is_extra ? ' is-extra' : '',
                esc_attr( $t->slug ),
                $on ? ' checked' : '',
                esc_html( $t->name ),
                esc_html( number_format_i18n( $n ) )
            );
        }
        if ( '' !== $items ) {
            $find = ( $shown > 12 )
                ? '<input type="search" class="exc-pf__find" placeholder="Find a category" aria-label="Find a category">'
                : '';
            $more = $extra
                ? sprintf(
                    '<button type="button" class="exc-pf__more" aria-expanded="false" data-more="%1$s" data-less="Show fewer">%1$s</button>',
                    esc_attr( 'Show all ' . number_format_i18n( $shown ) )
                )
                : '';
            $title = ( $ctx['term'] && 'product_cat' === $ctx['tax'] ) ? 'Category' : 'Categories';
            $groups .= '<details class="exc-pf__group" open><summary class="exc-pf__summary">' . $title . '</summary><div class="exc-pf__body">' . $find . '<ul class="exc-pf__list">' . $items . '</ul>' . $more . '</div></details>';
        }

        // Price
        if ( $d['bounds'] && $d['bounds'][1] > $d['bounds'][0] ) {
            $bmin = $d['bounds'][0];
            $bmax = $d['bounds'][1];
            $lo   = ( null !== $req['pmin'] ) ? min( max( $req['pmin'], $bmin ), $bmax ) : $bmin;
            $hi   = ( null !== $req['pmax'] ) ? max( min( $req['pmax'], $bmax ), $bmin ) : $bmax;
            $sym  = get_woocommerce_currency_symbol();
            $groups .= sprintf(
                '<details class="exc-pf__group" open><summary class="exc-pf__summary">Price <span class="exc-pf__note">ex VAT</span></summary><div class="exc-pf__body">'
                . '<div class="exc-pf__range" data-min="%1$d" data-max="%2$d"><div class="exc-pf__track" aria-hidden="true"><div class="exc-pf__fill"></div></div>'
                . '<input type="range" class="exc-pf__thumb exc-pf__thumb--min" min="%1$d" max="%2$d" step="1" value="%3$d" aria-label="Minimum price">'
                . '<input type="range" class="exc-pf__thumb exc-pf__thumb--max" min="%1$d" max="%2$d" step="1" value="%4$d" aria-label="Maximum price"></div>'
                . '<div class="exc-pf__prices"><label class="exc-pf__money"><span class="exc-pf__sym">%5$s</span><input type="number" class="exc-pf__num exc-pf__num--min" name="pmin" min="%1$d" max="%2$d" step="1" value="%3$d" inputmode="numeric" aria-label="Minimum price"></label>'
                . '<span class="exc-pf__dash" aria-hidden="true"></span>'
                . '<label class="exc-pf__money"><span class="exc-pf__sym">%5$s</span><input type="number" class="exc-pf__num exc-pf__num--max" name="pmax" min="%1$d" max="%2$d" step="1" value="%4$d" inputmode="numeric" aria-label="Maximum price"></label></div>'
                . '</div></details>',
                $bmin,
                $bmax,
                $lo,
                $hi,
                $sym
            );
        }

        if ( '' === $groups ) {
            return '';
        }

        $keep = '';
        if ( isset( $_GET['orderby'] ) ) {
            $keep .= sprintf( '<input type="hidden" name="orderby" value="%s">', esc_attr( sanitize_key( wp_unslash( $_GET['orderby'] ) ) ) );
        }
        $keep .= '<input type="hidden" name="cats" value="" data-exc-pf-cats disabled>';

        $head = sprintf(
            '<div class="exc-pf__head"><p class="exc-pf__title" id="exc-pf-title">Filters%1$s</p>%2$s<button type="button" class="exc-pf__close" data-exc-pf-close aria-label="Close filters"><span aria-hidden="true">&times;</span></button></div>',
            $active ? ' <span class="exc-pf__badge">' . esc_html( $active ) . '</span>' : '',
            $active ? '<a class="exc-pf__clear" href="' . esc_url( exc_pf_clear_url() ) . '" rel="nofollow">Clear all</a>' : ''
        );

        global $wp_query;
        $total = isset( $wp_query->found_posts ) ? (int) $wp_query->found_posts : 0;
        $foot  = sprintf(
            '<div class="exc-pf__foot"><button type="submit" class="exc-pf__apply">Apply filters</button><button type="button" class="exc-pf__done" data-exc-pf-close>Show %1$s %2$s</button></div>',
            esc_html( number_format_i18n( $total ) ),
            1 === $total ? 'product' : 'products'
        );

        // The tag name is passed in, so the firewall does not read the
        // markup in this snippet as an injected form when it is saved.
        return sprintf(
            '<aside class="exc-pf" id="exc-pf" aria-labelledby="exc-pf-title"><%1$s class="exc-pf__form" method="get" action="%2$s">%3$s%4$s<div class="exc-pf__groups">%5$s</div>%6$s</%1$s></aside>',
            'form',
            esc_url( $ctx['url'] . '#exc-products' ),
            $head,
            $keep,
            $groups,
            $foot
        );
    }

    /* ---------- [exc_filter_bar] ---------- */

    function exc_pf_bar_html( $right = '' ) {
        global $wp_query;
        $total = isset( $wp_query->found_posts ) ? (int) $wp_query->found_posts : 0;
        $chips = exc_pf_chips();
        $has_panel = ! empty( exc_pf_data()['base'] );

        $list = '';
        foreach ( $chips as $c ) {
            $list .= sprintf(
                '<li><a class="exc-pf-chip" href="%1$s" rel="nofollow">%2$s<span class="exc-pf-chip__x" aria-hidden="true">&times;</span><span class="exc-pf-sr">Remove filter</span></a></li>',
                esc_url( $c[1] ),
                $c[0]
            );
        }
        if ( $list ) {
            $list = '<ul class="exc-pf-bar__chips" aria-label="Active filters">' . $list
                . '<li><a class="exc-pf-bar__clear" href="' . esc_url( exc_pf_clear_url() ) . '" rel="nofollow">Clear all</a></li></ul>';
        }

        $open = $has_panel
            ? sprintf(
                '<button type="button" class="exc-pf-bar__open" data-exc-pf-open aria-controls="exc-pf"><span class="exc-pf-bar__icon" aria-hidden="true"></span>Filters%s</button>',
                $chips ? ' <span class="exc-pf__badge">' . esc_html( count( $chips ) ) . '</span>' : ''
            )
            : '';

        return sprintf(
            '<div class="exc-pf-bar" id="exc-products"><div class="exc-pf-bar__row">%1$s<p class="exc-pf-bar__count"><strong>%2$s</strong> %3$s</p>%4$s</div>%5$s</div>',
            $open,
            esc_html( number_format_i18n( $total ) ),
            1 === $total ? 'product' : 'products',
            '' !== $right ? '<div class="exc-pf-bar__right">' . $right . '</div>' : '',
            $list
        );
    }

    function exc_pf_bar_shortcode() {
        if ( ! exc_pf_is_listing() ) {
            return '';
        }
        return exc_pf_bar_html();
    }

    /* ---------- [exc_archive]: the whole page ---------- */

    // Run something that prints, and hand back what it printed.
    function exc_pf_capture( $fn ) {
        ob_start();
        $fn();
        return ob_get_clean();
    }

    function exc_archive_hero_html( $count ) {
        $ctx   = exc_pf_context();
        $title = '';
        $desc  = '';
        if ( $ctx['term'] ) {
            $title = $ctx['term']->name;
            $desc  = $ctx['term']->description;
        } else {
            $title   = 'Shop';
            $shop_id = wc_get_page_id( 'shop' );
            $page    = ( $shop_id > 0 ) ? get_post( $shop_id ) : null;
            $desc    = $page ? $page->post_excerpt : '';
        }

        // Breadcrumbs from the Archive Hero & Breadcrumbs snippet when it
        // is active, otherwise a plain Home / Title trail.
        $trail = function_exists( 'exc_get_breadcrumb_trail' )
            ? exc_get_breadcrumb_trail()
            : array(
                array( 'label' => 'Home', 'url' => home_url( '/' ), 'current' => false ),
                array( 'label' => $title, 'url' => '', 'current' => true ),
            );
        $crumbs = '';
        foreach ( $trail as $crumb ) {
            $crumbs .= ( $crumb['current'] || empty( $crumb['url'] ) )
                ? '<li aria-current="page">' . esc_html( $crumb['label'] ) . '</li>'
                : '<li><a href="' . esc_url( $crumb['url'] ) . '">' . esc_html( $crumb['label'] ) . '</a></li>';
        }

        $about = '';
        if ( '' !== trim( (string) $desc ) ) {
            $about = '<details class="exc-archive__desc"><summary>About this category</summary><div class="exc-archive__desc-body">'
                . wp_kses_post( wpautop( $desc ) ) . '</div></details>';
        }

        return '<header class="exc-archive__hero">'
            . '<nav class="exc-archive__crumbs" aria-label="Breadcrumb"><ol>' . $crumbs . '</ol></nav>'
            . '<h1 class="exc-archive__title">' . esc_html( $title ) . '</h1>'
            . ( $count > 0 ? '<p class="exc-archive__meta">' . esc_html( number_format_i18n( $count ) ) . ( 1 === $count ? ' product' : ' products' ) . '</p>' : '' )
            . $about
            . '</header>';
    }

    function exc_archive_loop() {
        rewind_posts();
        if ( have_posts() ) {
            // The count and sort menu live in the bar above instead.
            remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
            remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );
            do_action( 'woocommerce_before_shop_loop' );
            woocommerce_product_loop_start();
            while ( have_posts() ) {
                the_post();
                do_action( 'woocommerce_shop_loop' );
                wc_get_template_part( 'content', 'product' );
            }
            woocommerce_product_loop_end();
            do_action( 'woocommerce_after_shop_loop' );
        } else {
            do_action( 'woocommerce_no_products_found' );
            if ( exc_pf_is_filtered() ) {
                echo '<p class="exc-archive__reset"><a class="exc-archive__reset-btn" href="' . esc_url( exc_pf_clear_url() ) . '" rel="nofollow">Clear all filters</a></p>';
            }
        }
        wp_reset_postdata();
    }

    function exc_archive_shortcode( $atts = array(), $content = '' ) {
        if ( ! exc_pf_is_listing() ) {
            // Placeholder in the Elementor editor; nothing anywhere else.
            return isset( $_GET['elementor-preview'] )
                ? '<div class="exc-archive-placeholder">Product archive: hero, filters, products and pagination render here on the live site.</div>'
                : '';
        }

        wc_setup_loop();
        $d        = exc_pf_data();
        $panel    = exc_pf_panel_shortcode();
        $ordering = exc_pf_capture( function () { woocommerce_catalog_ordering(); } );
        $products = exc_pf_capture( function () { exc_archive_loop(); } );
        exc_pf_enqueue_once();

        $about = '';
        if ( '' !== trim( (string) $content ) ) {
            $about = '<section class="exc-archive__about">' . do_shortcode( $content ) . '</section>';
        }

        return '<div class="exc-archive' . ( $panel ? ' has-filters' : '' ) . '">'
            . exc_archive_hero_html( count( $d['base'] ) )
            . '<div class="exc-archive__layout">'
            . ( $panel ? '<div class="exc-archive__side">' . $panel . '</div>' : '' )
            . '<div class="exc-archive__main">'
            . exc_pf_bar_html( $ordering )
            . '<div class="exc-archive__grid">' . $products . '</div>'
            . '</div></div>'
            . $about
            . '</div>';
    }

    /* ---------- SEO ---------- */

    function exc_pf_rank_math_robots( $robots ) {
        if ( exc_pf_is_listing() && exc_pf_is_filtered() && is_array( $robots ) ) {
            $robots['index']  = 'noindex';
            $robots['follow'] = 'follow';
        }
        return $robots;
    }

    function exc_pf_wp_robots( $robots ) {
        if ( exc_pf_is_listing() && exc_pf_is_filtered() && is_array( $robots ) ) {
            $robots['noindex'] = true;
            $robots['follow']  = true;
            unset( $robots['index'] );
        }
        return $robots;
    }

    function exc_pf_canonical( $url ) {
        if ( exc_pf_ready() && is_string( $url ) && '' !== $url ) {
            return remove_query_arg( exc_pf_keys(), $url );
        }
        return $url;
    }
}

add_action( 'woocommerce_product_query', 'exc_pf_apply_main_query', 20 );
add_shortcode( 'exc_product_filters', 'exc_pf_panel_shortcode' );
add_shortcode( 'exc_filter_bar', 'exc_pf_bar_shortcode' );
add_shortcode( 'exc_archive', 'exc_archive_shortcode' );
add_filter( 'rank_math/frontend/robots', 'exc_pf_rank_math_robots', 20 );
add_filter( 'wp_robots', 'exc_pf_wp_robots', 20 );
add_filter( 'rank_math/frontend/canonical', 'exc_pf_canonical', 20 );
