<?php
defined( 'ABSPATH' ) || exit;

class Auth_Popup_Admin_Settings {

    public static function init(): void {
        add_action( 'admin_menu',             [ __CLASS__, 'add_menu'           ] );
        add_action( 'admin_init',             [ __CLASS__, 'register_settings'  ] );
        add_action( 'admin_enqueue_scripts',  [ __CLASS__, 'enqueue_assets'     ] );
        add_filter( 'plugin_action_links_' . AUTH_POPUP_BASENAME, [ __CLASS__, 'plugin_action_links' ] );
        add_filter( 'manage_users_columns', [ __CLASS__, 'add_users_phone_column' ] );
        add_filter( 'manage_users_custom_column', [ __CLASS__, 'render_users_phone_column' ], 10, 3 );
        add_action( 'pre_user_query', [ __CLASS__, 'include_phone_in_users_search' ] );

        // Combined mobile + email migration AJAX
        add_action( 'wp_ajax_auth_popup_user_data_mig_status', [ __CLASS__, 'ajax_user_data_migration_status' ] );
        add_action( 'wp_ajax_auth_popup_run_user_data_mig',    [ __CLASS__, 'ajax_run_user_data_migration'    ] );

        // WC address import AJAX
        add_action( 'wp_ajax_auth_popup_wc_import_status', [ __CLASS__, 'ajax_wc_import_status' ] );
        add_action( 'wp_ajax_auth_popup_run_wc_import',    [ __CLASS__, 'ajax_run_wc_import'    ] );
    }

    public static function add_users_phone_column( array $columns ): array {
        $new_columns = [];

        foreach ( $columns as $key => $label ) {
            $new_columns[ $key ] = $label;

            if ( 'email' === $key ) {
                $new_columns['auth_popup_phone'] = __( 'Phone', 'auth-popup' );
            }
        }

        if ( ! isset( $new_columns['auth_popup_phone'] ) ) {
            $new_columns['auth_popup_phone'] = __( 'Phone', 'auth-popup' );
        }

        return $new_columns;
    }

    public static function render_users_phone_column( string $output, string $column_name, int $user_id ): string {
        if ( 'auth_popup_phone' !== $column_name ) {
            return $output;
        }

        $phone = Auth_Popup_User_Auth::get_user_phone( $user_id );

        return $phone ? esc_html( $phone ) : '&mdash;';
    }

    public static function include_phone_in_users_search( WP_User_Query $query ): void {
        global $wpdb, $pagenow;

        if ( ! is_admin() || 'users.php' !== $pagenow ) {
            return;
        }

        $search = trim( (string) $query->get( 'search' ) );
        if ( '' === $search ) {
            return;
        }

        $term = trim( $search, '*' );
        if ( '' === $term ) {
            return;
        }

        $like         = '%' . $wpdb->esc_like( $term ) . '%';
        $digits       = preg_replace( '/\D+/', '', $term );
        $profile_join = " LEFT JOIN {$wpdb->prefix}auth_popup_user_profiles ap_phone_profile ON ap_phone_profile.user_id = {$wpdb->users}.ID";
        $meta_join    = " LEFT JOIN {$wpdb->usermeta} ap_billing_phone ON ap_billing_phone.user_id = {$wpdb->users}.ID AND ap_billing_phone.meta_key = 'billing_phone'";

        if ( false === strpos( $query->query_from, 'ap_phone_profile' ) ) {
            $query->query_from .= $profile_join;
        }

        if ( false === strpos( $query->query_from, 'ap_billing_phone' ) ) {
            $query->query_from .= $meta_join;
        }

        $phone_where = $wpdb->prepare(
            ' OR ap_phone_profile.phone LIKE %s OR ap_billing_phone.meta_value LIKE %s',
            $like,
            $like
        );

        if ( '' !== $digits && $digits !== $term ) {
            $digit_like  = '%' . $wpdb->esc_like( $digits ) . '%';
            $phone_where .= $wpdb->prepare(
                ' OR REPLACE(REPLACE(REPLACE(REPLACE(ap_phone_profile.phone, "+", ""), " ", ""), "-", ""), ".", "") LIKE %s
                  OR REPLACE(REPLACE(REPLACE(REPLACE(ap_billing_phone.meta_value, "+", ""), " ", ""), "-", ""), ".", "") LIKE %s',
                $digit_like,
                $digit_like
            );
        }

        $query->query_where = preg_replace(
            '/\)\s*$/',
            $phone_where . ' )',
            $query->query_where,
            1
        );

        $query->query_fields = 'DISTINCT ' . preg_replace( '/^DISTINCT\s+/i', '', $query->query_fields );
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
            'myaccount_inline_form',
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
                case 'otp_max_per_hour_ip':
                case 'otp_max_verify_attempts':
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

    /* ── Combined Mobile + Email Migration AJAX ───────────────────── */

    public static function ajax_user_data_migration_status(): void {
        check_ajax_referer( 'auth_popup_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        wp_send_json_success( Auth_Popup_Core::user_data_migration_status() );
    }

    public static function ajax_run_user_data_migration(): void {
        check_ajax_referer( 'auth_popup_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        if ( ! empty( $_POST['restart'] ) ) {
            delete_option( 'auth_popup_user_data_migration_done' );
        }

        // Run one batch immediately starting at offset 0 (idempotent — SQL guards prevent duplicates)
        Auth_Popup_Core::run_user_data_migration_batch( 0 );

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
