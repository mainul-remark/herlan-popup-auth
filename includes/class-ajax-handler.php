<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles all AJAX endpoints for Auth Popup.
 * All actions use nonce verification and sanitised input.
 */
class Auth_Popup_Ajax_Handler {

    public static function init(): void {
        $actions = [
            'auth_popup_send_otp',
            'auth_popup_login_password',
            'auth_popup_login_otp',
            'auth_popup_register',
            'auth_popup_google_auth',
            'auth_popup_facebook_auth',
            'auth_popup_logout',
            'auth_popup_check_phone',
            'auth_popup_get_loyalty_rules',
        ];

        foreach ( $actions as $action ) {
            // Allow both logged-in and guest users
            add_action( 'wp_ajax_' . $action,        [ __CLASS__, $action ] );
            add_action( 'wp_ajax_nopriv_' . $action, [ __CLASS__, $action ] );
        }

        // Address book — logged-in users only
        $address_actions = [
            'auth_popup_get_addresses',
            'auth_popup_save_address',
            'auth_popup_delete_address',
            'auth_popup_set_default_address',
        ];
        foreach ( $address_actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, $action ] );
        }
    }

    /* ── Send OTP ───────────────────────────────────────────────────── */

    public static function auth_popup_send_otp(): void {
        self::verify_nonce();

        $phone   = sanitize_text_field( $_POST['phone']   ?? '' );
        $context = sanitize_text_field( $_POST['context'] ?? 'login' );

        if ( ! Auth_Popup_SMS_Service::is_valid_phone( $phone ) ) {
            self::error( __( 'Please enter a valid mobile number.', 'auth-popup' ) );
        }

        $phone = Auth_Popup_SMS_Service::normalise_phone( $phone );

        // For login: phone must already be registered
        if ( 'login' === $context && null === Auth_Popup_User_Auth::get_user_by_phone( $phone ) ) {
            self::error( __( 'No account found with this mobile number. Please register first.', 'auth-popup' ) );
        }

        // Generate OTP (includes rate-limit check)
        $otp = Auth_Popup_OTP_Manager::generate( $phone );
        if ( is_wp_error( $otp ) ) {
            self::error( $otp->get_error_message() );
        }

        // Send via SSLCommerce SMS
        $result = Auth_Popup_SMS_Service::send_otp( $phone, $otp );
        if ( is_wp_error( $result ) ) {
            // Invalidate so the user can retry
            Auth_Popup_OTP_Manager::invalidate( $phone );
            self::error( $result->get_error_message() );
        }

        $expiry = (int) Auth_Popup_Core::get_setting( 'otp_expiry_minutes', 5 );
        self::success( [
            'message'        => sprintf(
                /* translators: %s: phone number */
                __( 'OTP sent to %s', 'auth-popup' ),
                $phone
            ),
            'expiry_seconds' => $expiry * 60,
        ] );
    }

    /* ── Login with Password ────────────────────────────────────────── */

    public static function auth_popup_login_password(): void {
        self::verify_nonce();

        if ( is_user_logged_in() ) {
            self::success( [ 'redirect' => self::redirect_url() ] );
        }

        $credential = sanitize_text_field( $_POST['credential'] ?? '' );
        $password   = $_POST['password'] ?? '';

        if ( empty( $credential ) || empty( $password ) ) {
            self::error( __( 'Mobile/email and password are required.', 'auth-popup' ) );
        }

        $user = Auth_Popup_User_Auth::login_with_password( $credential, $password );
        if ( is_wp_error( $user ) ) {
            self::error( $user->get_error_message() );
        }

        self::success( [
            'message'  => __( 'Login successful!', 'auth-popup' ),
            'redirect' => self::redirect_url(),
        ] );
    }

    /* ── Login with OTP ─────────────────────────────────────────────── */

    public static function auth_popup_login_otp(): void {
        self::verify_nonce();

        if ( is_user_logged_in() ) {
            self::success( [ 'redirect' => self::redirect_url() ] );
        }

        $phone = sanitize_text_field( $_POST['phone'] ?? '' );
        $otp   = sanitize_text_field( $_POST['otp']   ?? '' );

        if ( ! Auth_Popup_SMS_Service::is_valid_phone( $phone ) ) {
            self::error( __( 'Invalid mobile number.', 'auth-popup' ) );
        }

        if ( strlen( $otp ) !== 6 || ! ctype_digit( $otp ) ) {
            self::error( __( 'Invalid OTP format.', 'auth-popup' ) );
        }

        $norm = Auth_Popup_SMS_Service::normalise_phone( $phone );

        if ( ! Auth_Popup_OTP_Manager::verify( $norm, $otp ) ) {
            self::error( __( 'Incorrect or expired OTP. Please try again.', 'auth-popup' ) );
        }

        $user = Auth_Popup_User_Auth::login_or_create_by_phone( $norm );
        if ( is_wp_error( $user ) ) {
            self::error( $user->get_error_message() );
        }

        self::success( [
            'message'  => __( 'Login successful!', 'auth-popup' ),
            'redirect' => self::redirect_url(),
        ] );
    }

    /* ── Register ───────────────────────────────────────────────────── */

    public static function auth_popup_register(): void {
        self::verify_nonce();

        if ( is_user_logged_in() ) {
            self::success( [ 'redirect' => self::redirect_url() ] );
        }

        $phone = sanitize_text_field( $_POST['phone']    ?? '' );
        $otp   = sanitize_text_field( $_POST['otp']      ?? '' );
        $name  = sanitize_text_field( $_POST['name']     ?? '' );
        $email = sanitize_email(      $_POST['email']    ?? '' );
        $pass  = $_POST['password'] ?? '';

        // Validate OTP first
        if ( ! Auth_Popup_SMS_Service::is_valid_phone( $phone ) ) {
            self::error( __( 'Invalid mobile number.', 'auth-popup' ) );
        }

        if ( strlen( $otp ) !== 6 || ! ctype_digit( $otp ) ) {
            self::error( __( 'Invalid OTP format.', 'auth-popup' ) );
        }

        $norm = Auth_Popup_SMS_Service::normalise_phone( $phone );

        if ( ! Auth_Popup_OTP_Manager::verify( $norm, $otp ) ) {
            self::error( __( 'Incorrect or expired OTP. Please try again.', 'auth-popup' ) );
        }

        $user = Auth_Popup_User_Auth::register( [
            'phone'    => $norm,
            'name'     => $name,
            'email'    => $email,
            'password' => $pass,
        ] );

        if ( is_wp_error( $user ) ) {
            self::error( $user->get_error_message() );
        }

        // Loyalty Programme registration (optional)
        $loyalty_message = '';
        if ( ! empty( $_POST['join_loyalty'] ) && '1' === $_POST['join_loyalty'] ) {
            $loyalty_result  = self::register_loyalty( $user, $name, $email, $norm );
            $loyalty_message = $loyalty_result; // informational note appended to success msg
        }

        $message = __( 'Account created! Welcome aboard.', 'auth-popup' );
        if ( $loyalty_message ) {
            $message .= ' ' . $loyalty_message;
        }

        self::success( [
            'message'  => $message,
            'redirect' => self::redirect_url(),
        ] );
    }

    /**
     * Register user in the Herlan Loyalty Programme via direct API call.
     * Returns a short status string to append to the success message.
     */
    private static function register_loyalty( \WP_User $user, string $name, string $email, string $phone ): string {
        $api_url = rtrim( (string) Auth_Popup_Core::get_setting( 'loyalty_api_url' ), '/' );

        if ( empty( $api_url ) ) {
            return '';
        }

        $gender      = sanitize_text_field( $_POST['gender']      ?? '' );
        $dob         = sanitize_text_field( $_POST['dob']         ?? '' );
        $card_number = sanitize_text_field( $_POST['card_number'] ?? '' );

        if ( empty( $gender ) || empty( $dob ) ) {
            return __( '(Loyalty registration skipped: gender and date of birth are required.)', 'auth-popup' );
        }

        $data = [
            'full_name'   => $name,
            'email'       => $email,
            'phone'       => $phone,
            'card_number' => $card_number,
            'gender'      => strtolower( $gender ),
            'dob'         => $dob,
            'channel'     => 'E-commerce',
            'join_date'   => date( 'Y-m-d' ),
        ];

        // Step 1: check if phone already exists in loyalty system
        $login_res = wp_remote_post(
            $api_url . '/login',
            [
                'timeout' => 10,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [ 'phone' => $phone ] ),
            ]
        );

        if ( ! is_wp_error( $login_res ) ) {
            $login_body = json_decode( wp_remote_retrieve_body( $login_res ), true );
            if ( ! empty( $login_body['success'] ) ) {
                // Phone already registered in loyalty system
                update_user_meta( $user->ID, 'herlan_loyalty_registered', '1' );
                return __( 'You are already a loyal member!', 'auth-popup' );
            }
        }

        // Step 2: phone not found — register in loyalty system
        $response = wp_remote_post(
            $api_url . '/registration',
            [
                'timeout'     => 10,
                'headers'     => [ 'Content-Type' => 'application/json' ],
                'body'        => wp_json_encode( $data ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $body['success'] ) ) {
            update_user_meta( $user->ID, 'herlan_loyalty_registered', '1' );
            return __( 'You have joined the Herlan Star Loyalty Programme!', 'auth-popup' );
        }

        $err_msg = $body['message'] ?? __( 'Loyalty registration failed.', 'auth-popup' );
        return '(' . $err_msg . ')';
    }

    /* ── Google Auth ────────────────────────────────────────────────── */

    public static function auth_popup_google_auth(): void {
        self::verify_nonce();

        $access_token = sanitize_text_field( $_POST['access_token'] ?? '' );

        if ( empty( $access_token ) ) {
            self::error( __( 'Google token is missing.', 'auth-popup' ) );
        }

        $profile = Auth_Popup_OAuth_Handler::verify_google_token( $access_token );
        if ( is_wp_error( $profile ) ) {
            self::error( $profile->get_error_message() );
        }

        $user = Auth_Popup_User_Auth::login_or_create_oauth( $profile, 'google' );
        if ( is_wp_error( $user ) ) {
            self::error( $user->get_error_message() );
        }

        self::success( [
            'message'  => __( 'Logged in with Google!', 'auth-popup' ),
            'redirect' => self::redirect_url(),
        ] );
    }

    /* ── Facebook Auth ──────────────────────────────────────────────── */

    public static function auth_popup_facebook_auth(): void {
        self::verify_nonce();

        $access_token = sanitize_text_field( $_POST['access_token'] ?? '' );

        if ( empty( $access_token ) ) {
            self::error( __( 'Facebook token is missing.', 'auth-popup' ) );
        }

        $profile = Auth_Popup_OAuth_Handler::verify_facebook_token( $access_token );
        if ( is_wp_error( $profile ) ) {
            self::error( $profile->get_error_message() );
        }

        $user = Auth_Popup_User_Auth::login_or_create_oauth( $profile, 'facebook' );
        if ( is_wp_error( $user ) ) {
            self::error( $user->get_error_message() );
        }

        self::success( [
            'message'  => __( 'Logged in with Facebook!', 'auth-popup' ),
            'redirect' => self::redirect_url(),
        ] );
    }

    /* ── Logout ─────────────────────────────────────────────────────── */

    public static function auth_popup_logout(): void {
        self::verify_nonce();
        wp_logout();
        self::success( [ 'redirect' => home_url() ] );
    }

    /* ── Loyalty Rules ──────────────────────────────────────────────── */

    public static function auth_popup_get_loyalty_rules(): void {
        self::verify_nonce();

        $cache_key = 'auth_popup_loyalty_rules';
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            self::success( $cached );
        }

        $response = wp_remote_get( 'https://loyalty.herlan.store/api/customer/loyalty/rules', [
            'timeout' => 10,
            'headers' => [ 'Accept' => 'application/json' ],
        ] );

        if ( is_wp_error( $response ) ) {
            self::error( __( 'Failed to load loyalty rules.', 'auth-popup' ) );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
            self::error( __( 'No loyalty rules available.', 'auth-popup' ) );
        }

        $rules = array_values( array_map( function ( $rule ) {
            return [
                'name'        => sanitize_text_field( $rule['name']        ?? '' ),
                'description' => sanitize_text_field( $rule['short_description'] ?? '' ),
            ];
        }, $body['data'] ) );

        $payload = [ 'rules' => $rules ];
        set_transient( $cache_key, $payload, 5 * MINUTE_IN_SECONDS );

        self::success( $payload );
    }

    /* ── Check Phone (for registration) ─────────────────────────────── */

    public static function auth_popup_check_phone(): void {
        self::verify_nonce();

        $phone = sanitize_text_field( $_POST['phone'] ?? '' );
        if ( ! Auth_Popup_SMS_Service::is_valid_phone( $phone ) ) {
            self::success( [ 'exists' => false, 'valid' => false ] );
        }

        $norm   = Auth_Popup_SMS_Service::normalise_phone( $phone );
        $exists = null !== Auth_Popup_User_Auth::get_user_by_phone( $norm );

        self::success( [ 'exists' => $exists, 'valid' => true ] );
    }

    /* ── Address Book ───────────────────────────────────────────────── */

    public static function auth_popup_get_addresses(): void {
        self::verify_nonce();
        self::require_login();

        $addresses = Auth_Popup_Address_Manager::get_addresses( get_current_user_id() );
        self::success( [ 'addresses' => $addresses ] );
    }

    public static function auth_popup_save_address(): void {
        self::verify_nonce();
        self::require_login();

        $address_id = (int) ( $_POST['address_id'] ?? 0 );
        $result     = Auth_Popup_Address_Manager::save(
            get_current_user_id(),
            $_POST,
            $address_id
        );

        if ( is_wp_error( $result ) ) {
            self::error( $result->get_error_message() );
        }

        // Sync to WC usermeta if this address is (or became) the default
        $saved = Auth_Popup_Address_Manager::get_address( get_current_user_id(), $result );
        if ( $saved && (int) $saved['is_default'] === 1 ) {
            Auth_Popup_Address_Manager::sync_to_wc( get_current_user_id(), $saved );
        }

        $addresses = Auth_Popup_Address_Manager::get_addresses( get_current_user_id() );
        self::success( [
            'message'    => $address_id > 0
                ? __( 'Address updated.', 'auth-popup' )
                : __( 'Address saved.', 'auth-popup' ),
            'address_id' => $result,
            'addresses'  => $addresses,
        ] );
    }

    public static function auth_popup_delete_address(): void {
        self::verify_nonce();
        self::require_login();

        $address_id = (int) ( $_POST['address_id'] ?? 0 );
        if ( ! $address_id ) {
            self::error( __( 'Invalid address.', 'auth-popup' ) );
        }

        $deleted = Auth_Popup_Address_Manager::delete( get_current_user_id(), $address_id );
        if ( ! $deleted ) {
            self::error( __( 'Address not found.', 'auth-popup' ) );
        }

        $addresses = Auth_Popup_Address_Manager::get_addresses( get_current_user_id() );
        self::success( [
            'message'   => __( 'Address deleted.', 'auth-popup' ),
            'addresses' => $addresses,
        ] );
    }

    public static function auth_popup_set_default_address(): void {
        self::verify_nonce();
        self::require_login();

        $address_id = (int) ( $_POST['address_id'] ?? 0 );
        if ( ! $address_id ) {
            self::error( __( 'Invalid address.', 'auth-popup' ) );
        }

        $done = Auth_Popup_Address_Manager::set_default( get_current_user_id(), $address_id );
        if ( ! $done ) {
            self::error( __( 'Address not found.', 'auth-popup' ) );
        }

        // Sync the newly-promoted default to WC usermeta
        $new_default = Auth_Popup_Address_Manager::get_address( get_current_user_id(), $address_id );
        if ( $new_default ) {
            Auth_Popup_Address_Manager::sync_to_wc( get_current_user_id(), $new_default );
        }

        $addresses = Auth_Popup_Address_Manager::get_addresses( get_current_user_id() );
        self::success( [
            'message'   => __( 'Default address updated.', 'auth-popup' ),
            'addresses' => $addresses,
        ] );
    }

    /* ── Helpers ─────────────────────────────────────────────────────── */

    private static function require_login(): void {
        if ( ! is_user_logged_in() ) {
            self::error( __( 'You must be logged in.', 'auth-popup' ), 403 );
        }
    }

    private static function verify_nonce(): void {
        if ( ! check_ajax_referer( 'auth_popup_nonce', 'nonce', false ) ) {
            self::error( __( 'Security check failed. Please refresh the page.', 'auth-popup' ), 403 );
        }
    }

    private static function redirect_url(): string {
        $url = sanitize_url( $_POST['redirect_to'] ?? '' );
        if ( empty( $url ) ) {
            $url = Auth_Popup_Core::get_setting( 'redirect_url', home_url() );
        }
        // Prevent open redirect: allow only same-origin URLs
        if ( strpos( $url, home_url() ) !== 0 ) {
            $url = home_url();
        }
        return $url;
    }

    private static function success( array $data = [] ): void {
        wp_send_json_success( $data );
    }

    private static function error( string $message, int $code = 200 ): void {
        wp_send_json_error( [ 'message' => $message ], $code );
        // wp_send_json_error calls wp_die(), so no exit needed
    }
}
