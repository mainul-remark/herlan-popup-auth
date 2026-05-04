<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API endpoints for Auth Popup.
 *
 * Base: /wp-json/auth-popup/v1/
 *
 * Auth (public):
 *   POST   /auth/send-otp
 *   POST   /auth/login
 *   POST   /auth/login-otp
 *   POST   /auth/register
 *   POST   /auth/google
 *   POST   /auth/facebook
 *   POST   /auth/verify-otp
 *   POST   /auth/social-complete
 *   POST   /auth/logout
 *   GET    /auth/check-phone
 *   GET    /auth/loyalty-rules
 *   POST   /auth/forgot-password
 *   POST   /auth/verify-reset-otp
 *   POST   /auth/reset-password
 *
 * Addresses (require authentication):
 *   GET    /addresses
 *   POST   /addresses
 *   GET    /addresses/{id}
 *   PUT    /addresses/{id}
 *   DELETE /addresses/{id}
 *   POST   /addresses/{id}/default
 */
class Auth_Popup_REST_API {

    const NAMESPACE = 'auth-popup/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
        // Authenticate Bearer tokens so is_user_logged_in() works for address endpoints
        add_filter( 'rest_pre_dispatch',          [ __CLASS__, 'validate_api_key' ], 10, 3 );
        add_filter( 'rest_post_dispatch',         [ __CLASS__, 'normalize_error_response' ], 10, 3 );
        add_filter( 'determine_current_user',     [ __CLASS__, 'authenticate_token' ], 20 );
        add_filter( 'rest_authentication_errors', [ __CLASS__, 'check_authentication_errors' ] );
    }

    /* ── Route Registration ──────────────────────────────────────────── */

    public static function register_routes(): void {
        $ns = self::NAMESPACE;

        register_rest_route( $ns, '/auth/send-otp', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'send_otp' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'phone'   => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => 'Mobile phone number.',
                ],
                'context' => [
                    'required'          => false,
                    'type'              => 'string',
                    'enum'              => [ 'login', 'register', 'social' ],
                    'default'           => 'login',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => 'Context: login, register, or social.',
                ],
            ],
        ] );

        register_rest_route( $ns, '/auth/login', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'login_password' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'credential'  => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => 'Email, username, or mobile number.',
                ],
                'password'    => [
                    'required'    => true,
                    'type'        => 'string',
                    'description' => 'Account password.',
                ],
                'redirect_to' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_url', 'default' => '' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/login-otp', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'login_otp' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'phone'       => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'otp'         => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'redirect_to' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_url', 'default' => '' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/register', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'register_user' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'phone'        => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'otp'          => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'name'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
                'email'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_email',       'default' => '' ],
                'password'     => [ 'required' => false, 'type' => 'string', 'default' => '' ],
                'join_loyalty' => [ 'required' => false, 'type' => 'string', 'default' => '0' ],
                'gender'       => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
                'dob'          => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
                'card_number'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
                'redirect_to'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_url', 'default' => '' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/google', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'google_auth' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'access_token' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'redirect_to'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_url', 'default' => '' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/facebook', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'facebook_auth' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'access_token' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'redirect_to'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_url', 'default' => '' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/verify-otp', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'verify_otp' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'phone' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'otp'   => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/social-complete', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'social_complete' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'temp_token'   => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'phone'        => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'otp'          => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'join_loyalty' => [ 'required' => false, 'type' => 'string', 'default' => '0' ],
                'gender'       => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
                'dob'          => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
                'card_number'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
                'redirect_to'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_url', 'default' => '' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/logout', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'logout' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'refresh_token' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/refresh', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'refresh_token' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'refresh_token' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/check-phone', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'check_phone' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'phone' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/loyalty-rules', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_loyalty_rules' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/auth/forgot-password', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'forgot_password' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'email' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/verify-reset-otp', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'verify_reset_otp' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'email' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ],
                'otp'   => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( $ns, '/auth/reset-password', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'reset_password' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'reset_token'      => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'new_password'     => [ 'required' => true, 'type' => 'string' ],
                'confirm_password' => [ 'required' => true, 'type' => 'string' ],
            ],
        ] );

        // ── Address endpoints ───────────────────────────────────────────

        register_rest_route( $ns, '/addresses', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'get_addresses' ],
                'permission_callback' => [ __CLASS__, 'require_login' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'create_address' ],
                'permission_callback' => [ __CLASS__, 'require_login' ],
                'args'                => self::address_args( true ),
            ],
        ] );

        register_rest_route( $ns, '/addresses/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'get_address' ],
                'permission_callback' => [ __CLASS__, 'require_login' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_address' ],
                'permission_callback' => [ __CLASS__, 'require_login' ],
                'args'                => self::address_args( false ),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ __CLASS__, 'delete_address' ],
                'permission_callback' => [ __CLASS__, 'require_login' ],
            ],
        ] );

        register_rest_route( $ns, '/addresses/(?P<id>\d+)/default', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'set_default_address' ],
            'permission_callback' => [ __CLASS__, 'require_login' ],
        ] );
    }

    /* ── Auth Callbacks ──────────────────────────────────────────────── */

    public static function send_otp( WP_REST_Request $request ): WP_REST_Response {
        $phone   = $request->get_param( 'phone' );
        $context = $request->get_param( 'context' );

        if ( ! Auth_Popup_SMS_Service::is_valid_phone( $phone ) ) {
            return self::error( __( 'Please enter a valid mobile number.', 'auth-popup' ), 422 );
        }

        $phone = Auth_Popup_SMS_Service::normalise_phone( $phone );

        if ( 'login' === $context && null === Auth_Popup_User_Auth::get_user_by_phone( $phone ) ) {
            return self::error( __( 'No account found with this mobile number. Please register first.', 'auth-popup' ), 404 );
        }

        if ( 'social' === $context && null !== Auth_Popup_User_Auth::get_user_by_phone( $phone ) ) {
            return self::error( __( 'This mobile number is already registered. Please use a different number or sign in with your existing account.', 'auth-popup' ), 409 );
        }

        $otp = Auth_Popup_OTP_Manager::generate( $phone );
        if ( is_wp_error( $otp ) ) {
            return self::error( $otp->get_error_message(), 429 );
        }

        $result = Auth_Popup_SMS_Service::send_otp( $phone, $otp );
        if ( is_wp_error( $result ) ) {
            Auth_Popup_OTP_Manager::invalidate( $phone );
            return self::error( $result->get_error_message(), 502 );
        }

        $expiry = (int) Auth_Popup_Core::get_setting( 'otp_expiry_minutes', 5 );

        return self::success(
            /* translators: %s: phone number */
            sprintf( __( 'OTP sent to %s', 'auth-popup' ), $phone ),
            [ 'expiry_seconds' => $expiry * 60 ]
        );
    }

    public static function login_password( WP_REST_Request $request ): WP_REST_Response {
        if ( is_user_logged_in() ) {
            return self::success( __( 'Already logged in.', 'auth-popup' ), [ 'redirect' => self::redirect_url( $request ) ] );
        }

        $credential = $request->get_param( 'credential' );
        $password   = $request->get_param( 'password' );

        $rate_check = self::check_password_rate_limit( $credential );
        if ( is_wp_error( $rate_check ) ) {
            return self::error( $rate_check->get_error_message(), 429 );
        }

        $user = Auth_Popup_User_Auth::login_with_password( $credential, $password );
        if ( is_wp_error( $user ) ) {
            self::record_password_failure( $credential );
            return self::error( $user->get_error_message(), 401 );
        }

        self::clear_password_failures( $credential );
        $tokens = self::generate_token( $user->ID );

        return self::success(
            __( 'Login successful!', 'auth-popup' ),
            array_merge( $tokens, [ 'redirect' => self::redirect_url( $request ) ] )
        );
    }

    public static function login_otp( WP_REST_Request $request ): WP_REST_Response {
        if ( is_user_logged_in() ) {
            return self::success( __( 'Already logged in.', 'auth-popup' ), [ 'redirect' => self::redirect_url( $request ) ] );
        }

        $phone = $request->get_param( 'phone' );
        $otp   = $request->get_param( 'otp' );

        if ( ! Auth_Popup_SMS_Service::is_valid_phone( $phone ) ) {
            return self::error( __( 'Invalid mobile number.', 'auth-popup' ), 422 );
        }

        if ( strlen( $otp ) !== 6 || ! ctype_digit( $otp ) ) {
            return self::error( __( 'OTP must be exactly 6 digits.', 'auth-popup' ), 422 );
        }

        $norm = Auth_Popup_SMS_Service::normalise_phone( $phone );

        if ( ! Auth_Popup_OTP_Manager::verify( $norm, $otp ) ) {
            return self::error( __( 'Incorrect or expired OTP. Please try again.', 'auth-popup' ), 401 );
        }

        $user = Auth_Popup_User_Auth::login_or_create_by_phone( $norm );
        if ( is_wp_error( $user ) ) {
            return self::error( $user->get_error_message(), 500 );
        }

        $tokens = self::generate_token( $user->ID );

        return self::success(
            __( 'Login successful!', 'auth-popup' ),
            array_merge( $tokens, [ 'redirect' => self::redirect_url( $request ) ] )
        );
    }

    public static function register_user( WP_REST_Request $request ): WP_REST_Response {
        if ( is_user_logged_in() ) {
            return self::success( __( 'Already logged in.', 'auth-popup' ), [ 'redirect' => self::redirect_url( $request ) ] );
        }

        $phone = $request->get_param( 'phone' );
        $otp   = $request->get_param( 'otp' );
        $name  = $request->get_param( 'name' );
        $email = $request->get_param( 'email' );
        $pass  = $request->get_param( 'password' );

        if ( ! Auth_Popup_SMS_Service::is_valid_phone( $phone ) ) {
            return self::error( __( 'Invalid mobile number.', 'auth-popup' ), 422 );
        }

        if ( strlen( $otp ) !== 6 || ! ctype_digit( $otp ) ) {
            return self::error( __( 'OTP must be exactly 6 digits.', 'auth-popup' ), 422 );
        }

        $norm = Auth_Popup_SMS_Service::normalise_phone( $phone );

        if ( ! Auth_Popup_OTP_Manager::verify( $norm, $otp ) ) {
            return self::error( __( 'Incorrect or expired OTP. Please try again.', 'auth-popup' ), 401 );
        }

        $user = Auth_Popup_User_Auth::register( [
            'phone'    => $norm,
            'name'     => $name,
            'email'    => $email,
            'password' => $pass,
        ] );

        if ( is_wp_error( $user ) ) {
            return self::error( $user->get_error_message(), 422 );
        }

        $loyalty_note = '';
        if ( '1' === $request->get_param( 'join_loyalty' ) ) {
            $loyalty_note = self::register_loyalty( $user, $name, $email, $norm, $request );
        }

        $tokens  = self::generate_token( $user->ID );
        $message = __( 'Account created! Welcome aboard.', 'auth-popup' );
        if ( $loyalty_note ) {
            $message .= ' ' . $loyalty_note;
        }

        return self::success( $message, array_merge( $tokens, [ 'redirect' => self::redirect_url( $request ) ] ), 201 );
    }

    public static function google_auth( WP_REST_Request $request ): WP_REST_Response {
        $access_token = $request->get_param( 'access_token' );

        $profile = Auth_Popup_OAuth_Handler::verify_google_token( $access_token );
        if ( is_wp_error( $profile ) ) {
            return self::error( $profile->get_error_message(), 401 );
        }

        $oauth_email = sanitize_email( $profile['email'] ?? '' );
        if ( ! empty( $oauth_email ) ) {
            $existing_user = get_user_by( 'email', $oauth_email );
            if ( $existing_user ) {
                $existing_phone = Auth_Popup_User_Auth::get_user_phone( $existing_user->ID );
                if ( ! empty( $existing_phone ) ) {
                    $user = Auth_Popup_User_Auth::login_or_create_oauth( $profile, 'google' );
                    if ( is_wp_error( $user ) ) {
                        return self::error( $user->get_error_message(), 500 );
                    }
                    $tokens = self::generate_token( $user->ID );
                    return self::success(
                        __( 'Logged in with Google!', 'auth-popup' ),
                        array_merge( $tokens, [ 'redirect' => self::redirect_url( $request ) ] )
                    );
                }
            }
        }

        $temp_token = wp_generate_password( 32, false );
        set_transient(
            'ap_social_' . $temp_token,
            [ 'profile' => $profile, 'provider' => 'google' ],
            15 * MINUTE_IN_SECONDS
        );

        return self::success(
            __( 'Please verify your mobile number to complete sign-in.', 'auth-popup' ),
            [
                'need_mobile' => true,
                'temp_token'  => $temp_token,
                'provider'    => 'google',
                'name'        => $profile['name'] ?? '',
            ]
        );
    }

    public static function facebook_auth( WP_REST_Request $request ): WP_REST_Response {
        $access_token = $request->get_param( 'access_token' );

        $profile = Auth_Popup_OAuth_Handler::verify_facebook_token( $access_token );
        if ( is_wp_error( $profile ) ) {
            return self::error( $profile->get_error_message(), 401 );
        }

        $oauth_email = sanitize_email( $profile['email'] ?? '' );
        if ( ! empty( $oauth_email ) ) {
            $existing_user = get_user_by( 'email', $oauth_email );
            if ( $existing_user ) {
                $existing_phone = Auth_Popup_User_Auth::get_user_phone( $existing_user->ID );
                if ( ! empty( $existing_phone ) ) {
                    $user = Auth_Popup_User_Auth::login_or_create_oauth( $profile, 'facebook' );
                    if ( is_wp_error( $user ) ) {
                        return self::error( $user->get_error_message(), 500 );
                    }
                    $tokens = self::generate_token( $user->ID );
                    return self::success(
                        __( 'Logged in with Facebook!', 'auth-popup' ),
                        array_merge( $tokens, [ 'redirect' => self::redirect_url( $request ) ] )
                    );
                }
            }
        }

        $temp_token = wp_generate_password( 32, false );
        set_transient(
            'ap_social_' . $temp_token,
            [ 'profile' => $profile, 'provider' => 'facebook' ],
            15 * MINUTE_IN_SECONDS
        );

        return self::success(
            __( 'Please verify your mobile number to complete sign-in.', 'auth-popup' ),
            [
                'need_mobile' => true,
                'temp_token'  => $temp_token,
                'provider'    => 'facebook',
                'name'        => $profile['name'] ?? '',
            ]
        );
    }

    public static function verify_otp( WP_REST_Request $request ): WP_REST_Response {
        $phone = $request->get_param( 'phone' );
        $otp   = $request->get_param( 'otp' );

        if ( ! Auth_Popup_SMS_Service::is_valid_phone( $phone ) ) {
            return self::error( __( 'Invalid mobile number.', 'auth-popup' ), 422 );
        }

        if ( strlen( $otp ) !== 6 || ! ctype_digit( $otp ) ) {
            return self::error( __( 'OTP must be exactly 6 digits.', 'auth-popup' ), 422 );
        }

        $norm = Auth_Popup_SMS_Service::normalise_phone( $phone );

        if ( ! Auth_Popup_OTP_Manager::peek( $norm, $otp ) ) {
            return self::error( __( 'Incorrect or expired OTP. Please try again.', 'auth-popup' ), 401 );
        }

        return self::success( __( 'OTP verified.', 'auth-popup' ) );
    }

    public static function social_complete( WP_REST_Request $request ): WP_REST_Response {
        if ( is_user_logged_in() ) {
            return self::success( __( 'Already logged in.', 'auth-popup' ), [ 'redirect' => self::redirect_url( $request ) ] );
        }

        $temp_token = $request->get_param( 'temp_token' );
        $phone      = $request->get_param( 'phone' );
        $otp        = $request->get_param( 'otp' );

        $transient = get_transient( 'ap_social_' . $temp_token );
        if ( ! $transient ) {
            return self::error( __( 'Session expired. Please try signing in again.', 'auth-popup' ), 410 );
        }

        $profile  = $transient['profile'];
        $provider = $transient['provider'];

        if ( ! Auth_Popup_SMS_Service::is_valid_phone( $phone ) ) {
            return self::error( __( 'Invalid mobile number.', 'auth-popup' ), 422 );
        }

        if ( strlen( $otp ) !== 6 || ! ctype_digit( $otp ) ) {
            return self::error( __( 'OTP must be exactly 6 digits.', 'auth-popup' ), 422 );
        }

        $norm = Auth_Popup_SMS_Service::normalise_phone( $phone );

        if ( ! Auth_Popup_OTP_Manager::verify( $norm, $otp ) ) {
            return self::error( __( 'Incorrect or expired OTP. Please try again.', 'auth-popup' ), 401 );
        }

        delete_transient( 'ap_social_' . $temp_token );

        $user = Auth_Popup_User_Auth::login_or_create_social_with_phone( $profile, $provider, $norm );
        if ( is_wp_error( $user ) ) {
            return self::error( $user->get_error_message(), 500 );
        }

        $loyalty_note = '';
        if ( '1' === $request->get_param( 'join_loyalty' ) ) {
            $name         = sanitize_text_field( $profile['name']  ?? '' );
            $email        = sanitize_email( $profile['email'] ?? '' );
            $loyalty_note = self::register_loyalty( $user, $name, $email, $norm, $request );
        }

        $tokens  = self::generate_token( $user->ID );
        $message = __( 'Signed in successfully!', 'auth-popup' );
        if ( $loyalty_note ) {
            $message .= ' ' . $loyalty_note;
        }

        return self::success( $message, array_merge( $tokens, [ 'redirect' => self::redirect_url( $request ) ] ) );
    }

    public static function logout( WP_REST_Request $request ): WP_REST_Response {
        $token = self::extract_bearer_token();
        if ( $token ) {
            self::revoke_token( $token );
        }
        $refresh = $request->get_param( 'refresh_token' );
        if ( $refresh ) {
            self::revoke_refresh_token( $refresh );
        }
        wp_logout();
        return self::success( __( 'Logged out successfully.', 'auth-popup' ), [ 'redirect' => home_url() ] );
    }

    public static function refresh_token( WP_REST_Request $request ): WP_REST_Response {
        $refresh_token = $request->get_param( 'refresh_token' );

        $user_id = self::validate_refresh_token( $refresh_token );
        if ( ! $user_id ) {
            return self::error( __( 'Invalid or expired refresh token. Please log in again.', 'auth-popup' ), 401 );
        }

        // Rotate: revoke old refresh token before issuing new pair
        self::revoke_refresh_token( $refresh_token );

        $tokens = self::generate_token( $user_id );

        return self::success( __( 'Token refreshed.', 'auth-popup' ), $tokens );
    }

    public static function check_phone( WP_REST_Request $request ): WP_REST_Response {
        $phone = $request->get_param( 'phone' );

        if ( ! Auth_Popup_SMS_Service::is_valid_phone( $phone ) ) {
            return self::success( __( 'Phone number checked.', 'auth-popup' ), [ 'exists' => false, 'valid' => false ] );
        }

        $norm   = Auth_Popup_SMS_Service::normalise_phone( $phone );
        $exists = null !== Auth_Popup_User_Auth::get_user_by_phone( $norm );

        return self::success( __( 'Phone number checked.', 'auth-popup' ), [ 'exists' => $exists, 'valid' => true ] );
    }

    public static function get_loyalty_rules( WP_REST_Request $request ): WP_REST_Response {
        $cache_key = 'auth_popup_loyalty_rules';
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return self::success( __( 'Loyalty rules retrieved.', 'auth-popup' ), $cached );
        }

        $response = wp_remote_get( 'https://loyalty.herlan.store/api/customer/loyalty/rules', [
            'timeout' => 10,
            'headers' => [ 'Accept' => 'application/json' ],
        ] );

        if ( is_wp_error( $response ) ) {
            return self::error( __( 'Failed to load loyalty rules.', 'auth-popup' ), 502 );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
            return self::error( __( 'No loyalty rules available.', 'auth-popup' ), 404 );
        }

        $rules = array_values( array_map( function ( $rule ) {
            return [
                'name'        => sanitize_text_field( $rule['name']              ?? '' ),
                'description' => sanitize_text_field( $rule['short_description'] ?? '' ),
            ];
        }, $body['data'] ) );

        $payload = [ 'rules' => $rules ];
        set_transient( $cache_key, $payload, 5 * MINUTE_IN_SECONDS );

        return self::success( __( 'Loyalty rules retrieved.', 'auth-popup' ), $payload );
    }

    public static function forgot_password( WP_REST_Request $request ): WP_REST_Response {
        $email = $request->get_param( 'email' );

        if ( ! is_email( $email ) ) {
            return self::error( __( 'Please enter a valid email address.', 'auth-popup' ), 422 );
        }

        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            return self::error( __( 'No account found with this email address.', 'auth-popup' ), 404 );
        }

        $hash     = md5( strtolower( $email ) );
        $key_otp  = 'ap_fp_' . $hash;
        $key_lock = 'ap_fp_lock_' . $hash;

        if ( get_transient( $key_lock ) ) {
            return self::error( __( 'Please wait a moment before requesting another OTP.', 'auth-popup' ), 429 );
        }

        $otp    = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
        $expiry = 10 * MINUTE_IN_SECONDS;

        set_transient( $key_otp,  wp_hash( $otp ), $expiry );
        set_transient( $key_lock, 1, 60 );

        $site_name = get_bloginfo( 'name' );
        /* translators: %s: site name */
        $subject = sprintf( __( 'Password Reset OTP – %s', 'auth-popup' ), $site_name );
        $body    = Auth_Popup_Ajax_Handler::build_forgot_otp_email( $user->display_name, $otp, $site_name );
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        $from_sanitizer = static function ( string $from ): string {
            if ( is_email( $from ) ) {
                return $from;
            }
            $host = (string) wp_parse_url( get_site_url(), PHP_URL_HOST );
            if ( strpos( $host, 'www.' ) === 0 ) {
                $host = substr( $host, 4 );
            }
            if ( empty( $host ) || strpos( $host, '.' ) === false ) {
                $host .= '.local';
            }
            return 'noreply@' . $host;
        };
        add_filter( 'wp_mail_from', $from_sanitizer, PHP_INT_MAX );

        $mail_error     = null;
        $error_listener = function ( WP_Error $err ) use ( &$mail_error ) {
            $mail_error = $err->get_error_message();
        };
        add_action( 'wp_mail_failed', $error_listener );

        $sent = wp_mail( $email, $subject, $body, $headers );

        remove_filter( 'wp_mail_from', $from_sanitizer, PHP_INT_MAX );
        remove_action( 'wp_mail_failed', $error_listener );

        if ( ! $sent ) {
            delete_transient( $key_otp );
            delete_transient( $key_lock );
            $reason = $mail_error ?: __( 'Mail server returned an error. Please contact support.', 'auth-popup' );
            return self::error( $reason, 502 );
        }

        return self::success(
            __( 'OTP sent to your email address.', 'auth-popup' ),
            [ 'expiry_seconds' => $expiry ]
        );
    }

    public static function verify_reset_otp( WP_REST_Request $request ): WP_REST_Response {
        $email = $request->get_param( 'email' );
        $otp   = $request->get_param( 'otp' );

        if ( ! is_email( $email ) ) {
            return self::error( __( 'Invalid email address.', 'auth-popup' ), 422 );
        }

        if ( strlen( $otp ) !== 6 || ! ctype_digit( $otp ) ) {
            return self::error( __( 'OTP must be exactly 6 digits.', 'auth-popup' ), 422 );
        }

        $hash         = md5( strtolower( $email ) );
        $key_otp      = 'ap_fp_' . $hash;
        $key_attempts = 'ap_fp_va_' . $hash;

        $attempts = (int) get_transient( $key_attempts );
        if ( $attempts >= 5 ) {
            delete_transient( $key_otp );
            return self::error( __( 'Too many incorrect attempts. Please request a new OTP.', 'auth-popup' ), 429 );
        }

        $stored = get_transient( $key_otp );
        if ( false === $stored ) {
            return self::error( __( 'OTP has expired. Please request a new one.', 'auth-popup' ), 410 );
        }

        if ( ! hash_equals( $stored, wp_hash( $otp ) ) ) {
            set_transient( $key_attempts, $attempts + 1, 10 * MINUTE_IN_SECONDS );
            return self::error( __( 'Incorrect OTP. Please try again.', 'auth-popup' ), 401 );
        }

        delete_transient( $key_otp );
        delete_transient( $key_attempts );
        delete_transient( 'ap_fp_lock_' . $hash );

        $reset_token = wp_generate_password( 32, false );
        set_transient( 'ap_fp_rt_' . $reset_token, strtolower( $email ), 15 * MINUTE_IN_SECONDS );

        return self::success(
            __( 'OTP verified. Please set your new password.', 'auth-popup' ),
            [ 'reset_token' => $reset_token ]
        );
    }

    public static function reset_password( WP_REST_Request $request ): WP_REST_Response {
        $reset_token      = $request->get_param( 'reset_token' );
        $new_password     = $request->get_param( 'new_password' );
        $confirm_password = $request->get_param( 'confirm_password' );

        $email = get_transient( 'ap_fp_rt_' . $reset_token );
        if ( ! $email ) {
            return self::error( __( 'Session expired. Please start over.', 'auth-popup' ), 410 );
        }

        if ( empty( $new_password ) ) {
            return self::error( __( 'New password is required.', 'auth-popup' ), 422 );
        }

        if ( strlen( $new_password ) < 6 ) {
            return self::error( __( 'Password must be at least 6 characters.', 'auth-popup' ), 422 );
        }

        if ( $new_password !== $confirm_password ) {
            return self::error( __( 'Passwords do not match.', 'auth-popup' ), 422 );
        }

        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            delete_transient( 'ap_fp_rt_' . $reset_token );
            return self::error( __( 'Account not found.', 'auth-popup' ), 404 );
        }

        wp_set_password( $new_password, $user->ID );
        delete_transient( 'ap_fp_rt_' . $reset_token );

        return self::success( __( 'Password reset successfully! You can now log in with your new password.', 'auth-popup' ) );
    }

    /* ── Address Callbacks ───────────────────────────────────────────── */

    public static function get_addresses( WP_REST_Request $request ): WP_REST_Response {
        $addresses = Auth_Popup_Address_Manager::get_addresses( get_current_user_id() );
        return self::success( __( 'Addresses retrieved.', 'auth-popup' ), $addresses );
    }

    public static function get_address( WP_REST_Request $request ): WP_REST_Response {
        $id      = (int) $request->get_param( 'id' );
        $address = Auth_Popup_Address_Manager::get_address( get_current_user_id(), $id );

        if ( ! $address ) {
            return self::error( __( 'Address not found.', 'auth-popup' ), 404 );
        }

        return self::success( __( 'Address retrieved.', 'auth-popup' ), $address );
    }

    public static function create_address( WP_REST_Request $request ): WP_REST_Response {
        $result = Auth_Popup_Address_Manager::save(
            get_current_user_id(),
            $request->get_params(),
            0
        );

        if ( is_wp_error( $result ) ) {
            return self::error( $result->get_error_message(), 422 );
        }

        $saved = Auth_Popup_Address_Manager::get_address( get_current_user_id(), $result );
        if ( $saved && (int) $saved['is_default'] === 1 ) {
            Auth_Popup_Address_Manager::sync_to_wc( get_current_user_id(), $saved );
        }

        $addresses = Auth_Popup_Address_Manager::get_addresses( get_current_user_id() );

        return self::success(
            __( 'Address saved.', 'auth-popup' ),
            [ 'address_id' => $result, 'addresses' => $addresses ],
            201
        );
    }

    public static function update_address( WP_REST_Request $request ): WP_REST_Response {
        $id     = (int) $request->get_param( 'id' );
        $result = Auth_Popup_Address_Manager::save(
            get_current_user_id(),
            $request->get_params(),
            $id
        );

        if ( is_wp_error( $result ) ) {
            $status = 'not_found' === $result->get_error_code() ? 404 : 422;
            return self::error( $result->get_error_message(), $status );
        }

        $saved = Auth_Popup_Address_Manager::get_address( get_current_user_id(), $result );
        if ( $saved && (int) $saved['is_default'] === 1 ) {
            Auth_Popup_Address_Manager::sync_to_wc( get_current_user_id(), $saved );
        }

        $addresses = Auth_Popup_Address_Manager::get_addresses( get_current_user_id() );

        return self::success(
            __( 'Address updated.', 'auth-popup' ),
            [ 'address_id' => $result, 'addresses' => $addresses ]
        );
    }

    public static function delete_address( WP_REST_Request $request ): WP_REST_Response {
        $id      = (int) $request->get_param( 'id' );
        $deleted = Auth_Popup_Address_Manager::delete( get_current_user_id(), $id );

        if ( ! $deleted ) {
            return self::error( __( 'Address not found.', 'auth-popup' ), 404 );
        }

        $addresses = Auth_Popup_Address_Manager::get_addresses( get_current_user_id() );
        return self::success( __( 'Address deleted.', 'auth-popup' ), $addresses );
    }

    public static function set_default_address( WP_REST_Request $request ): WP_REST_Response {
        $id   = (int) $request->get_param( 'id' );
        $done = Auth_Popup_Address_Manager::set_default( get_current_user_id(), $id );

        if ( ! $done ) {
            return self::error( __( 'Address not found.', 'auth-popup' ), 404 );
        }

        $new_default = Auth_Popup_Address_Manager::get_address( get_current_user_id(), $id );
        if ( $new_default ) {
            Auth_Popup_Address_Manager::sync_to_wc( get_current_user_id(), $new_default );
        }

        $addresses = Auth_Popup_Address_Manager::get_addresses( get_current_user_id() );
        return self::success( __( 'Default address updated.', 'auth-popup' ), $addresses );
    }

    /* ── Permission Callbacks ────────────────────────────────────────── */

    /**
     * @return true|WP_Error
     */
    public static function require_login() {
        if ( is_user_logged_in() ) {
            return true;
        }
        return new WP_Error(
            'rest_unauthorized',
            __( 'Authentication required. Please log in to access this resource.', 'auth-popup' ),
            [ 'status' => 401 ]
        );
    }

    /* ── API Key Validation ──────────────────────────────────────────── */

    /**
     * WordPress filter: rest_pre_dispatch.
     * Rejects requests that do not carry a valid X-API-Key header.
     * Validation is skipped when no key has been configured in settings.
     *
     * @param mixed            $result  Current short-circuit result (null = continue).
     * @param WP_REST_Server   $server  REST server.
     * @param WP_REST_Request  $request Current request.
     * @return mixed WP_Error on invalid key, original $result otherwise.
     */
    public static function validate_api_key( $result, WP_REST_Server $server, WP_REST_Request $request ) {
        if ( null !== $result ) {
            return $result;
        }

        // Only protect auth-popup routes, not core WP or other plugin routes
        if ( strpos( $request->get_route(), '/' . self::NAMESPACE . '/' ) !== 0 ) {
            return $result;
        }

        $configured_key = (string) Auth_Popup_Core::get_setting( 'rest_api_key', '' );
        if ( empty( $configured_key ) ) {
            return $result;
        }

        $provided_key = trim( (string) $request->get_header( 'x-api-key' ) );
        if ( empty( $provided_key ) || ! hash_equals( $configured_key, $provided_key ) ) {
            return new WP_Error(
                'rest_forbidden_api_key',
                __( 'Invalid or missing API key.', 'auth-popup' ),
                [ 'status' => 403 ]
            );
        }

        return $result;
    }

    /**
     * WordPress filter: rest_post_dispatch.
     * Normalizes error responses on auth-popup routes to match our response structure,
     * and converts wrong-method 404s into a clear 405 Method Not Allowed.
     */
    public static function normalize_error_response( WP_REST_Response $response, WP_REST_Server $server, WP_REST_Request $request ): WP_REST_Response {
        $route = $request->get_route();

        if ( strpos( $route, '/' . self::NAMESPACE . '/' ) !== 0 ) {
            return $response;
        }

        $status = $response->get_status();
        $data   = $response->get_data();
        $code   = $data['code'] ?? '';

        if ( $code === 'rest_no_route' ) {
            // Check if path matches a registered route — if yes, the method is wrong
            $routes       = $server->get_routes( self::NAMESPACE );
            $path_matched = false;
            foreach ( $routes as $pattern => $handlers ) {
                if ( preg_match( '@^' . $pattern . '$@i', $route ) ) {
                    $path_matched = true;
                    break;
                }
            }

            if ( $path_matched ) {
                return new WP_REST_Response( [
                    'success'       => false,
                    'response_code' => 405,
                    'message'       => sprintf(
                        /* translators: %s: HTTP method used by the client */
                        __( 'Method Not Allowed. You used %s. This endpoint only accepts POST requests.', 'auth-popup' ),
                        $request->get_method()
                    ),
                    'data'          => (object) [],
                ], 405 );
            }

            return new WP_REST_Response( [
                'success'       => false,
                'response_code' => 404,
                'message'       => __( 'Endpoint not found. Please check the URL.', 'auth-popup' ),
                'data'          => (object) [],
            ], 404 );
        }

        // Reformat any other WP-generated error on our routes (e.g. 403 API key error)
        if ( ! empty( $code ) && $status >= 400 ) {
            return new WP_REST_Response( [
                'success'       => false,
                'response_code' => $status,
                'message'       => $data['message'] ?? __( 'An error occurred.', 'auth-popup' ),
                'data'          => (object) [],
            ], $status );
        }

        return $response;
    }

    /* ── Bearer Token Authentication ────────────────────────────────── */

    /**
     * WordPress filter: determine_current_user.
     * Authenticates the request from an Authorization: Bearer <token> header.
     */
    public static function authenticate_token( $user_id ) {
        // Only run during REST API requests and only if no user identified yet
        if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST || $user_id ) {
            return $user_id;
        }

        $token = self::extract_bearer_token();
        if ( ! $token ) {
            return $user_id;
        }

        $authenticated = self::validate_token( $token );
        return $authenticated ?: $user_id;
    }

    /**
     * WordPress filter: rest_authentication_errors.
     * Returns a 401 WP_Error immediately when a Bearer token is present but invalid,
     * so the caller gets a clear error instead of a generic "login required" message.
     */
    public static function check_authentication_errors( $error ) {
        if ( $error ) {
            return $error;
        }

        $token = self::extract_bearer_token();
        if ( ! $token ) {
            return $error;
        }

        if ( ! self::validate_token( $token ) ) {
            return new WP_Error(
                'rest_invalid_token',
                __( 'Invalid or expired API token. Please log in again.', 'auth-popup' ),
                [ 'status' => 401 ]
            );
        }

        return $error;
    }

    /**
     * Extract the raw token string from the Authorization: Bearer header.
     */
    private static function extract_bearer_token(): ?string {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        // Apache mod_rewrite can strip Authorization — check the redirect variable too
        if ( empty( $header ) ) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        }

        if ( empty( $header ) || strpos( $header, 'Bearer ' ) !== 0 ) {
            return null;
        }

        $token = trim( substr( $header, 7 ) );
        return ! empty( $token ) ? $token : null;
    }

    /**
     * Generate a new access + refresh token pair for a user.
     * Lifetimes are read from plugin settings (defaults: 12 h / 7 days).
     *
     * @return array{ token: string, refresh_token: string, expires_in: int }
     */
    public static function generate_token( int $user_id ): array {
        $access_hours   = max( 1, (int) Auth_Popup_Core::get_setting( 'token_lifetime_hours', 12 ) );
        $refresh_days   = max( 1, (int) Auth_Popup_Core::get_setting( 'refresh_token_lifetime_days', 7 ) );
        $access_expiry  = $access_hours * HOUR_IN_SECONDS;
        $refresh_expiry = $refresh_days * DAY_IN_SECONDS;

        $access_token  = bin2hex( random_bytes( 32 ) );
        $refresh_token = bin2hex( random_bytes( 32 ) );

        set_transient( 'ap_api_' . hash( 'sha256', $access_token ),  $user_id, $access_expiry );
        set_transient( 'ap_rt_'  . hash( 'sha256', $refresh_token ), $user_id, $refresh_expiry );

        return [
            'token'         => $access_token,
            'refresh_token' => $refresh_token,
            'expires_in'    => $access_expiry,
        ];
    }

    /**
     * Return the user ID for a valid access token, or 0 if invalid/expired.
     */
    private static function validate_token( string $token ): int {
        $user_id = (int) get_transient( 'ap_api_' . hash( 'sha256', $token ) );
        return $user_id > 0 ? $user_id : 0;
    }

    /**
     * Return the user ID for a valid refresh token, or 0 if invalid/expired.
     */
    private static function validate_refresh_token( string $token ): int {
        $user_id = (int) get_transient( 'ap_rt_' . hash( 'sha256', $token ) );
        return $user_id > 0 ? $user_id : 0;
    }

    /**
     * Permanently invalidate an access token (used on logout).
     */
    private static function revoke_token( string $token ): void {
        delete_transient( 'ap_api_' . hash( 'sha256', $token ) );
    }

    /**
     * Permanently invalidate a refresh token (used on logout and token rotation).
     */
    private static function revoke_refresh_token( string $token ): void {
        delete_transient( 'ap_rt_' . hash( 'sha256', $token ) );
    }

    /* ── Password Rate Limiting ──────────────────────────────────────── */

    private static function pw_ip_key(): string {
        $ip = Auth_Popup_OTP_Manager::get_client_ip() ?: 'unknown';
        return 'ap_pw_ip_' . wp_hash( $ip );
    }

    private static function pw_cred_key( string $credential ): string {
        return 'ap_pw_cr_' . wp_hash( strtolower( $credential ) );
    }

    /**
     * @return true|WP_Error
     */
    private static function check_password_rate_limit( string $credential ) {
        if ( (int) get_transient( self::pw_ip_key() ) >= 10 ) {
            return new WP_Error( 'rate_limited', __( 'Too many login attempts from your network. Please try again in 15 minutes.', 'auth-popup' ) );
        }
        if ( (int) get_transient( self::pw_cred_key( $credential ) ) >= 5 ) {
            return new WP_Error( 'rate_limited', __( 'Too many login attempts for this account. Please try again in 15 minutes.', 'auth-popup' ) );
        }
        return true;
    }

    private static function record_password_failure( string $credential ): void {
        $window   = 15 * MINUTE_IN_SECONDS;
        $ip_key   = self::pw_ip_key();
        $cred_key = self::pw_cred_key( $credential );
        set_transient( $ip_key,   (int) get_transient( $ip_key )   + 1, $window );
        set_transient( $cred_key, (int) get_transient( $cred_key ) + 1, $window );
    }

    private static function clear_password_failures( string $credential ): void {
        delete_transient( self::pw_ip_key() );
        delete_transient( self::pw_cred_key( $credential ) );
    }

    /* ── Loyalty Registration ────────────────────────────────────────── */

    private static function register_loyalty( WP_User $user, string $name, string $email, string $phone, WP_REST_Request $request ): string {
        $api_url = rtrim( (string) Auth_Popup_Core::get_setting( 'loyalty_api_url' ), '/' );
        if ( empty( $api_url ) ) {
            return '';
        }

        $gender      = (string) $request->get_param( 'gender' );
        $dob         = (string) $request->get_param( 'dob' );
        $card_number = (string) $request->get_param( 'card_number' );

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

        $login_res = wp_remote_post( $api_url . '/login', [
            'timeout' => 10,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'phone' => $phone ] ),
        ] );

        if ( ! is_wp_error( $login_res ) ) {
            $login_body = json_decode( wp_remote_retrieve_body( $login_res ), true );
            if ( ! empty( $login_body['success'] ) ) {
                update_user_meta( $user->ID, 'herlan_loyalty_registered', '1' );
                return __( 'You are already a loyal member!', 'auth-popup' );
            }
        }

        $response = wp_remote_post( $api_url . '/registration', [
            'timeout' => 10,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $data ),
        ] );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $body['success'] ) ) {
            update_user_meta( $user->ID, 'herlan_loyalty_registered', '1' );
            return __( 'You have joined the Herlan Star Loyalty Programme!', 'auth-popup' );
        }

        return '(' . ( $body['message'] ?? __( 'Loyalty registration failed.', 'auth-popup' ) ) . ')';
    }

    /* ── Response Helpers ────────────────────────────────────────────── */

    private static function success( string $message, $data = [], int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response(
            [
                'success'       => true,
                'response_code' => $status,
                'message'       => $message,
                'data'          => empty( $data ) ? (object) [] : $data,
            ],
            $status
        );
    }

    private static function error( string $message, int $status = 400 ): WP_REST_Response {
        return new WP_REST_Response(
            [
                'success'       => false,
                'response_code' => $status,
                'message'       => $message,
                'data'          => (object) [],
            ],
            $status
        );
    }

    /* ── Utilities ───────────────────────────────────────────────────── */

    private static function redirect_url( WP_REST_Request $request ): string {
        $home      = home_url();
        $home_host = (string) wp_parse_url( $home, PHP_URL_HOST );

        $url      = sanitize_url( (string) ( $request->get_param( 'redirect_to' ) ?? '' ) );
        $url_host = ! empty( $url ) ? (string) wp_parse_url( $url, PHP_URL_HOST ) : '';

        if ( ! empty( $url_host ) && $url_host === $home_host ) {
            return $url;
        }

        $configured = (string) Auth_Popup_Core::get_setting( 'redirect_url', $home );
        return ! empty( $configured ) ? $configured : $home;
    }

    private static function address_args( bool $required = true ): array {
        return [
            'label'      => [ 'required' => false,     'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
            'first_name' => [ 'required' => $required, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            'last_name'  => [ 'required' => false,     'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
            'company'    => [ 'required' => false,     'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
            'address_1'  => [ 'required' => $required, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            'address_2'  => [ 'required' => false,     'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
            'city'       => [ 'required' => false,     'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
            'state'      => [ 'required' => $required, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'description' => 'Bangladesh district code, e.g. BD-06.' ],
            'postcode'   => [ 'required' => false,     'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
            'country'    => [ 'required' => false,     'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'BD' ],
            'phone'      => [ 'required' => $required, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
        ];
    }
}
