/**
 * EXC Min Order Checkout Button (v2: banner refresh)
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
 *    WooCommerce redraws, so on its own it goes stale after an update.
 *    When WooCommerce updates the basket (Update basket, remove item,
 *    undo), the server sends back the whole rebuilt basket page. This
 *    script takes the fresh banner from that response and swaps its
 *    contents in, animating the progress bar. The banner is still built
 *    only by the Minimum Order snippet; nothing is duplicated here.
 *    Shipping and coupon changes do not alter the subtotal the minimum
 *    is measured on, so the banner has nothing to update for those.
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
(function () {
    var SEL = ".exc-min-order-banner";
    var FILL = ".exc-min-order-banner__progress-fill";

    function announce(banner) {
        // Polite live region, so screen readers hear the new message.
        banner.setAttribute("aria-live", "polite");
    }

    function apply(fresh) {
        var current = document.querySelector(SEL);

        if (!fresh) {
            if (current) { current.parentNode.removeChild(current); }
            return;
        }

        if (!current) {
            var form = document.querySelector("form.woocommerce-cart-form");
            if (!form) { return; }
            current = document.importNode(fresh, true);
            announce(current);
            form.parentNode.insertBefore(current, form);
            return;
        }

        var oldFill = current.querySelector(FILL);
        var oldWidth = oldFill ? oldFill.style.width : "";

        current.className = fresh.className;
        current.innerHTML = fresh.innerHTML;

        // Slide the progress bar from its old width to the new one.
        var newFill = current.querySelector(FILL);
        if (newFill && oldWidth && window.requestAnimationFrame) {
            var target = newFill.style.width;
            newFill.style.width = oldWidth;
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () {
                    newFill.style.width = target;
                });
            });
        }
    }

    function init() {
        var $ = window.jQuery;
        if (!$ || !window.DOMParser) { return; }

        var existing = document.querySelector(SEL);
        if (existing) { announce(existing); }

        $(document).ajaxSuccess(function (event, xhr) {
            var text = (xhr && typeof xhr.responseText === "string") ? xhr.responseText : "";
            if (text.indexOf("woocommerce-cart-form") === -1) { return; }

            var doc = new DOMParser().parseFromString(text, "text/html");

            // Only trust a real basket page, not JSON or fragments that
            // happen to mention the class name.
            if (!doc.querySelector("form.woocommerce-cart-form")) { return; }

            apply(doc.querySelector(SEL));
        });
    }

    if (window.jQuery) {
        init();
    } else {
        document.addEventListener("DOMContentLoaded", init);
    }
})();
';
    }
}

if ( ! function_exists( 'exc_min_order_banner_refresh_script' ) ) {
    function exc_min_order_banner_refresh_script() {
        if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
            return;
        }
        if ( ! function_exists( 'exc_min_order_shortfall' ) || ! function_exists( 'exc_min_order_banner_refresh_js' ) ) {
            return;
        }

        echo '<script id="exc-min-order-banner-refresh">' . exc_min_order_banner_refresh_js() . '</script>' . "\n";
    }
}

add_action( 'wp_footer', 'exc_min_order_banner_refresh_script', 30 );
