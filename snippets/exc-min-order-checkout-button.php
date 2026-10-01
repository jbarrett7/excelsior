/**
 * EXC Min Order Checkout Button
 * ------------------------------------------------------------
 * WPCode PHP Snippet. Run Everywhere, Auto Insert, priority 10.
 * No opening PHP tag. Pure ASCII.
 *
 * Requires the "Minimum Order" snippet (exc_min_order_shortfall).
 * Styled by the "EXC Basket Page" CSS snippet (.exc-checkout-locked).
 *
 * Under the minimum, the basket's "Proceed to checkout" link is
 * replaced by a locked button saying how much more to add.
 *
 * It is printed inside the basket totals, which WooCommerce redraws
 * after every quantity or shipping change, so it unlocks the moment
 * the minimum is met. No reload needed. This replaces the CSS-only
 * approach (body.exc-min-order-not-met), which never matched and
 * would have gone stale after an AJAX update anyway.
 *
 * Checkout itself is still enforced server side by the Minimum Order
 * snippet. This only changes what the customer sees on the basket.
 */

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
