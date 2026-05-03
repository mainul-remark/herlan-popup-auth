<?php
defined( 'ABSPATH' ) || exit;

/**
 * SSLCommerce iSMS Plus (se.smsplus.net) API wrapper.
 *
 * Reference: https://github.com/sslw/ismsplus_api
 *
 * POST {base_url}/send-sms
 * Headers: Authorization: Bearer {api_token}
 * Body (JSON):
 *   sid      – Sender ID
 *   msisdn   – array of recipient numbers (BD format: 8801XXXXXXXXX)
 *   sms      – message body
 *   csms_id  – unique campaign/message ID
 */
class Auth_Popup_SMS_Service {

    /**
     * Send an OTP SMS.
     *
     * @param string $phone Normalised phone number
     * @param string $otp   The one-time password
     * @return true|WP_Error
     */
    public static function send_otp( string $phone, string $otp ) {
        $api_token = Auth_Popup_Core::get_setting( 'sms_api_token' );
        $sender_id = Auth_Popup_Core::get_setting( 'sms_sender_id' );
        $base_url  = rtrim( (string) Auth_Popup_Core::get_setting( 'sms_base_url', 'https://se.smsplus.net/api/v1' ), '/' );

        if ( empty( $api_token ) || empty( $sender_id ) ) {
            return new WP_Error( 'sms_not_configured', __( 'SMS gateway is not configured.', 'auth-popup' ) );
        }

        $brand   = Auth_Popup_Core::get_setting( 'popup_brand_name', get_bloginfo( 'name' ) );
        $expiry  = (int) Auth_Popup_Core::get_setting( 'otp_expiry_minutes', 5 );
        $message = sprintf(
            /* translators: 1: brand name, 2: OTP code, 3: expiry minutes */
            __( '[%1$s] Your OTP is %2$s. Valid for %3$d minutes. Do not share it.', 'auth-popup' ),
            $brand, $otp, $expiry
        );

        $payload = [
            'api_token' => $api_token,
            'sid'       => $sender_id,
            'msisdn'    => self::normalise_phone( $phone ),
            'sms'       => $message,
            'csms_id'   => 'AP_' . time() . '_' . wp_generate_password( 6, false ),
        ];

        $response = wp_remote_post(
            $base_url . '/send-sms',
            [
                'timeout'     => 15,
                'headers'     => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body' => wp_json_encode( $payload ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        // iSMS Plus returns 202 on success
        if ( in_array( (int) $code, [ 200, 202 ], true ) ) {
            return true;
        }

        $error_msg = $body['message'] ?? $body['error'] ?? __( 'Unknown SMS gateway error.', 'auth-popup' );
        return new WP_Error( 'sms_send_failed', $error_msg );
    }

    /**
     * Normalise a phone number to Bangladeshi international format (880XXXXXXXXXX).
     */
    public static function normalise_phone( string $phone ): string {
        $phone = preg_replace( '/\D/', '', $phone );

        // Remove leading zeros
        $phone = ltrim( $phone, '0' );

        // If starts with 880, it's already correct
        if ( strpos( $phone, '880' ) === 0 ) {
            return $phone;
        }

        // If 10-digit BD number (1XXXXXXXXX), prepend 880
        if ( strlen( $phone ) === 10 && strpos( $phone, '1' ) === 0 ) {
            return '880' . $phone;
        }

        return $phone;
    }

    /**
     * Validate phone number format (BD mobile: 01XXXXXXXXX or +880...).
     */
    public static function is_valid_phone( string $phone ): bool {
        $clean = preg_replace( '/\D/', '', $phone );
        // Accept: 01XXXXXXXXX (11), 8801XXXXXXXXX (13), 001XXXXXXXXX (13)
        return (bool) preg_match( '/^(880|00880)?0?1[3-9]\d{8}$/', $clean );
    }
}
