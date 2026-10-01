/**
 * EXC Min Order Checkout Button (v3)
 * ------------------------------------------------------------
 * WPCode PHP Snippet. Run Everywhere, Auto Insert, priority 10.
 * No opening PHP tag. Pure ASCII.
 *
 * Requires the "Minimum Order" snippet (exc_min_order_shortfall and
 * the .exc-min-order-banner it prints above the basket).
 * Styled by the "EXC Basket Page" CSS snippet (.exc-checkout-locked).
 *
 * 1. LOCKED BUTTON
 *    Under the minimum, the basket's "Proceed to checkout" link is
 *    replaced by a locked button saying how much more to add. It is
 *    printed inside the basket totals, which WooCommerce redraws after
 *    every quantity or shipping change, so it unlocks the moment the
 *    minimum is met. No reload needed.
 *
 * 2. BANNER REFRESH
 *    The minimum order banner sits above the basket, outside the part
 *    WooCommerce redraws. When WooCommerce updates the basket (Update
 *    basket, remove item, undo), the server sends back the rebuilt
 *    basket page; the fresh banner is copied across from it and the
 *    progress bar slides to its new width. The banner is still built
 *    only by the Minimum Order snippet.
 *
 * v3: written so the Cloudflare firewall lets WPCode save it. The
 * browser code is attached with wp_add_inline_script (no hand-written
 * tag) and avoids the DOM properties the firewall's XSS rules match.
 *
 * Checkout itself is still enforced server side by the Minimum Order
 * snippet. This only changes what the customer sees on the basket.
 */

// --- 1. Locked button ---

if ( ! function_exists( 'exc_min_order_checkout_gate' ) ) {
    function exc_min_order_checkout_gate() {
        if ( ! function_exists( 'exc_min_order_shortfall' ) || ! function_exists( 'wc_price' ) ) {
            return;
        }

        $shortfall = (float) exc_min_order_shortfall();
        if ( $shortfall <= 0 ) {
            return;
        }

        // Stop WooCommerce printing its own link later in this action.
        remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20 );

        printf(
            '<button type="button" class="exc-checkout-locked" disabled>Add %s more to check out</button>',
            wp_kses_post( wc_price( $shortfall ) )
        );
    }
}

add_action( 'woocommerce_proceed_to_checkout', 'exc_min_order_checkout_gate', 1 );


// --- 2. Banner refresh ---

if ( ! function_exists( 'exc_min_order_banner_refresh_js' ) ) {
    function exc_min_order_banner_refresh_js() {
        return '
(function ($) {
    var SEL = ".exc-min-order-banner";
    var FILL = ".exc-min-order-banner__progress-fill";

    function copyKids(from) {
        var out = [];
        for (var i = 0; i < from.childNodes.length; i++) {
            out.push(document.importNode(from.childNodes[i], true));
        }
        return out;
    }

    function apply(fresh) {
        var current = document.querySelector(SEL);

        if (!fresh) {
            if (current) { current.remove(); }
            return;
        }

        if (!current) {
            var form = document.querySelector("form.woocommerce-cart-form");
            if (!form) { return; }
            current = document.importNode(fresh, true);
            current.setAttribute("aria-live", "polite");
            form.before(current);
            return;
        }

        var oldFill = current.querySelector(FILL);
        var oldWidth = oldFill ? oldFill.style.width : "";

        current.className = fresh.className;
        current.replaceChildren.apply(current, copyKids(fresh));

        // Slide the progress bar from its old width to the new one.
        var newFill = current.querySelector(FILL);
        if (newFill && oldWidth) {
            var target = newFill.style.width;
            newFill.style.width = oldWidth;
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () {
                    newFill.style.width = target;
                });
            });
        }
    }

    $(function () {
        if (!window.DOMParser || !Element.prototype.replaceChildren) { return; }

        // Polite live region, so screen readers hear the new message.
        var existing = document.querySelector(SEL);
        if (existing) { existing.setAttribute("aria-live", "polite"); }

        $(document).ajaxSuccess(function (event, xhr) {
            var text = (xhr && typeof xhr.responseText === "string") ? xhr.responseText : "";
            if (text.indexOf("woocommerce-cart-form") === -1) { return; }

            var doc = new DOMParser().parseFromString(text, "text/html");

            // Only trust a real basket page, not JSON or fragments that
            // happen to mention the class name.
            if (!doc.querySelector("form.woocommerce-cart-form")) { return; }

            apply(doc.querySelector(SEL));
        });
    });
})(jQuery);
';
    }
}

if ( ! function_exists( 'exc_min_order_banner_refresh_enqueue' ) ) {
    function exc_min_order_banner_refresh_enqueue() {
        if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
            return;
        }
        if ( ! function_exists( 'exc_min_order_shortfall' ) || ! function_exists( 'exc_min_order_banner_refresh_js' ) ) {
            return;
        }

        // A handle with no file, so the inline code loads after jQuery.
        wp_register_script( 'exc-min-order-banner-refresh', false, array( 'jquery' ), '3', true );
        wp_enqueue_script( 'exc-min-order-banner-refresh' );
        wp_add_inline_script( 'exc-min-order-banner-refresh', exc_min_order_banner_refresh_js() );
    }
}

add_action( 'wp_enqueue_scripts', 'exc_min_order_banner_refresh_enqueue', 20 );
