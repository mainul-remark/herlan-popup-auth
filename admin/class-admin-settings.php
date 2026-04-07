<?php
defined( 'ABSPATH' ) || exit;

class Auth_Popup_Admin_Settings {

    public static function init(): void {
        add_action( 'admin_menu',             [ __CLASS__, 'add_menu'           ] );
        add_action( 'admin_init',             [ __CLASS__, 'register_settings'  ] );
        add_action( 'admin_enqueue_scripts',  [ __CLASS__, 'enqueue_assets'     ] );
        add_filter( 'plugin_action_links_' . AUTH_POPUP_BASENAME, [ __CLASS__, 'plugin_action_links' ] );

        // Phone migration AJAX
        add_action( 'wp_ajax_auth_popup_migration_status', [ __CLASS__, 'ajax_migration_status' ] );
        add_action( 'wp_ajax_auth_popup_run_migration',    [ __CLASS__, 'ajax_run_migration'    ] );

        // WC address import AJAX
        add_action( 'wp_ajax_auth_popup_wc_import_status', [ __CLASS__, 'ajax_wc_import_status' ] );
        add_action( 'wp_ajax_auth_popup_run_wc_import',    [ __CLASS__, 'ajax_run_wc_import'    ] );
    }

    public static function add_menu(): void {
        add_options_page(
            __( 'Auth Popup Settings', 'auth-popup' ),
            __( 'Auth Popup',          'auth-popup' ),
            'manage_options',
            'auth-popup-settings',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function register_settings(): void {
        register_setting( 'auth_popup_settings_group', 'auth_popup_settings', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
        ] );
    }

    public static function sanitize_settings( $raw ): array {
        $defaults = Auth_Popup_Core::default_settings();
        $clean    = [];

        // Boolean keys: unchecked checkboxes are absent from $_POST entirely,
        // so we must default to '0', not the setting default.
        $boolean_keys = [
            'enable_password_login',
            'enable_otp_login',
            'enable_google',
            'enable_facebook',
            'loyalty_enabled',
            'checkout_hide_shipping_form',
            'checkout_disable_ship_to_different',
        ];

        foreach ( $defaults as $key => $default ) {
            $value = in_array( $key, $boolean_keys, true )
                ? ( $raw[ $key ] ?? '0' )   // unchecked → not sent → '0'
                : ( $raw[ $key ] ?? $default );

            switch ( $key ) {
                case 'redirect_url':
                    $clean[ $key ] = esc_url_raw( $value );
                    break;
                case 'popup_logo_url':
                    $clean[ $key ] = esc_url_raw( $value );
                    break;
                case 'otp_expiry_minutes':
                case 'otp_max_per_hour':
                    $clean[ $key ] = absint( $value );
                    break;
                case 'enable_password_login':
                case 'enable_otp_login':
                case 'enable_google':
                case 'enable_facebook':
                case 'loyalty_enabled':
                case 'checkout_hide_shipping_form':
                case 'checkout_disable_ship_to_different':
                    $clean[ $key ] = ( '1' === (string) $value ) ? '1' : '0';
                    break;
                case 'loyalty_api_url':
                    $clean[ $key ] = esc_url_raw( $value );
                    break;
                default:
                    $clean[ $key ] = sanitize_text_field( $value );
            }
        }

        return $clean;
    }

    public static function enqueue_assets( string $hook ): void {
        if ( 'settings_page_auth-popup-settings' !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'auth-popup-admin',
            AUTH_POPUP_URL . 'assets/css/admin.css',
            [],
            AUTH_POPUP_VERSION
        );
        wp_localize_script( 'jquery', 'AuthPopupAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'auth_popup_admin_nonce' ),
        ] );
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        require AUTH_POPUP_PATH . 'admin/views/settings.php';
    }

    public static function plugin_action_links( array $links ): array {
        $settings_link = '<a href="' . admin_url( 'options-general.php?page=auth-popup-settings' ) . '">'
            . __( 'Settings', 'auth-popup' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /* ── Migration AJAX ────────────────────────────────────────────── */

    public static function ajax_migration_status(): void {
        check_ajax_referer( 'auth_popup_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        global $wpdb;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta}
             WHERE meta_key = 'billing_phone' AND meta_value != ''"
        );

        $migrated = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}auth_popup_user_profiles
             WHERE phone IS NOT NULL AND phone != ''"
        );

        $done    = (bool) get_option( 'auth_popup_phone_migration_done' );
        $percent = $total > 0 ? min( 100, (int) round( ( $migrated / $total ) * 100 ) ) : 100;

        // Next scheduled batch
        $next_run = null;
        if ( function_exists( 'as_get_scheduled_actions' ) ) {
            $pending = as_get_scheduled_actions( [
                'hook'     => 'auth_popup_migrate_phones_batch',
                'status'   => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 1,
            ] );
            if ( ! empty( $pending ) ) {
                $action   = reset( $pending );
                $next_run = $action->get_schedule()->get_date()->format( 'Y-m-d H:i:s' );
            }
        } elseif ( $next = wp_next_scheduled( 'auth_popup_migrate_phones_batch' ) ) {
            $next_run = date( 'Y-m-d H:i:s', $next );
        }

        wp_send_json_success( [
            'total'    => $total,
            'migrated' => $migrated,
            'percent'  => $percent,
            'done'     => $done,
            'next_run' => $next_run,
        ] );
    }

    public static function ajax_run_migration(): void {
        check_ajax_referer( 'auth_popup_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        global $wpdb;

        if ( ! empty( $_POST['restart'] ) ) {
            delete_option( 'auth_popup_phone_migration_done' );
        }

        // Calculate offset from rows already migrated
        $offset = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}auth_popup_user_profiles
             WHERE phone IS NOT NULL AND phone != ''"
        );

        Auth_Popup_Core::run_migration_batch( $offset );

        wp_send_json_success( [ 'message' => 'Batch processed.' ] );
    }

    /* ── WC Address Import AJAX ────────────────────────────────────── */

    public static function ajax_wc_import_status(): void {
        check_ajax_referer( 'auth_popup_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $status  = Auth_Popup_Core::wc_address_import_status();
        $percent = $status['total'] > 0
            ? min( 100, (int) round( ( $status['imported'] / $status['total'] ) * 100 ) )
            : 100;

        wp_send_json_success( array_merge( $status, [ 'percent' => $percent ] ) );
    }

    public static function ajax_run_wc_import(): void {
        check_ajax_referer( 'auth_popup_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        if ( ! empty( $_POST['restart'] ) ) {
            delete_option( 'auth_popup_wc_address_import_done' );
        }

        // Run one batch immediately (offset = rows already in our table)
        global $wpdb;
        $offset = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}auth_popup_user_addresses"
        );

        Auth_Popup_Core::run_wc_address_import_batch( $offset );

        wp_send_json_success( [ 'message' => 'Import batch processed.' ] );
    }
}
