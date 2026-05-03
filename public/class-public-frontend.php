<?php
defined( 'ABSPATH' ) || exit;

class Auth_Popup_Public_Frontend {

    public static function init(): void {
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_footer',          [ __CLASS__, 'render_popup'   ] );
        add_action( 'send_headers',       [ __CLASS__, 'send_google_oauth_headers' ] );
        add_action( 'template_redirect',  [ __CLASS__, 'maybe_no_cache_login_redirect' ], 0 );
        add_action( 'wp_ajax_auth_popup_upload_avatar', [ __CLASS__, 'ajax_upload_avatar' ] );
        add_action( 'wp_ajax_auth_popup_order_items',   [ __CLASS__, 'ajax_order_items'   ] );
        add_shortcode( 'auth_popup_button', [ __CLASS__, 'shortcode_button'      ] );
        add_shortcode( 'auth_popup_form',   [ __CLASS__, 'shortcode_inline_form' ] );


        // Pre-fill WooCommerce checkout shipping fields from user's default address
        add_filter( 'woocommerce_checkout_get_value', [ __CLASS__, 'prefill_checkout_shipping' ], 10, 2 );

        // Override checkout form template to reorder sections
        add_filter( 'woocommerce_locate_template', [ __CLASS__, 'locate_checkout_template' ], 10, 3 );

        // Replace WC default edit-address page with our custom address book.
        // Priority 0 starts buffering before WC renders anything; PHP_INT_MAX
        // discards that output and injects our container instead.
        add_action( 'woocommerce_account_edit-address_endpoint', [ __CLASS__, 'address_book_ob_start' ], 0 );
        add_action( 'woocommerce_account_edit-address_endpoint', [ __CLASS__, 'address_book_ob_end'   ], PHP_INT_MAX );

        // Checkout appearance settings
        if ( Auth_Popup_Core::get_setting( 'checkout_disable_ship_to_different', '1' ) === '1' ) {
            add_filter( 'woocommerce_cart_needs_shipping_address', '__return_false' );
            add_filter( 'woocommerce_ship_to_different_address_checked', '__return_false' );
        }
    }

    public static function send_google_oauth_headers(): void {
        if ( headers_sent() ) {
            return;
        }

        header( 'Cross-Origin-Opener-Policy: same-origin-allow-popups' );
    }

