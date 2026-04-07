<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles server-side verification of Google and Facebook OAuth tokens.
 */
class Auth_Popup_OAuth_Handler {

    /* ── Google ─────────────────────────────────────────────────────── */

    /**
     * Verify Google ID token and extract user profile.
     * Uses Google's tokeninfo endpoint (no SDK dependency).
     *
     * @param string $id_token  Token received from frontend google.accounts.id
     * @return array|WP_Error   User profile array on success
     */
    public static function verify_google_token( string $access_token ) {
        if ( empty( Auth_Popup_Core::get_setting( 'google_client_id' ) ) ) {
            return new WP_Error( 'google_not_configured', __( 'Google login is not configured.', 'auth-popup' ) );
        }

        // Fetch user profile using access token
        $response = wp_remote_get(
            'https://www.googleapis.com/oauth2/v3/userinfo',
            [
                'timeout' => 15,
                'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== (int) $code || empty( $body ) ) {
            return new WP_Error( 'google_token_invalid', __( 'Invalid Google token.', 'auth-popup' ) );
        }

        if ( empty( $body['email'] ) ) {
            return new WP_Error( 'google_no_email', __( 'Google account has no email.', 'auth-popup' ) );
        }

        return [
            'email'       => $body['email'],
            'name'        => $body['name'] ?? '',
            'avatar'      => $body['picture'] ?? '',
            'provider_id' => $body['sub'] ?? '',
        ];
    }

    /* ── Facebook ───────────────────────────────────────────────────── */

    /**
     * Verify Facebook access token and extract user profile.
     * Uses Facebook Graph API debug endpoint + /me endpoint.
     *
     * @param string $access_token Token received from Facebook JS SDK
     * @return array|WP_Error
     */
    public static function verify_facebook_token( string $access_token ) {
        $app_id     = Auth_Popup_Core::get_setting( 'fb_app_id' );
        $app_secret = Auth_Popup_Core::get_setting( 'fb_app_secret' );

        if ( empty( $app_id ) || empty( $app_secret ) ) {
            return new WP_Error( 'facebook_not_configured', __( 'Facebook login is not configured.', 'auth-popup' ) );
        }

        // Step 1: Debug / validate the token
        $app_token = $app_id . '|' . $app_secret;
        $debug_url = add_query_arg( [
            'input_token'  => $access_token,
            'access_token' => $app_token,
        ], 'https://graph.facebook.com/debug_token' );

        $debug_res = wp_remote_get( $debug_url, [ 'timeout' => 15 ] );

        if ( is_wp_error( $debug_res ) ) {
            return $debug_res;
        }

        $debug_body = json_decode( wp_remote_retrieve_body( $debug_res ), true );

        if ( empty( $debug_body['data']['is_valid'] ) || ! $debug_body['data']['is_valid'] ) {
            return new WP_Error( 'fb_token_invalid', __( 'Invalid Facebook token.', 'auth-popup' ) );
        }

        if ( (string) $debug_body['data']['app_id'] !== (string) $app_id ) {
            return new WP_Error( 'fb_token_mismatch', __( 'Facebook token app ID mismatch.', 'auth-popup' ) );
        }

        // Step 2: Get user profile
        $me_url = add_query_arg( [
            'fields'       => 'id,name,email,picture.type(large)',
            'access_token' => $access_token,
        ], 'https://graph.facebook.com/v19.0/me' );

        $me_res = wp_remote_get( $me_url, [ 'timeout' => 15 ] );

        if ( is_wp_error( $me_res ) ) {
            return $me_res;
        }

        $me_body = json_decode( wp_remote_retrieve_body( $me_res ), true );

        if ( empty( $me_body['id'] ) ) {
            return new WP_Error( 'fb_profile_failed', __( 'Could not retrieve Facebook profile.', 'auth-popup' ) );
        }

        return [
            'email'       => $me_body['email'] ?? '',
            'name'        => $me_body['name']  ?? '',
            'avatar'      => $me_body['picture']['data']['url'] ?? '',
            'provider_id' => $me_body['id'],
        ];
    }
}
