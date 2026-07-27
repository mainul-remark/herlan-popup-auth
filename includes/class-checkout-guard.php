<?php
defined( 'ABSPATH' ) || exit;

/**
 * Renders a non-blocking email-verification reminder bar on the checkout
 * page for logged-in users whose account email's domain requires
 * verification (Auth_Popup_Email_Verification) and isn't currently
 * verified. Placing an order is never blocked here — payment methods that
 * require a verified email (e.g. Herlan Pay Later) are responsible for
 * hiding themselves via Auth_Popup_Email_Verification::is_verified().
 */
class Auth_Popup_Checkout_Guard {

    public static function init(): void {
        // Priority 9: right before the theme's .promo-bar (priority 10 on
        // the same hook), so this bar renders directly above it.
        add_action( 'shoptimizer_before_header', [ __CLASS__, 'render_bar' ], 9 );
    }

    /**
     * Fires in the theme header, before <header>, on every page — so this
     * only prints on the checkout page itself, not the order-received
     * endpoint (verification is irrelevant once the order is placed).
     */
    public static function render_bar(): void {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            return;
        }

        $user = wp_get_current_user();
        if ( ! Auth_Popup_Email_Verification::needs_verification( $user->ID, $user->user_email ) ) {
            return;
        }

        require AUTH_POPUP_PATH . 'public/views/checkout-email-verify.php';
    }
}
