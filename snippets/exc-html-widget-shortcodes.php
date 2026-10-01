/**
 * EXC Shortcodes in Elementor HTML Widgets
 * ------------------------------------------------------------
 * WPCode PHP Snippet. Run Everywhere, Auto Insert, priority 10.
 * No opening PHP tag. Pure ASCII.
 *
 * Lets Elementor's HTML widget run shortcodes, e.g. [exc_archive].
 * Not needed if the archive uses Elementor's Shortcode widget.
 *
 * Only the HTML widget: the Text Editor widget already runs shortcodes
 * itself. Uses instanceof rather than method_exists, which the
 * Cloudflare firewall tends to flag when WPCode saves.
 */

if ( ! function_exists( 'exc_html_widget_shortcodes' ) ) {
    function exc_html_widget_shortcodes( $content, $widget ) {
        if ( ! function_exists( 'do_shortcode' ) || ! ( $widget instanceof \Elementor\Widget_Base ) ) {
            return $content;
        }
        if ( 'html' !== $widget->get_name() ) {
            return $content;
        }
        if ( false === strpos( (string) $content, '[' ) ) {
            return $content;
        }
        return do_shortcode( $content );
    }
}

add_filter( 'elementor/widget/render_content', 'exc_html_widget_shortcodes', 10, 2 );
