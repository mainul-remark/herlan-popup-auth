<?php
defined( 'ABSPATH' ) || exit;

/**
 * Blocks WooCommerce order placement for logged-in users whose account
 * email's domain requires verification (Auth_Popup_Email_Verification) and
 * isn't currently verified. Renders a verification widget above the
 * checkout form so shoppers can resolve it without losing checkout progress.
 */
class Auth_Popup_Checkout_Guard {

    public static function init(): void {
        add_action( 'woocommerce_after_checkout_validation', [ __CLASS__, 'validate' ], 10, 2 );
        add_action( 'woocommerce_before_checkout_form',       [ __CLASS__, 'render_widget' ], 15 );
    }

    /**
     * Fires after WooCommerce's own checkout POST validation, before the
     * order is created. Adding an error here blocks order creation and
     * WooCommerce automatically re-renders the checkout with the notice.
     */
    public static function validate( array $data, \WP_Error $errors ): void {
        if ( ! is_user_logged_in() ) {
            return; // guest checkout is out of scope for this feature
        }

        $user  = wp_get_current_user();
        $email = $user->user_email;

        if ( ! Auth_Popup_Email_Verification::is_domain_required( $email ) ) {
            return;
        }

        if ( Auth_Popup_Email_Verification::is_verified( $user->ID, $email ) ) {
            return;
        }

        // Best-effort: ensure a live code exists. Ignore rate-limit errors —
        // a still-valid code may already have been sent.
        Auth_Popup_Email_Verification::send_code( $user->ID, $email );

        $errors->add(
            'auth_popup_email_unverified',
            sprintf(
                /* translators: %s: account email address */
                __( 'Please verify your account email (%s) before placing your order. Enter the 6-digit code sent to your inbox in the box above the checkout form, then click "Place order" again.', 'auth-popup' ),
                esc_html( $email )
            )
        );
    }

    /**
     * Renders the verification widget outside form.checkout (via
     * woocommerce_before_checkout_form) so it survives repeated failed
     * submits without WooCommerce's checkout.js wiping or duplicating it.
     */
    public static function render_widget(): void {
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
