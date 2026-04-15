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
     * Tracks failed attempts; invalidates OTP after too many failures.
     * Deletes the transient on success (one-time use).
     */
    public static function verify( string $phone, string $otp ): bool {
        if ( self::is_verify_locked( $phone ) ) {
            return false; // OTP already invalidated by brute-force protection
        }

        $key    = self::transient_key( $phone );
        $stored = get_transient( $key );

        if ( false === $stored ) {
            return false; // expired or never set
        }

        if ( hash_equals( $stored, wp_hash( $otp ) ) ) {
            delete_transient( $key );
            self::reset_verify_attempts( $phone );
            return true;
        }

        self::increment_verify_attempts( $phone );
        return false;
    }

    /**
     * Check an OTP without consuming it (transient is kept).
     * Also tracks failed attempts to prevent brute-force via this endpoint.
     * Use this for intermediate validation steps; call verify() on final submit.
     */
    public static function peek( string $phone, string $otp ): bool {
        if ( self::is_verify_locked( $phone ) ) {
            return false;
        }

        $stored = get_transient( self::transient_key( $phone ) );
        if ( false === $stored ) {
            return false;
        }

        if ( hash_equals( $stored, wp_hash( $otp ) ) ) {
            return true;
        }

        self::increment_verify_attempts( $phone );
        return false;
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
        return str_pad( (string) random_int( 0, (int) str_repeat( '9', self::OTP_LENGTH ) ), self::OTP_LENGTH, '0', STR_PAD_LEFT );
    }

    /* ── Rate-limiting (OTP generation) ────────────────────────────── */

    private static function check_rate_limit( string $phone ): ?WP_Error {
        // 1. Per-phone rate limit
        $phone_error = self::check_phone_rate_limit( $phone );
        if ( $phone_error ) {
            return $phone_error;
        }

        // 2. Per-IP rate limit (guards against targeting many different phones from one IP)
        $ip_error = self::check_ip_rate_limit();
        if ( $ip_error ) {
            return $ip_error;
        }

        return null;
    }

    private static function check_phone_rate_limit( string $phone ): ?WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . 'auth_popup_otp_log';
        $max   = (int) Auth_Popup_Core::get_setting( 'otp_max_per_hour', 5 );
        $since = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ); // UTC

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

    private static function check_ip_rate_limit(): ?WP_Error {
        global $wpdb;
        $ip     = self::get_client_ip();
        if ( empty( $ip ) ) {
            return null; // Cannot determine IP — skip check
        }

        $table  = $wpdb->prefix . 'auth_popup_otp_log';
        $max_ip = (int) Auth_Popup_Core::get_setting( 'otp_max_per_hour_ip', 10 );
        $since  = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ); // UTC

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE ip_address = %s AND sent_at >= %s",
            $ip, $since
        ) );

        if ( $count >= $max_ip ) {
            return new WP_Error(
                'rate_limit_exceeded',
                __( 'Too many OTP requests from your network. Please try again later.', 'auth-popup' )
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
                'sent_at'    => gmdate( 'Y-m-d H:i:s' ), // UTC — consistent with rate-limit queries
            ],
            [ '%s', '%s', '%s' ]
        );
    }

    /**
     * Delete log entries older than 24 hours.
     * Called by a daily WP-Cron event registered in Auth_Popup_Core.
     */
    public static function cleanup_old_logs(): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}auth_popup_otp_log WHERE sent_at < %s",
            gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
        ) );
    }

    /* ── Brute-force protection (OTP verification) ──────────────────── */

    private static function verify_attempts_key( string $phone ): string {
        return 'auth_popup_vattempts_' . md5( $phone );
    }

    /**
     * Returns true and invalidates the OTP if too many failed attempts occurred.
     */
    private static function is_verify_locked( string $phone ): bool {
        $max      = (int) Auth_Popup_Core::get_setting( 'otp_max_verify_attempts', 5 );
        $attempts = (int) get_transient( self::verify_attempts_key( $phone ) );

        if ( $attempts >= $max ) {
            self::invalidate( $phone ); // Burn the OTP so it can no longer be tried
            return true;
        }

        return false;
    }

    private static function increment_verify_attempts( string $phone ): void {
        $key      = self::verify_attempts_key( $phone );
        $attempts = (int) get_transient( $key );
        $expiry   = (int) Auth_Popup_Core::get_setting( 'otp_expiry_minutes', 5 );
        set_transient( $key, $attempts + 1, $expiry * MINUTE_IN_SECONDS );
    }

    private static function reset_verify_attempts( string $phone ): void {
        delete_transient( self::verify_attempts_key( $phone ) );
    }

    /* ── Helpers ────────────────────────────────────────────────────── */

    public static function get_client_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                return sanitize_text_field( explode( ',', $_SERVER[ $key ] )[0] );
            }
        }
        return '';
    }
}
