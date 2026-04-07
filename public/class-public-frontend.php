<?php
defined( 'ABSPATH' ) || exit;

class Auth_Popup_Public_Frontend {

    public static function init(): void {
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_footer',          [ __CLASS__, 'render_popup'   ] );
        add_shortcode( 'auth_popup_button', [ __CLASS__, 'shortcode_button' ] );

        // Re-add WooCommerce account navigation on mobile sub-pages.
        // The herlan-theme removes it on all account sub-pages (orders, my-points, etc.)
        // so auth-popup's nav drawer has nothing to slide in. Running at priority 100
        // ensures this runs after the theme's priority-10 removal.
        add_action( 'wp', [ __CLASS__, 'restore_mobile_account_navigation' ], 100 );

        // Pre-fill WooCommerce checkout shipping fields from user's default address
        add_filter( 'woocommerce_checkout_get_value', [ __CLASS__, 'prefill_checkout_shipping' ], 10, 2 );

        // Override checkout form template to reorder sections
        add_filter( 'woocommerce_locate_template', [ __CLASS__, 'locate_checkout_template' ], 10, 3 );

        // Checkout appearance settings
        if ( Auth_Popup_Core::get_setting( 'checkout_disable_ship_to_different', '1' ) === '1' ) {
            add_filter( 'woocommerce_cart_needs_shipping_address', '__return_false' );
            add_filter( 'woocommerce_ship_to_different_address_checked', '__return_false' );
        }
    }

    /**
     * Re-add the WooCommerce account navigation on mobile account sub-pages.
     *
     * The herlan-theme removes `woocommerce_account_navigation` on mobile when
     * visiting any account sub-page (orders, my-points, etc.), leaving the DOM
     * without a `.woocommerce-MyAccount-navigation` element. Auth-popup's nav
     * drawer needs that element to exist so it can slide it in. Running at
     * priority 100 guarantees we run after the theme's priority-10 removal.
     */
    public static function restore_mobile_account_navigation(): void {
        if ( ! wp_is_mobile() || ! is_user_logged_in() || ! is_account_page() ) {
            return;
        }
        if ( ! has_action( 'woocommerce_account_navigation', 'woocommerce_account_navigation' ) ) {
            add_action( 'woocommerce_account_navigation', 'woocommerce_account_navigation' );
        }
    }

    public static function enqueue_assets(): void {
        $s = get_option( 'auth_popup_settings', Auth_Popup_Core::default_settings() );

        // Main stylesheet
        wp_enqueue_style(
            'auth-popup',
            AUTH_POPUP_URL . 'assets/css/auth-popup.css',
            [],
            AUTH_POPUP_VERSION
        );

        // Google Identity Services — load whenever Google login is enabled
        if ( ! empty( $s['enable_google'] ) ) {
            wp_enqueue_script( 'google-gsi', 'https://accounts.google.com/gsi/client', [], null, true );
        }

        // Facebook SDK — load whenever Facebook login is enabled
        if ( ! empty( $s['enable_facebook'] ) ) {
            wp_enqueue_script( 'facebook-sdk', 'https://connect.facebook.net/en_US/sdk.js', [], null, true );
        }

        // Main JS
        wp_enqueue_script(
            'auth-popup',
            AUTH_POPUP_URL . 'assets/js/auth-popup.js',
            [ 'jquery' ],
            AUTH_POPUP_VERSION,
            true
        );

        // Address manager stylesheet + script (logged-in users only)
        if ( is_user_logged_in() ) {
            wp_enqueue_style(
                'auth-address-manager',
                AUTH_POPUP_URL . 'assets/css/address-manager.css',
                [ 'auth-popup' ],
                AUTH_POPUP_VERSION
            );

            wp_enqueue_script(
                'auth-address-manager',
                AUTH_POPUP_URL . 'assets/js/address-manager.js',
                [ 'jquery', 'auth-popup' ],
                AUTH_POPUP_VERSION,
                true
            );

            wp_localize_script( 'auth-address-manager', 'AuthAddressManager', [
                'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
                'nonce'            => wp_create_nonce( 'auth_popup_nonce' ),
                'isCheckout'       => function_exists( 'is_checkout' ) && is_checkout() ? '1' : '0',
                'hideShippingForm' => Auth_Popup_Core::get_setting( 'checkout_hide_shipping_form', '1' ),
                'i18n'       => [
                    'add_new'        => __( 'Add New Address', 'auth-popup' ),
                    'save'           => __( 'Save Address', 'auth-popup' ),
                    'saving'         => __( 'Saving…', 'auth-popup' ),
                    'cancel'         => __( 'Cancel', 'auth-popup' ),
                    'add_address'    => __( 'Add Address', 'auth-popup' ),
                    'edit_address'   => __( 'Edit Address', 'auth-popup' ),
                ],
            ] );
        }

        // Localise data for JS
        wp_localize_script( 'auth-popup', 'AuthPopup', [
            'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'auth_popup_nonce' ),
            'redirectUrl'     => esc_url( ( is_ssl() ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ),
            'triggerSelector' => $s['trigger_selector'] ?? '.auth-popup-trigger',
            'googleClientId'  => $s['enable_google'] === '1' ? ( $s['google_client_id'] ?? '' ) : '',
            'facebookAppId'   => $s['enable_facebook'] === '1' ? ( $s['fb_app_id'] ?? '' ) : '',
            'enableGoogle'    => $s['enable_google'],
            'enableFacebook'  => $s['enable_facebook'],
            'enablePassword'  => $s['enable_password_login'],
            'enableOtp'       => $s['enable_otp_login'],
            'isLoggedIn'      => is_user_logged_in() ? '1' : '0',
            'displayName'     => is_user_logged_in() ? wp_get_current_user()->display_name : '',
            'isAccountPage'   => ( function_exists( 'wc_get_page_id' ) && self::is_myaccount_url() ) ? '1' : '0',
            'loyaltyNonce'    => wp_create_nonce( 'herlan_loyalty_nonce' ),
            'myAccountUrl'    => function_exists( 'wc_get_page_permalink' )
                                    ? wc_get_page_permalink( 'myaccount' )
                                    : home_url( '/my-account/' ),
            'i18n'            => [
                'sending'        => __( 'Sending…', 'auth-popup' ),
                'send_otp'       => __( 'Send OTP', 'auth-popup' ),
                'resend_in'      => __( 'Resend in', 'auth-popup' ),
                'resend_otp'     => __( 'Resend OTP', 'auth-popup' ),
                'logging_in'     => __( 'Logging in…', 'auth-popup' ),
                'registering'    => __( 'Creating account…', 'auth-popup' ),
                'success'        => __( 'Success!', 'auth-popup' ),
                'error_network'  => __( 'Network error. Please try again.', 'auth-popup' ),
            ],
        ] );
    }

    public static function render_popup(): void {
        if ( is_user_logged_in() ) {
            return; // No popup needed for logged-in users
        }
        require AUTH_POPUP_PATH . 'public/views/popup.php';
    }

    /**
     * Pre-fill WooCommerce checkout shipping fields from the user's default
     * address when WooCommerce has no stored value for that field yet.
     *
     * @param mixed  $value Current field value (null if not set by WC).
     * @param string $key   Checkout field key, e.g. "shipping_first_name".
     * @return mixed
     */
    /**
     * Point WooCommerce at our plugin template for checkout/form-checkout.php.
     * Only overrides when the theme has no template of its own.
     */
    public static function locate_checkout_template( string $template, string $template_name, string $template_path ): string {
        if ( 'checkout/form-checkout.php' !== $template_name ) {
            return $template;
        }

        // If the active theme already overrides this template, respect it
        $theme_template = locate_template( [
            WC()->template_path() . $template_name,
            $template_name,
        ] );
        if ( $theme_template ) {
            return $theme_template;
        }

        $plugin_template = AUTH_POPUP_PATH . 'templates/' . $template_name;
        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }

        return $template;
    }

