<?php
defined( 'ABSPATH' ) || exit;

/**
 * Single admin-configured notice (title, image, message) shown when a guest
 * clicks "Continue as Guest" on checkout, with a button that reopens the
 * login/register popup.
 */
class Auth_Popup_Notice_Manager {

    public static function init(): void {
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_footer', [ __CLASS__, 'render_notice' ], 20 );
    }

    /**
     * The notice only ever matters where the "Continue as Guest" button can
     * appear (checkout, guests only) and only when the admin has configured
     * some content for it.
     */
    private static function should_display(): bool {
        if ( is_admin() || is_user_logged_in() ) {
            return false;
        }
        if ( Auth_Popup_Core::get_setting( 'notices_enabled', '1' ) !== '1' ) {
            return false;
        }
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return false;
        }

        $notice = self::get_notice_data();
        return '' !== $notice['title'] || '' !== $notice['message'] || '' !== $notice['image'];
    }

    private static function get_notice_data(): array {
        return [
            'title'   => Auth_Popup_Core::get_setting( 'notice_title', '' ),
            'message' => Auth_Popup_Core::get_setting( 'notice_message', '' ),
            'image'   => Auth_Popup_Core::get_setting( 'notice_image_url', '' ),
        ];
    }

    public static function enqueue_assets(): void {
        if ( ! self::should_display() ) {
            return;
        }

        $suffix = Auth_Popup_Core::asset_suffix();

        wp_enqueue_style(
            'auth-popup-notice',
            AUTH_POPUP_URL . "assets/css/notice-popup{$suffix}.css",
            [ 'auth-popup' ],
            AUTH_POPUP_VERSION
        );

        wp_enqueue_script(
            'auth-popup-notice',
            AUTH_POPUP_URL . "assets/js/notice-popup{$suffix}.js",
            [],
            AUTH_POPUP_VERSION,
            true
        );
    }

    public static function render_notice(): void {
        if ( ! self::should_display() ) {
            return;
        }

        $raw    = self::get_notice_data();
        $notice = [
            'title'   => $raw['title'],
            'message' => $raw['message'] ? wp_kses_post( wpautop( $raw['message'] ) ) : '',
            'image'   => $raw['image'],
        ];

        require AUTH_POPUP_PATH . 'public/views/notice-popup.php';
    }
}
