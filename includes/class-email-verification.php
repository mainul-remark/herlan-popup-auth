<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles admin-configured "required email domain" verification: 6-digit
 * emailed codes, per-user verified state (stored on the profiles table),
 * and re-verification expiry. Domains not in the admin list are a complete
 * no-op — every public method fast-exits for them.
 */
class Auth_Popup_Email_Verification {

    /* ── Domain configuration ──────────────────────────────────────── */

    /**
     * Parse the 'email_verify_domains' setting into a normalised array.
     */
    public static function get_required_domains(): array {
        $raw = (string) Auth_Popup_Core::get_setting( 'email_verify_domains', '' );
        if ( '' === trim( $raw ) ) {
            return [];
        }

        $domains = array_map( static function ( $d ) {
            return ltrim( strtolower( trim( $d ) ), '@' );
        }, explode( ',', $raw ) );

        return array_values( array_unique( array_filter( $domains ) ) );
    }

    public static function is_domain_required( string $email ): bool {
        $domains = self::get_required_domains();
        if ( empty( $domains ) || false === strpos( $email, '@' ) ) {
            return false;
        }

        $domain = strtolower( substr( strrchr( $email, '@' ), 1 ) );

        return in_array( $domain, $domains, true );
    }

    /* ── Verification state ────────────────────────────────────────── */