    public static function prefill_checkout_shipping( $value, string $key ) {
        // Only act when WooCommerce has no stored value yet
        if ( null !== $value && '' !== $value ) {
            return $value;
        }
        if ( ! is_user_logged_in() ) {
            return $value;
        }

        // Map checkout field keys to address table columns
        // Only the 4 visible fields used by herlan-theme checkout
        $map = [
            'billing_first_name' => 'first_name',
            'billing_phone'      => 'phone',
            'billing_address_1'  => 'address_1',
            'billing_state'      => 'state',
        ];

        if ( ! isset( $map[ $key ] ) ) {
            return $value;
        }

        $default = Auth_Popup_Address_Manager::get_default( get_current_user_id() );
        $col     = $map[ $key ];

        if ( $default && ! empty( $default[ $col ] ) ) {
            return $default[ $col ];
        }

        return $value;
    }

    /**
     * Returns true when the current request URL begins with the my-account
     * page URL — covers the dashboard AND all sub-pages/custom plugin endpoints.
     */
    private static function is_myaccount_url(): bool {
        $page_id = wc_get_page_id( 'myaccount' );
        if ( ! $page_id ) return false;
        $base = trailingslashit( get_permalink( $page_id ) );
        $current = ( is_ssl() ? 'https' : 'http' )
                 . '://' . $_SERVER['HTTP_HOST']
                 . strtok( $_SERVER['REQUEST_URI'], '?' );
        return strpos( trailingslashit( $current ), $base ) === 0;
    }

    public static function shortcode_button( array $atts ): string {
        $atts = shortcode_atts( [
            'label' => __( 'Login / Register', 'auth-popup' ),
            'class' => '',
        ], $atts, 'auth_popup_button' );

        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            $label = sprintf( __( 'Hello, %s', 'auth-popup' ), esc_html( $user->display_name ) );
            return '<span class="auth-popup-logged-in">' . $label . '</span>';
        }

        $class = 'auth-popup-trigger ' . esc_attr( $atts['class'] );
        return '<button type="button" class="' . trim( $class ) . '" data-auth-popup="true">'
            . esc_html( $atts['label'] )
            . '</button>';
    }
}

/**
 * Template tag for use in themes.
 */
function auth_popup_trigger_button( string $label = '', string $class = '' ): void {
    if ( empty( $label ) ) {
        $label = __( 'Login / Register', 'auth-popup' );
    }
    echo do_shortcode( '[auth_popup_button label="' . esc_attr( $label ) . '" class="' . esc_attr( $class ) . '"]' );
}
