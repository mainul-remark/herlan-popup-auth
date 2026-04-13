<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles OTP generation, storage (WP transients) and verification.
 * Rate-limiting is tracked in a custom DB table.
 */
class Auth_Popup_OTP_Manager {

    /** Length of generated OTP */
    private const OTP_LENGTH = 6;

    /* ─────────────────────────────────────────────────────────────── */

    /**
     * Generate a fresh OTP, store it, and return it.
     * Returns WP_Error if rate-limit is exceeded.
     *
     * @param string $phone Normalised phone number (e.g. 8801712345678)
     * @return string|WP_Error
     */
    public static function generate( string $phone ) {
        $rate_check = self::check_rate_limit( $phone );
        if ( is_wp_error( $rate_check ) ) {
            return $rate_check;
        }

        $otp = self::random_otp();
        $expiry = (int) Auth_Popup_Core::get_setting( 'otp_expiry_minutes', 5 );

        // Store OTP: keyed by phone, expires in N minutes
        set_transient( self::transient_key( $phone ), wp_hash( $otp ), $expiry * MINUTE_IN_SECONDS );

        // Record in rate-limit log
        self::log_otp_request( $phone );

        return $otp;
    }

    /**
     * Verify an OTP for a given phone number.
     * Deletes the transient on success (one-time use).
     */
    public static function verify( string $phone, string $otp ): bool {
        $key   = self::transient_key( $phone );
        $stored = get_transient( $key );

        if ( false === $stored ) {
            return false; // expired or never set
        }

        if ( hash_equals( $stored, wp_hash( $otp ) ) ) {
            delete_transient( $key );
            return true;
        }

        return false;
    }

    /**
     * Check an OTP without consuming it (transient is kept).
     * Use this for intermediate validation steps; call verify() on final submit.
     */
    public static function peek( string $phone, string $otp ): bool {
        $stored = get_transient( self::transient_key( $phone ) );
        if ( false === $stored ) {
            return false;
        }
        return hash_equals( $stored, wp_hash( $otp ) );
    }

    /**
     * Delete (invalidate) an OTP manually.
     */
    public static function invalidate( string $phone ): void {
        delete_transient( self::transient_key( $phone ) );
    }

    /* ── Helpers ────────────────────────────────────────────────────── */

    private static function transient_key( string $phone ): string {
        return 'auth_popup_otp_' . md5( $phone );
    }

    private static function random_otp(): string {
        return str_pad( (string) wp_rand( 0, (int) str_repeat( '9', self::OTP_LENGTH ) ), self::OTP_LENGTH, '0', STR_PAD_LEFT );
    }

    /* ── Rate-limiting ──────────────────────────────────────────────── */

    private static function check_rate_limit( string $phone ): ?WP_Error {
        global $wpdb;
        $table   = $wpdb->prefix . 'auth_popup_otp_log';
        $max     = (int) Auth_Popup_Core::get_setting( 'otp_max_per_hour', 5 );
        $since   = date( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE phone = %s AND sent_at >= %s",
            $phone, $since
        ) );

        if ( $count >= $max ) {
            return new WP_Error(
                'rate_limit_exceeded',
                __( 'Too many OTP requests. Please try again later.', 'auth-popup' )
            );
        }

        return null;
    }

    private static function log_otp_request( string $phone ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'auth_popup_otp_log',
            [
                'phone'      => sanitize_text_field( $phone ),
                'ip_address' => self::get_client_ip(),
                'sent_at'    => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s' ]
        );
    }

    private static function get_client_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                return sanitize_text_field( explode( ',', $_SERVER[ $key ] )[0] );
            }
        }
        return '';
    }
}