    /**
     * True if the domain is not required, or the email is verified and
     * that verification hasn't expired.
     */
    public static function is_verified( int $user_id, string $email ): bool {
        if ( ! self::is_domain_required( $email ) ) {
            return true;
        }

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT email_verified, email_verified_at FROM {$wpdb->prefix}auth_popup_user_profiles WHERE user_id = %d",
            $user_id
        ) );

        if ( ! $row || ! $row->email_verified ) {
            return false;
        }

        $expiry_days = (int) Auth_Popup_Core::get_setting( 'email_verify_expiry_days', 90 );
        if ( $expiry_days > 0 && $row->email_verified_at ) {
            $verified_ts = strtotime( $row->email_verified_at . ' UTC' );
            if ( false !== $verified_ts && ( time() - $verified_ts ) > ( $expiry_days * DAY_IN_SECONDS ) ) {
                return false; // expired — needs re-verification
            }
        }

        return true;
    }

    public static function needs_verification( int $user_id, string $email ): bool {
        return self::is_domain_required( $email ) && ! self::is_verified( $user_id, $email );
    }

    public static function mark_verified( int $user_id ): void {
        self::upsert_profile( $user_id, [
            'email_verified'    => 1,
            'email_verified_at' => gmdate( 'Y-m-d H:i:s' ),
        ] );
    }

    public static function reset_verification( int $user_id ): void {
        self::upsert_profile( $user_id, [
            'email_verified'    => 0,
            'email_verified_at' => null,
        ] );
    }

    /**
     * Hooked to WordPress' 'profile_update' action. If the account email
     * changed, the previous verification no longer applies to it.
     */
    public static function maybe_reset_on_email_change( int $user_id, \WP_User $old_user_data ): void {
        $new_user = get_userdata( $user_id );
        if ( $new_user && $new_user->user_email !== $old_user_data->user_email ) {
            self::reset_verification( $user_id );
        }
    }

    /* ── Code send / verify ────────────────────────────────────────── */

    /**
     * Generate, store, and email a 6-digit verification code.
     *
     * @return true|WP_Error
     */
    public static function send_code( int $user_id, string $email ) {
        $rate_check = self::check_rate_limit( $email );
        if ( is_wp_error( $rate_check ) ) {
            return $rate_check;
        }

        $code   = self::random_code();
        $expiry = (int) Auth_Popup_Core::get_setting( 'email_verify_otp_expiry_minutes', 10 ) * MINUTE_IN_SECONDS;

        set_transient( self::code_key( $email ), wp_hash( $code ), $expiry );
        set_transient( self::lock_key( $email ), 1, 60 ); // 60-second resend cooldown
        self::increment_hour_count( $email );

        $user      = get_userdata( $user_id );
        $name      = $user ? $user->display_name : '';
        $site_name = get_bloginfo( 'name' );

        /* translators: %s: site name */
        $subject = sprintf( __( 'Verify your email – %s', 'auth-popup' ), $site_name );
        $body    = self::build_email_body( $name, $code, $site_name );

        $sent = self::send_mail( $email, $subject, $body );
        if ( is_wp_error( $sent ) ) {
            delete_transient( self::code_key( $email ) );
            delete_transient( self::lock_key( $email ) );
            return $sent;
        }

        return true;
    }

    /**
     * Verify a submitted code. On success, marks the account as verified.
     *
     * @return true|WP_Error
     */
    public static function verify_code( int $user_id, string $email, string $code ) {
        if ( self::is_verify_locked( $email ) ) {
            return new WP_Error( 'too_many_attempts', __( 'Too many incorrect attempts. Please request a new code.', 'auth-popup' ) );
        }

        $key    = self::code_key( $email );
        $stored = get_transient( $key );

        if ( false === $stored ) {
            return new WP_Error( 'code_expired', __( 'This code has expired. Please request a new one.', 'auth-popup' ) );
        }

        if ( ! hash_equals( $stored, wp_hash( $code ) ) ) {
            self::increment_verify_attempts( $email );
            return new WP_Error( 'invalid_code', __( 'Incorrect code. Please try again.', 'auth-popup' ) );
        }

        delete_transient( $key );
        self::reset_verify_attempts( $email );
        self::mark_verified( $user_id );

        return true;
    }

    /* ── Private helpers ───────────────────────────────────────────── */

    private static function random_code(): string {
        return str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
    }

    private static function code_key( string $email ): string {
        return 'ap_ev_' . md5( strtolower( $email ) );
    }

    private static function lock_key( string $email ): string {
        return 'ap_ev_lock_' . md5( strtolower( $email ) );
    }

    private static function attempts_key( string $email ): string {
        return 'ap_ev_va_' . md5( strtolower( $email ) );
    }

    private static function hour_count_key( string $email ): string {
        return 'ap_ev_hc_' . md5( strtolower( $email ) );
    }

    private static function check_rate_limit( string $email ): ?WP_Error {
        if ( get_transient( self::lock_key( $email ) ) ) {
            return new WP_Error( 'resend_cooldown', __( 'Please wait a moment before requesting another code.', 'auth-popup' ) );
        }

        $max   = (int) Auth_Popup_Core::get_setting( 'email_verify_max_per_hour', 5 );
        $count = (int) get_transient( self::hour_count_key( $email ) );

        if ( $count >= $max ) {
            return new WP_Error( 'rate_limit_exceeded', __( 'Too many verification codes requested. Please try again later.', 'auth-popup' ) );
        }

        return null;
    }

    private static function increment_hour_count( string $email ): void {
        $key   = self::hour_count_key( $email );
        $count = (int) get_transient( $key );
        set_transient( $key, $count + 1, HOUR_IN_SECONDS );
    }

    private static function is_verify_locked( string $email ): bool {
        $max      = (int) Auth_Popup_Core::get_setting( 'email_verify_max_verify_attempts', 5 );
        $attempts = (int) get_transient( self::attempts_key( $email ) );

        if ( $attempts >= $max ) {
            delete_transient( self::code_key( $email ) ); // burn the code so it can no longer be tried
            return true;
        }

        return false;
    }

    private static function increment_verify_attempts( string $email ): void {
        $key      = self::attempts_key( $email );
        $attempts = (int) get_transient( $key );
        $expiry   = (int) Auth_Popup_Core::get_setting( 'email_verify_otp_expiry_minutes', 10 );
        set_transient( $key, $attempts + 1, $expiry * MINUTE_IN_SECONDS );
    }

    private static function reset_verify_attempts( string $email ): void {
        delete_transient( self::attempts_key( $email ) );
    }

    /**
     * Insert or update a row in the profiles table for the given user.
     * Only touches the columns passed in $data.
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

    /**
     * Send an HTML email with the plugin's standard From-address sanitizer
     * (fixes invalid dev/local From addresses) and error-capturing listener.
     * This is the single mail-sending path for this feature.
     *
     * @return true|WP_Error
     */
    private static function send_mail( string $to, string $subject, string $body ) {
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

        $sent = wp_mail( $to, $subject, $body, $headers );

        remove_filter( 'wp_mail_from', $from_sanitizer, PHP_INT_MAX );
        remove_action( 'wp_mail_failed', $error_listener );

        if ( ! $sent ) {
            return new WP_Error(
                'mail_failed',
                $mail_error ?: __( 'Mail server returned an error. Please contact support.', 'auth-popup' )
            );
        }

        return true;
    }

    private static function build_email_body( string $name, string $code, string $site_name ): string {
        $digits = '';
        foreach ( str_split( $code ) as $d ) {
            $digits .= '<span style="display:inline-block;width:40px;height:48px;line-height:48px;text-align:center;font-size:28px;font-weight:700;border:2px solid #e5e7eb;border-radius:8px;margin:0 4px;color:#111827;">' . esc_html( $d ) . '</span>';
        }

        $greeting = $name ? esc_html( $name ) : __( 'there', 'auth-popup' );

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f9fafb;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;padding:40px 0;">
  <tr><td align="center">
    <table width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.08);">
      <tr><td style="background:#111827;padding:28px 36px;">
        <h1 style="margin:0;color:#ffffff;font-size:20px;">' . esc_html( $site_name ) . '</h1>
      </td></tr>
      <tr><td style="padding:36px;">
        <p style="margin:0 0 8px;color:#374151;font-size:15px;">Hello <strong>' . $greeting . '</strong>,</p>
        <p style="margin:0 0 28px;color:#6b7280;font-size:14px;">Use the code below to verify your email address.</p>
        <div style="text-align:center;margin:0 0 28px;">' . $digits . '</div>
        <p style="margin:0;color:#9ca3af;font-size:12px;">If you did not request this, please ignore this email. Do not share this code with anyone.</p>
      </td></tr>
      <tr><td style="background:#f3f4f6;padding:16px 36px;text-align:center;">
        <p style="margin:0;color:#9ca3af;font-size:12px;">&copy; ' . esc_html( $site_name ) . '</p>
      </td></tr>
    </table>
  </td></tr>
</table></body></html>';
    }
}
