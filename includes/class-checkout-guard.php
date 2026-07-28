<?php
defined( 'ABSPATH' ) || exit;

/**
 * Renders a non-blocking email-verification reminder bar site-wide for
 * logged-in users who (a) hold a role allowed to use the Herlan Pay Later
 * gateway and (b) have an account email whose domain requires verification
 * (Auth_Popup_Email_Verification) and isn't currently verified. Placing an
 * order is never blocked here — payment methods that require a verified
 * email (e.g. Herlan Pay Later) are responsible for hiding themselves via
 * Auth_Popup_Email_Verification::is_verified().
 */
class Auth_Popup_Checkout_Guard {

    public static function init(): void {
        // Priority 9: right before the theme's .promo-bar (priority 10 on
        // the same hook), so this bar renders directly above it.
        add_action( 'shoptimizer_before_header', [ __CLASS__, 'render_bar' ], 9 );
    }

    /**
     * Fires in the theme header, before <header>, on every front-end page
     * except the order-received endpoint (verification is irrelevant once
     * the order is placed).
     */
    public static function render_bar(): void {
        if ( is_admin() ) {
            return;
        }

        if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            return;
        }

        // Herlan Pay Later isn't active — the bar's "enable Pay Later"
        // messaging wouldn't apply to anyone.
        if ( ! defined( 'HPL_GATEWAY_ID' ) ) {
            return;
        }

        $user = wp_get_current_user();

        $gateway_settings = get_option( 'woocommerce_' . HPL_GATEWAY_ID . '_settings', array() );
        $allowed_roles    = isset( $gateway_settings['allowed_roles'] ) ? (array) $gateway_settings['allowed_roles'] : array();
        $feature_policy   = isset( $gateway_settings['feature_policy'] ) ? $gateway_settings['feature_policy'] : '';

        if ( empty( $allowed_roles ) || ! array_intersect( (array) $user->roles, $allowed_roles ) ) {
            return;
        }

        if ( ! Auth_Popup_Email_Verification::needs_verification( $user->ID, $user->user_email ) ) {
            return;
        }

        require AUTH_POPUP_PATH . 'public/views/checkout-email-verify.php';
    }
}
