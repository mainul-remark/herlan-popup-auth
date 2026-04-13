<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles WordPress user creation, login, and profile management
 * for Auth Popup flows (password, OTP, OAuth).
 */
class Auth_Popup_User_Auth {

    /* ─────────────────────────────────────────────────────────────── */

    /**
     * Login a user by mobile number (after OTP is verified).
     * If no user exists, creates one automatically.
     *
     * @return WP_User|WP_Error
     */
    public static function login_or_create_by_phone( string $phone ) {
        $phone = Auth_Popup_SMS_Service::normalise_phone( $phone );
        $user  = self::get_user_by_phone( $phone );

        if ( ! $user ) {
            // Auto-create account
            $user = self::create_user_by_phone( $phone );
            if ( is_wp_error( $user ) ) {
                return $user;
            }
        }

        return self::do_login( $user );
    }

    /**
     * Standard username/email + password login.
     *
     * @return WP_User|WP_Error
     */
    public static function login_with_password( string $credential, string $password ) {
        // $credential can be email, username, or phone
        $user = null;

        if ( is_email( $credential ) ) {
            $user = get_user_by( 'email', $credential );
        } elseif ( Auth_Popup_SMS_Service::is_valid_phone( $credential ) ) {
            $user = self::get_user_by_phone( Auth_Popup_SMS_Service::normalise_phone( $credential ) );
        } else {
            $user = get_user_by( 'login', $credential );
        }

        if ( ! $user ) {
            return new WP_Error( 'invalid_credential', __( 'No account found with this credential.', 'auth-popup' ) );
        }

        if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
            return new WP_Error( 'wrong_password', __( 'Incorrect password.', 'auth-popup' ) );
        }

