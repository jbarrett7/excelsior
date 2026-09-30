// EXC Legacy Query Params (v2)
// 301 legacy Magento sort/filter params off .html URLs.
// Keeps paged, orderby, utm, gclid etc. Skips add-to-cart requests.
// Product pages go straight to the canonical URL via exc_build_product_url().
// Added 23 Sept 2026 (Atherstone Digital).
// v2, 30 Sept 2026: JetSmartFilters requests are never redirected. v1 listed
// jsf_ajax as a legacy param, which 301'd the JSF "Self" AJAX request to a
// plain page and stopped archive pagination working. Any request carrying a
// jsf or jsf_* parameter now passes straight through.
// Pure ASCII.

if ( ! function_exists( 'exc_lqp_is_jsf_request' ) ) {
    function exc_lqp_is_jsf_request() {
        foreach ( array_keys( $_GET ) as $exc_key ) {
            $exc_key = (string) $exc_key;
            if ( 'jsf' === $exc_key || 0 === strpos( $exc_key, 'jsf_' ) ) {
                return true;
            }
        }
        return false;
    }
}

if ( ! function_exists( 'exc_lqp_redirect' ) ) {
    function exc_lqp_redirect() {
        $exc_needs = array( 'is_admin', 'wp_doing_ajax', 'wp_unslash', 'is_singular', 'home_url', 'add_query_arg', 'wp_safe_redirect', 'exc_lqp_is_jsf_request' );
        foreach ( $exc_needs as $exc_fn ) {
            if ( ! function_exists( $exc_fn ) ) return;
        }

        if ( is_admin() || wp_doing_ajax() ) return;
        if ( 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) return;
        if ( isset( $_GET['add-to-cart'] ) ) return;
        if ( exc_lqp_is_jsf_request() ) return;

        $path = explode( '?', isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' );
        $path = $path[0];
        if ( ! preg_match( '#\.html$#', $path ) ) return;

        $legacy = array( 'dir', 'order', 'mode', 'limit', 'p', 'cat', 'price', 'ajaxcatalog', 'q' );
        $get    = wp_unslash( $_GET );
        if ( ! array_intersect_key( $get, array_flip( $legacy ) ) ) return;

        $keep = array_filter( array_diff_key( $get, array_flip( $legacy ) ), 'is_scalar' );

        if ( is_singular( 'product' ) && function_exists( 'exc_build_product_url' ) && function_exists( 'get_queried_object' ) ) {
            $target = exc_build_product_url( get_queried_object() );
        } else {
            $target = home_url( $path );
        }

        if ( $keep ) {
            $target = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $keep ) ), $target );
        }

        wp_safe_redirect( $target, 301 );
        exit;
    }
}

add_action( 'template_redirect', 'exc_lqp_redirect', 1 );