    public static function maybe_no_cache_login_redirect(): void {
        if ( isset( $_GET['ap_logged_in'] ) ) {
            if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                define( 'DONOTCACHEPAGE', true );
            }
            nocache_headers();
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
        wp_enqueue_script( 'jquery-ui-datepicker' );

        wp_enqueue_script(
            'auth-popup',
            AUTH_POPUP_URL . 'assets/js/auth-popup.js',
            [ 'jquery', 'jquery-ui-datepicker' ],
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
                'isMyAccount'      => function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'edit-address' ) ? '1' : '0',
                'hideShippingForm' => Auth_Popup_Core::get_setting( 'checkout_hide_shipping_form', '1' ),
                'i18n'       => [
                    'add_new'        => __( 'Add New Address', 'auth-popup' ),
                    'save'           => __( 'Save Address', 'auth-popup' ),
                    'saving'         => __( 'Saving…', 'auth-popup' ),
                    'cancel'         => __( 'Cancel', 'auth-popup' ),
                    'add_address'    => __( 'Add Address', 'auth-popup' ),
                    'edit_address'   => __( 'Edit Address', 'auth-popup' ),
                    'delete_confirm' => __( 'Delete this address? This cannot be undone.', 'auth-popup' ),
                    'set_default'    => __( 'Set as Default', 'auth-popup' ),
                    'default_badge'  => __( 'Default', 'auth-popup' ),
                    'my_addresses'   => __( 'My Addresses', 'auth-popup' ),
                    'no_addresses'   => __( 'No saved addresses yet. Add your first address below.', 'auth-popup' ),
                ],
            ] );
        }

        // Localise data for JS
        $is_inline_page = Auth_Popup_Core::get_setting( 'myaccount_inline_form', '1' ) === '1'
                          && function_exists( 'is_account_page' ) && is_account_page()
                          && ! is_wc_endpoint_url()
                          && ! is_user_logged_in();

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
            'accountSummary'  => self::get_account_summary(),
            'loyaltyNonce'    => wp_create_nonce( 'herlan_loyalty_nonce' ),
            'isInlineForm'    => $is_inline_page ? '1' : '0',
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
            return;
        }
        // Skip popup overlay on my-account page when inline form is enabled
        if ( Auth_Popup_Core::get_setting( 'myaccount_inline_form', '1' ) === '1'
             && function_exists( 'is_account_page' ) && is_account_page()
             && ! is_wc_endpoint_url() ) {
            return;
        }
        require AUTH_POPUP_PATH . 'public/views/popup.php';
    }

    public static function shortcode_inline_form(): string {
        if ( is_user_logged_in() ) {
            return '';
        }
        ob_start();
        require AUTH_POPUP_PATH . 'public/views/inline-form.php';
        return ob_get_clean();
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
        $handled = [ 'checkout/form-checkout.php', 'myaccount/edit-address.php' ];

        // Override WC login form with inline auth form when setting is enabled
        if ( Auth_Popup_Core::get_setting( 'myaccount_inline_form', '1' ) === '1'
             && ! is_user_logged_in() ) {
            $handled[] = 'myaccount/form-login.php';
        }

        if ( ! in_array( $template_name, $handled, true ) ) {
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

    /**
     * Start output buffering before WooCommerce renders the edit-address page.
     * Only intercepts the listing page (empty $value), not billing/shipping sub-pages.
     */
    public static function address_book_ob_start( $value ): void {
        if ( '' !== (string) $value ) {
            return;
        }
        ob_start();
    }

    /**
     * Discard whatever WooCommerce rendered and inject our custom container.
     * The JS (address-manager.js) detects isMyAccount and populates it via AJAX.
     */
    public static function address_book_ob_end( $value ): void {
        if ( '' !== (string) $value ) {
            return;
        }
        if ( ob_get_level() > 0 ) {
            ob_end_clean();
        }
        echo '<div id="aab-my-account-addresses" class="aab-ma-wrap">'
            . '<div class="aab-inline-loading">Loading addresses&hellip;</div>'
            . '</div>';
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

    private static function get_account_summary(): array {
        if ( ! is_user_logged_in()
             || ! function_exists( 'is_account_page' )
             || ! is_account_page() ) {
            return [];
        }

        $user_id   = get_current_user_id();
        $user      = wp_get_current_user();
        $phone     = Auth_Popup_User_Auth::get_user_phone( $user_id );
        $addresses = Auth_Popup_Address_Manager::get_addresses( $user_id );

        $order_count = 0;
        if ( function_exists( 'wc_get_customer_order_count' ) ) {
            $order_count = (int) wc_get_customer_order_count( $user_id );
        }

        $wishlist_count = 0;
        if ( function_exists( 'yith_wcwl_count_products' ) ) {
            $wishlist_count = (int) yith_wcwl_count_products();
        } elseif ( function_exists( 'tinv_wishlist_get' ) ) {
            $wishlist = tinv_wishlist_get();
            if ( is_object( $wishlist ) && method_exists( $wishlist, 'count_products' ) ) {
                $wishlist_count = (int) $wishlist->count_products();
            }
        }

        return [
            'name'          => $user->display_name ?: $user->user_login,
            'email'         => $user->user_email,
            'phone'         => $phone,
            'avatarUrl'     => self::get_user_avatar_url( $user_id ),
            'orderCount'    => $order_count,
            'addressCount'  => count( $addresses ),
            'wishlistCount' => $wishlist_count,
        ];
    }

    private static function get_user_avatar_url( int $user_id ): string {
        global $wpdb;

        $meta_keys = [
            'profile_image',
            'profile_image_url',
            'avatar',
            'avatar_url',
            'user_avatar',
            'user_avatar_url',
            'wp_user_avatar',
            'simple_local_avatar',
        ];

        foreach ( $meta_keys as $key ) {
            $value = get_user_meta( $user_id, $key, true );
            if ( is_array( $value ) ) {
                $value = $value['full'] ?? $value['96'] ?? $value['url'] ?? $value['media_id'] ?? $value['attachment_id'] ?? '';
            }

            if ( is_numeric( $value ) ) {
                $url = wp_get_attachment_image_url( (int) $value, 'thumbnail' );
                if ( $url ) {
                    return $url;
                }
            }

            if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
                return esc_url_raw( $value );
            }
        }

        $profiles_table = $wpdb->prefix . 'auth_popup_user_profiles';
        $profile_avatar = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(NULLIF(google_avatar, ''), NULLIF(facebook_avatar, '')) FROM {$profiles_table} WHERE user_id = %d LIMIT 1",
            $user_id
        ) );

        if ( is_string( $profile_avatar ) && filter_var( $profile_avatar, FILTER_VALIDATE_URL ) ) {
            return esc_url_raw( $profile_avatar );
        }

        return get_avatar_url( $user_id, [ 'size' => 96 ] );
    }

    public static function ajax_upload_avatar(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Please log in again.', 'auth-popup' ) ], 401 );
        }

        check_ajax_referer( 'auth_popup_nonce', 'nonce' );

        if ( empty( $_FILES['avatar'] ) || ! is_array( $_FILES['avatar'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Please choose an image.', 'auth-popup' ) ], 400 );
        }

        $file = $_FILES['avatar'];
        if ( ! empty( $file['size'] ) && (int) $file['size'] > 3 * MB_IN_BYTES ) {
            wp_send_json_error( [ 'message' => __( 'Image must be 3MB or smaller.', 'auth-popup' ) ], 400 );
        }

        $type = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
        if ( empty( $type['type'] ) || 0 !== strpos( $type['type'], 'image/' ) ) {
            wp_send_json_error( [ 'message' => __( 'Please upload a valid image file.', 'auth-popup' ) ], 400 );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $upload = wp_handle_upload( $file, [
            'test_form' => false,
            'mimes'     => [
                'jpg|jpeg|jpe' => 'image/jpeg',
                'png'          => 'image/png',
                'gif'          => 'image/gif',
                'webp'         => 'image/webp',
            ],
        ] );

        if ( isset( $upload['error'] ) ) {
            wp_send_json_error( [ 'message' => $upload['error'] ], 400 );
        }

        $url = esc_url_raw( $upload['url'] ?? '' );
        if ( ! $url ) {
            wp_send_json_error( [ 'message' => __( 'Upload failed. Please try again.', 'auth-popup' ) ], 500 );
        }

        update_user_meta( get_current_user_id(), 'profile_image_url', $url );

        wp_send_json_success( [
            'url'     => $url,
            'message' => __( 'Photo updated.', 'auth-popup' ),
        ] );
    }

    public static function ajax_order_items(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Please log in again.', 'auth-popup' ) ], 401 );
        }

        check_ajax_referer( 'auth_popup_nonce', 'nonce' );

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid order.', 'auth-popup' ) ], 400 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || (int) $order->get_user_id() !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => __( 'Order not found.', 'auth-popup' ) ], 404 );
        }

        $items = [];
        foreach ( $order->get_items() as $item ) {
            $product   = $item->get_product();
            $thumb_id  = $product ? $product->get_image_id() : 0;
            $thumb_url = $thumb_id ? wp_get_attachment_image_url( (int) $thumb_id, 'thumbnail' ) : '';
            if ( ! $thumb_url && function_exists( 'wc_placeholder_img_src' ) ) {
                $thumb_url = wc_placeholder_img_src( 'thumbnail' );
            }

            $items[] = [
                'product_id' => $product ? (int) $product->get_id() : 0,
                'name'       => wp_strip_all_tags( $item->get_name() ),
                'qty'        => (int) $item->get_quantity(),
                'thumb'      => esc_url_raw( $thumb_url ),
            ];
        }

        wp_send_json_success( [
            'order_id' => $order_id,
            'status'   => $order->get_status(),
            'items'    => $items,
        ] );
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