        return self::do_login( $user );
    }

    /**
     * Register a new user with name, phone, email, and password.
     * Phone OTP must already be verified before calling this.
     *
     * @return WP_User|WP_Error
     */
    public static function register( array $data ) {
        $phone = Auth_Popup_SMS_Service::normalise_phone( $data['phone'] ?? '' );
        $name  = sanitize_text_field( $data['name'] ?? '' );
        $email = sanitize_email( $data['email'] ?? '' );
        $pass  = $data['password'] ?? '';

        // Validations
        if ( empty( $name ) ) {
            return new WP_Error( 'missing_name', __( 'Full name is required.', 'auth-popup' ) );
        }

        if ( ! Auth_Popup_SMS_Service::is_valid_phone( $phone ) ) {
            return new WP_Error( 'invalid_phone', __( 'Invalid phone number.', 'auth-popup' ) );
        }

        if ( ! empty( $email ) && ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', __( 'Invalid email address.', 'auth-popup' ) );
        }

        // Check if phone already registered
        if ( self::get_user_by_phone( $phone ) ) {
            return new WP_Error( 'phone_exists', __( 'An account with this phone number already exists.', 'auth-popup' ) );
        }

        // Check email uniqueness
        if ( ! empty( $email ) && email_exists( $email ) ) {
            return new WP_Error( 'email_exists', __( 'An account with this email already exists.', 'auth-popup' ) );
        }

        if ( empty( $pass ) ) {
            return new WP_Error( 'missing_password', __( 'Password is required.', 'auth-popup' ) );
        }

        $username = self::generate_username( $name, $phone );

        $user_id = wp_insert_user( [
            'user_login'   => $username,
            'user_pass'    => $pass,
            'user_email'   => $email ?: '',
            'first_name'   => self::parse_first_name( $name ),
            'last_name'    => self::parse_last_name( $name ),
            'display_name' => $name,
            'role'         => 'customer', // default for WooCommerce sites
        ] );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        // Store phone in dedicated profiles table (fast indexed lookup)
        self::upsert_profile( $user_id, [ 'phone' => $phone ] );
        update_user_meta( $user_id, 'billing_phone', $phone ); // WooCommerce compat

        $user = get_user_by( 'ID', $user_id );

        // Send welcome email
        wp_new_user_notification( $user_id, null, 'user' );

        return self::do_login( $user );
    }

    /* ── OAuth helpers ──────────────────────────────────────────────── */

    /**
     * Login or create a user from OAuth provider data.
     *
     * @param array  $profile  ['email', 'name', 'avatar', 'provider_id']
     * @param string $provider 'google' | 'facebook'
     * @return WP_User|WP_Error
     */
    public static function login_or_create_oauth( array $profile, string $provider ) {
        $email       = sanitize_email( $profile['email'] ?? '' );
        $name        = sanitize_text_field( $profile['name'] ?? '' );
        $provider_id = sanitize_text_field( $profile['provider_id'] ?? '' );
        $avatar      = esc_url_raw( $profile['avatar'] ?? '' );

        if ( empty( $email ) ) {
            return new WP_Error( 'oauth_no_email', __( 'Could not retrieve email from provider.', 'auth-popup' ) );
        }

        // Check existing user by email
        $user = get_user_by( 'email', $email );

        if ( ! $user ) {
            // Create new user
            $username = self::generate_username( $name ?: $email );
            $user_id  = wp_insert_user( [
                'user_login'   => $username,
                'user_pass'    => wp_generate_password( 24 ),
                'user_email'   => $email,
                'first_name'   => self::parse_first_name( $name ),
                'last_name'    => self::parse_last_name( $name ),
                'display_name' => $name ?: $email,
                'role'         => 'customer',
            ] );

            if ( is_wp_error( $user_id ) ) {
                return $user_id;
            }

            $user = get_user_by( 'ID', $user_id );
            wp_new_user_notification( $user_id, null, 'user' );
        }

        // Store/update OAuth IDs in dedicated profiles table
        $profile_data = [ "{$provider}_id" => $provider_id ];
        if ( $avatar ) {
            $profile_data["{$provider}_avatar"] = $avatar;
        }
        self::upsert_profile( $user->ID, $profile_data );

        return self::do_login( $user );
    }

    /* ── Social login + phone completion ───────────────────────────── */

    /**
     * Find or create a user from a verified OAuth profile combined with a
     * phone number that has already been OTP-verified by the caller.
     *
     * Priority: phone match → email match → create new user.
     *
     * @param array  $profile  ['email', 'name', 'avatar', 'provider_id']
     * @param string $provider 'google' | 'facebook'
     * @param string $phone    Normalised phone number (already OTP-verified)
     * @return WP_User|WP_Error
     */
    public static function login_or_create_social_with_phone( array $profile, string $provider, string $phone ) {
        $email       = sanitize_email( $profile['email'] ?? '' );
        $name        = sanitize_text_field( $profile['name'] ?? '' );
        $provider_id = sanitize_text_field( $profile['provider_id'] ?? '' );
        $avatar      = esc_url_raw( $profile['avatar'] ?? '' );

        // 1. Try to find existing user by the OTP-verified phone
        $user = self::get_user_by_phone( $phone );

        // 2. Fall back to lookup by OAuth email
        if ( ! $user && ! empty( $email ) ) {
            $user = get_user_by( 'email', $email );
        }

        // 3. Create a brand-new user
        if ( ! $user ) {
            $username = self::generate_username( $name ?: $email );
            $user_id  = wp_insert_user( [
                'user_login'   => $username,
                'user_pass'    => wp_generate_password( 24 ),
                'user_email'   => $email ?: '',
                'first_name'   => self::parse_first_name( $name ),
                'last_name'    => self::parse_last_name( $name ),
                'display_name' => $name ?: ( 'User ' . substr( $phone, -4 ) ),
                'role'         => 'customer',
            ] );

            if ( is_wp_error( $user_id ) ) {
                return $user_id;
            }

            $user = get_user_by( 'ID', $user_id );
            wp_new_user_notification( $user_id, null, 'user' );
        }

        // Store / update the verified phone on the account
        self::upsert_profile( $user->ID, [ 'phone' => $phone ] );
        update_user_meta( $user->ID, 'billing_phone', $phone );

        // Store / update OAuth provider data
        $profile_data = [ "{$provider}_id" => $provider_id ];
        if ( $avatar ) {
            $profile_data["{$provider}_avatar"] = $avatar;
        }
        self::upsert_profile( $user->ID, $profile_data );

        return self::do_login( $user );
    }

    /* ── Private helpers ────────────────────────────────────────────── */

    private static function do_login( WP_User $user ) {
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true );
        do_action( 'wp_login', $user->user_login, $user );
        do_action( 'auth_popup_after_login', $user );
        return $user;
    }

    public static function get_user_by_phone( string $phone ): ?WP_User {
        global $wpdb;

        // Fast lookup via indexed profiles table
        $user_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}auth_popup_user_profiles WHERE phone = %s LIMIT 1",
            $phone
        ) );

        if ( $user_id ) {
            return get_user_by( 'ID', (int) $user_id ) ?: null;
        }

        // Fallback: legacy wp_usermeta rows (existing users before migration)
        $users = get_users( [
            'meta_key'   => 'billing_phone',
            'meta_value' => $phone,
            'number'     => 1,
        ] );

        return ! empty( $users ) ? $users[0] : null;
    }

    private static function create_user_by_phone( string $phone ) {
        $username = 'user_' . substr( md5( $phone . time() ), 0, 8 );

        $user_id = wp_insert_user( [
            'user_login'   => $username,
            'user_pass'    => wp_generate_password( 24 ),
            'user_email'   => '',
            'display_name' => __( 'User', 'auth-popup' ) . ' ' . substr( $phone, -4 ),
            'role'         => 'customer',
        ] );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        self::upsert_profile( $user_id, [ 'phone' => $phone ] );
        update_user_meta( $user_id, 'billing_phone', $phone );

        do_action( 'auth_popup_user_created_by_phone', $user_id, $phone );

        return get_user_by( 'ID', $user_id );
    }

    private static function generate_username( string $name, string $phone = '' ): string {
        $base = sanitize_user( strtolower( str_replace( ' ', '_', $name ) ), true );
        if ( empty( $base ) && $phone ) {
            $base = 'user_' . substr( preg_replace( '/\D/', '', $phone ), -8 );
        }
        $base    = $base ?: 'user';
        $username = $base;
        $counter  = 1;
        while ( username_exists( $username ) ) {
            $username = $base . $counter++;
        }
        return $username;
    }

    /**
     * Insert or update a row in the profiles table for the given user.
     * Only updates the columns passed in $data — other columns are untouched.
     */
    private static function upsert_profile( int $user_id, array $data ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'auth_popup_user_profiles';

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE user_id = %d",
            $user_id
        ) );

        if ( $exists ) {
            $wpdb->update( $table, $data, [ 'user_id' => $user_id ] );
        } else {
            $wpdb->insert( $table, array_merge( [ 'user_id' => $user_id ], $data ) );
        }
    }

    private static function parse_first_name( string $full_name ): string {
        $parts = explode( ' ', trim( $full_name ), 2 );
        return $parts[0] ?? '';
    }

    private static function parse_last_name( string $full_name ): string {
        $parts = explode( ' ', trim( $full_name ), 2 );
        return $parts[1] ?? '';
    }
}
