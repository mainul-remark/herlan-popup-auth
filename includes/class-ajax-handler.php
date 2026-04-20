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
            'auth_popup_verify_otp',
            'auth_popup_social_complete',
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

        // For social completion: phone must NOT already belong to another account
        if ( 'social' === $context && null !== Auth_Popup_User_Auth::get_user_by_phone( $phone ) ) {
            self::error( __( 'This mobile number is already registered. Please use a different number or sign in with your existing account.', 'auth-popup' ) );
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

        // Rate limiting: block before attempting login
        self::check_password_rate_limit( $credential );

        $user = Auth_Popup_User_Auth::login_with_password( $credential, $password );
        if ( is_wp_error( $user ) ) {
            self::record_password_failure( $credential );
            self::error( $user->get_error_message() );
        }

        // Successful login: clear failure counters for this IP and credential
        self::clear_password_failures( $credential );

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

        // Check if a WP account already exists for this email
        $oauth_email = sanitize_email( $profile['email'] ?? '' );
        if ( ! empty( $oauth_email ) ) {
            $existing_user = get_user_by( 'email', $oauth_email );
            if ( $existing_user ) {
                $existing_phone = Auth_Popup_User_Auth::get_user_phone( $existing_user->ID );
                if ( ! empty( $existing_phone ) ) {
                    // User exists and already has a phone — auto-login immediately
                    $user = Auth_Popup_User_Auth::login_or_create_oauth( $profile, 'google' );
                    if ( is_wp_error( $user ) ) {
                        self::error( $user->get_error_message() );
                    }
                    self::success( [
                        'message'  => __( 'Logged in with Google!', 'auth-popup' ),
                        'redirect' => self::redirect_url(),
                    ] );
                }
                // User exists but has no phone — fall through to require phone
            }
            // User not found — fall through to require phone (new account will be created)
        }

        // User has no phone on record or is new — require mobile verification
        $temp_token = wp_generate_password( 32, false );
        set_transient( 'ap_social_' . $temp_token, [ 'profile' => $profile, 'provider' => 'google' ], 15 * MINUTE_IN_SECONDS );

        self::success( [
            'need_mobile' => true,
            'temp_token'  => $temp_token,
            'provider'    => 'google',
            'name'        => $profile['name'] ?? '',
            'message'     => __( 'Please verify your mobile number to complete sign-in.', 'auth-popup' ),
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

        // Check if a WP account already exists for this email
        $oauth_email = sanitize_email( $profile['email'] ?? '' );
        if ( ! empty( $oauth_email ) ) {
            $existing_user = get_user_by( 'email', $oauth_email );
            if ( $existing_user ) {
                $existing_phone = Auth_Popup_User_Auth::get_user_phone( $existing_user->ID );
                if ( ! empty( $existing_phone ) ) {
                    // User exists and already has a phone — auto-login immediately
                    $user = Auth_Popup_User_Auth::login_or_create_oauth( $profile, 'facebook' );
                    if ( is_wp_error( $user ) ) {
                        self::error( $user->get_error_message() );
                    }
                    self::success( [
                        'message'  => __( 'Logged in with Facebook!', 'auth-popup' ),
                        'redirect' => self::redirect_url(),
                    ] );
                }
                // User exists but has no phone — fall through to require phone
            }
            // User not found — fall through to require phone (new account will be created)
        }

        // User has no phone on record or is new — require mobile verification
        $temp_token = wp_generate_password( 32, false );
        set_transient( 'ap_social_' . $temp_token, [ 'profile' => $profile, 'provider' => 'facebook' ], 15 * MINUTE_IN_SECONDS );

        self::success( [
            'need_mobile' => true,
            'temp_token'  => $temp_token,
            'provider'    => 'facebook',
            'name'        => $profile['name'] ?? '',
            'message'     => __( 'Please verify your mobile number to complete sign-in.', 'auth-popup' ),
        ] );
    }

    /* ── OTP Peek (verify without consuming) ───────────────────────── */

    public static function auth_popup_verify_otp(): void {
        self::verify_nonce();

        $phone = sanitize_text_field( $_POST['phone'] ?? '' );
        $otp   = sanitize_text_field( $_POST['otp']   ?? '' );

        if ( ! Auth_Popup_SMS_Service::is_valid_phone( $phone ) ) {
            self::error( __( 'Invalid mobile number.', 'auth-popup' ) );
        }

        if ( strlen( $otp ) !== 6 || ! ctype_digit( $otp ) ) {
            self::error( __( 'Invalid OTP format.', 'auth-popup' ) );
        }

        $norm = Auth_Popup_SMS_Service::normalise_phone( $phone );

        if ( ! Auth_Popup_OTP_Manager::peek( $norm, $otp ) ) {
            self::error( __( 'Incorrect or expired OTP. Please try again.', 'auth-popup' ) );
        }

        self::success( [ 'message' => __( 'OTP verified.', 'auth-popup' ) ] );
    }

    /* ── Social Login Completion (mobile + OTP) ─────────────────────── */

    public static function auth_popup_social_complete(): void {
        self::verify_nonce();

        if ( is_user_logged_in() ) {
            self::success( [ 'redirect' => self::redirect_url() ] );
        }

        $temp_token = sanitize_text_field( $_POST['temp_token'] ?? '' );
        $phone      = sanitize_text_field( $_POST['phone']      ?? '' );
        $otp        = sanitize_text_field( $_POST['otp']        ?? '' );

        if ( empty( $temp_token ) ) {
            self::error( __( 'Session expired. Please try signing in again.', 'auth-popup' ) );
        }

        $transient = get_transient( 'ap_social_' . $temp_token );
        if ( ! $transient ) {
            self::error( __( 'Session expired. Please try signing in again.', 'auth-popup' ) );
        }

        $profile  = $transient['profile'];
        $provider = $transient['provider'];

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

        // OTP verified — consume the session transient
        delete_transient( 'ap_social_' . $temp_token );

        $user = Auth_Popup_User_Auth::login_or_create_social_with_phone( $profile, $provider, $norm );
        if ( is_wp_error( $user ) ) {
            self::error( $user->get_error_message() );
        }

        // Optional: Herlan Star Loyalty registration
        $loyalty_message = '';
        if ( ! empty( $_POST['join_loyalty'] ) && '1' === $_POST['join_loyalty'] ) {
            $name  = sanitize_text_field( $profile['name']  ?? '' );
            $email = sanitize_email( $profile['email'] ?? '' );
            $loyalty_message = self::register_loyalty( $user, $name, $email, $norm );
        }

        $message = __( 'Signed in successfully!', 'auth-popup' );
        if ( $loyalty_message ) {
            $message .= ' ' . $loyalty_message;
        }

        self::success( [
            'message'  => $message,
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

    /* ── Password Login Rate Limiting ───────────────────────────────── */

    /**
     * Transient key for per-IP failure counter.
     * wp_hash() uses HMAC-SHA256 with WP's secret key — key names are unpredictable,
     * preventing an attacker from targeting specific transient rows in the cache.
     */
    private static function pw_ip_key(): string {
        $ip = Auth_Popup_OTP_Manager::get_client_ip() ?: 'unknown';
        return 'ap_pw_ip_' . wp_hash( $ip );
    }

    /**
     * Transient key for per-credential failure counter.
     * Catches distributed attacks that rotate IPs but target the same account.
     */
    private static function pw_cred_key( string $credential ): string {
        return 'ap_pw_cr_' . wp_hash( strtolower( $credential ) );
    }

    /**
     * Block the request if either the IP or the credential has too many recent failures.
     * Limits are intentionally different: IPs can legitimately serve many users,
     * so the IP limit is higher than the per-credential limit.
     */
    private static function check_password_rate_limit( string $credential ): void {
        $window = 15 * MINUTE_IN_SECONDS;

        if ( (int) get_transient( self::pw_ip_key() ) >= 10 ) {
            self::error( __( 'Too many login attempts from your network. Please try again in 15 minutes.', 'auth-popup' ), 429 );
        }

        if ( (int) get_transient( self::pw_cred_key( $credential ) ) >= 5 ) {
            self::error( __( 'Too many login attempts for this account. Please try again in 15 minutes.', 'auth-popup' ), 429 );
        }
    }

    /** Increment both failure counters after a wrong password. */
    private static function record_password_failure( string $credential ): void {
        $window   = 15 * MINUTE_IN_SECONDS;
        $ip_key   = self::pw_ip_key();
        $cred_key = self::pw_cred_key( $credential );

        set_transient( $ip_key,   (int) get_transient( $ip_key )   + 1, $window );
        set_transient( $cred_key, (int) get_transient( $cred_key ) + 1, $window );
    }

    /** Reset both counters after a successful login. */
    private static function clear_password_failures( string $credential ): void {
        delete_transient( self::pw_ip_key() );
        delete_transient( self::pw_cred_key( $credential ) );
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
        $home      = home_url();
        $home_host = (string) wp_parse_url( $home, PHP_URL_HOST );

        // Use the caller-supplied URL only if it belongs to the same host.
        // strpos() is intentionally NOT used here: "https://herlan.com.evil.com/"
        // starts with "https://herlan.com" and would bypass a prefix check.
        $url      = sanitize_url( $_POST['redirect_to'] ?? '' );
        $url_host = ! empty( $url ) ? (string) wp_parse_url( $url, PHP_URL_HOST ) : '';

        if ( ! empty( $url_host ) && $url_host === $home_host ) {
            return $url;
        }

        // Fall back to the admin-configured redirect URL (trusted, sanitized at save time)
        $configured = (string) Auth_Popup_Core::get_setting( 'redirect_url', $home );
        return ! empty( $configured ) ? $configured : $home;
    }

    private static function success( array $data = [] ): void {
        wp_send_json_success( $data );
    }

    private static function error( string $message, int $code = 200 ): void {
        wp_send_json_error( [ 'message' => $message ], $code );
        // wp_send_json_error calls wp_die(), so no exit needed
    }
}
