<?php
defined( 'ABSPATH' ) || exit;

/**
 * Self-contained anti-bot layer for the public OTP / email-verification
 * forms: a honeypot field (real users never see or fill it) plus a signed
 * timestamp token that rejects submissions faster than a human could
 * plausibly complete the form. No external service or API key required.
 *
 * This is a bot-deterrent layered on top of the existing nonce checks and
 * per-IP/per-account rate limits in Auth_Popup_Ajax_Handler — it raises the
 * cost of naive/scripted abuse, but is not a substitute for network-level
 * DDoS mitigation (e.g. a CDN/WAF) against a determined, distributed attack.
 */
class Auth_Popup_Form_Guard {

    public const HONEYPOT_FIELD = 'ap_hp_url';
    public const TOKEN_FIELD    = 'ap_form_ts';

    /**
     * Minimum seconds between token issue and submission. Kept low (rather
     * than a more "obvious" 2-3s) because the password-login form can be
     * submitted a moment after render by a browser-autofilled credential
     * plus an immediate Enter/click — still well above the sub-second
     * timing of a scripted instant-submit bot.
     */
    private const MIN_AGE = 1;

    /**
     * Maximum token lifetime before it's considered stale and must be
     * refreshed. Matches a WP nonce "tick" (12h) rather than a short window
     * so long-lived sessions — the checkout email-verify bar, or a popup
     * left open in a background tab — don't hit a new expiry error sooner
     * than the existing nonce would already have expired.
     */
    private const MAX_AGE = 12 * HOUR_IN_SECONDS;

    /**
     * Build a fresh "<timestamp>.<hmac>" token string.
     */
    public static function issue_token(): string {
        $ts = time();
        return $ts . '.' . self::sign( $ts );
    }

    /**
     * Echo the honeypot input + hidden timestamp field for use inside a
     * real <form> element. The wrapper is positioned off-screen (not
     * display:none) so form-filling bots that skip display:none fields
     * still find and fill it.
     */
    public static function render_fields(): void {
        $token = self::issue_token();
        ?>
        <div class="ap-hp-wrap" aria-hidden="true" style="position:absolute !important;left:-9999px !important;top:-9999px !important;width:1px;height:1px;overflow:hidden;">
            <label for="<?php echo esc_attr( self::HONEYPOT_FIELD ); ?>">Leave this field blank</label>
            <input type="text" id="<?php echo esc_attr( self::HONEYPOT_FIELD ); ?>" name="<?php echo esc_attr( self::HONEYPOT_FIELD ); ?>" value="" tabindex="-1" autocomplete="off">
        </div>
        <input type="hidden" name="<?php echo esc_attr( self::TOKEN_FIELD ); ?>" value="<?php echo esc_attr( $token ); ?>">
        <?php
    }

    /**
     * Same data as render_fields(), for JS-driven widgets that don't submit
     * a native <form> (they build their own ajax `data` object instead).
     */
    public static function token_data(): array {
        return [
            self::HONEYPOT_FIELD => '',
            self::TOKEN_FIELD    => self::issue_token(),
        ];
    }

    /**
     * Validate a submitted request. Reads $_POST unless an array is supplied.
     *
     * @return true|WP_Error
     */
    public static function verify( ?array $source = null ) {
        $source = null !== $source ? $source : $_POST; // phpcs:ignore WordPress.Security.NonceVerification

        // Honeypot: any value at all means every field was filled blindly.
        if ( ! empty( $source[ self::HONEYPOT_FIELD ] ) ) {
            return new WP_Error( 'bot_detected', __( 'Security check failed. Please refresh the page and try again.', 'auth-popup' ) );
        }

        $token = isset( $source[ self::TOKEN_FIELD ] ) ? (string) $source[ self::TOKEN_FIELD ] : '';
        if ( '' === $token || false === strpos( $token, '.' ) ) {
            return new WP_Error( 'bot_detected', __( 'Security check failed. Please refresh the page and try again.', 'auth-popup' ) );
        }

        [ $ts_raw, $sig ] = array_pad( explode( '.', $token, 2 ), 2, '' );
        $ts = (int) $ts_raw;

        if ( $ts <= 0 || '' === $sig || ! hash_equals( self::sign( $ts ), $sig ) ) {
            return new WP_Error( 'bot_detected', __( 'Security check failed. Please refresh the page and try again.', 'auth-popup' ) );
        }

        $age = time() - $ts;

        if ( $age < self::MIN_AGE ) {
            return new WP_Error( 'too_fast', __( 'That was a bit too quick. Please try again.', 'auth-popup' ) );
        }

        if ( $age > self::MAX_AGE ) {
            return new WP_Error( 'stale_form', __( 'This form has expired. Please refresh the page and try again.', 'auth-popup' ) );
        }

        return true;
    }

    private static function sign( int $ts ): string {
        return hash_hmac( 'sha256', (string) $ts, wp_salt( 'auth' ) );
    }
}
