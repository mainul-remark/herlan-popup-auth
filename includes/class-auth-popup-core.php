<?php
defined( 'ABSPATH' ) || exit;

class Auth_Popup_Core {

    private static ?Auth_Popup_Core $instance = null;

    /** Batch size for the billing_phone migration job */
    private const MIGRATION_BATCH = 200;

    public static function get_instance(): Auth_Popup_Core {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function run(): void {
        $this->load_textdomain();

        // Upgrade DB tables if needed (handles already-active installs)
        if ( get_option( 'auth_popup_db_version' ) !== '1.2' ) {
            self::create_tables();
        }

        // Register the migration batch hooks (needed for both Action Scheduler & WP-Cron)
        add_action( 'auth_popup_migrate_user_data_batch', [ __CLASS__, 'run_user_data_migration_batch' ] );
        add_action( 'auth_popup_import_wc_addresses',     [ __CLASS__, 'run_wc_address_import_batch'  ] );
        // Keep legacy hooks registered so any still-queued batches from old installs can finish
        add_action( 'auth_popup_migrate_phones_batch',    [ __CLASS__, 'run_migration_batch'           ] );
        add_action( 'auth_popup_migrate_emails_batch',    [ __CLASS__, 'run_email_migration_batch'     ] );

        // Kick off WC address import if not yet done
        if ( ! get_option( 'auth_popup_wc_address_import_done' ) ) {
            self::schedule_wc_address_import( 0 );
        }

        // Kick off combined user-data migration if not yet done.
        // If both legacy migrations are already complete (existing installs), mark it done immediately.
        if ( ! get_option( 'auth_popup_user_data_migration_done' ) ) {
            if ( get_option( 'auth_popup_phone_migration_done' ) && get_option( 'auth_popup_email_migration_done' ) ) {
                update_option( 'auth_popup_user_data_migration_done', '1' );
            } else {
                self::schedule_user_data_migration( 0 );
            }
        }

        // Daily cleanup of old OTP log entries
        add_action( 'auth_popup_cleanup_otp_logs', [ 'Auth_Popup_OTP_Manager', 'cleanup_old_logs' ] );
        if ( ! wp_next_scheduled( 'auth_popup_cleanup_otp_logs' ) ) {
            wp_schedule_event( time(), 'daily', 'auth_popup_cleanup_otp_logs' );
        }

        // Ensure every active install has a REST API key
        $settings = get_option( 'auth_popup_settings', [] );
        if ( empty( $settings['rest_api_key'] ) ) {
            $settings['rest_api_key'] = bin2hex( random_bytes( 16 ) );
            update_option( 'auth_popup_settings', $settings );
        }

        // Boot modules
        Auth_Popup_Ajax_Handler::init();
        Auth_Popup_REST_API::init();
        Auth_Popup_Admin_Settings::init();
        Auth_Popup_Public_Frontend::init();
    }

    private function load_textdomain(): void {
        load_plugin_textdomain(
            'auth-popup',
            false,
            dirname( AUTH_POPUP_BASENAME ) . '/languages'
        );
    }

    /* ── Activation / Deactivation ─────────────────────────────────── */

    public static function activate(): void {
        self::create_tables();

        // Schedule daily OTP log cleanup
        if ( ! wp_next_scheduled( 'auth_popup_cleanup_otp_logs' ) ) {
            wp_schedule_event( time(), 'daily', 'auth_popup_cleanup_otp_logs' );
        }

        // Set default options if not present
        if ( ! get_option( 'auth_popup_settings' ) ) {
            update_option( 'auth_popup_settings', self::default_settings() );
        }

        // Ensure every install has a REST API key
        $settings = get_option( 'auth_popup_settings', [] );
        if ( empty( $settings['rest_api_key'] ) ) {
            $settings['rest_api_key'] = bin2hex( random_bytes( 16 ) );
            update_option( 'auth_popup_settings', $settings );
        }

        // Schedule combined mobile + email migration
        delete_option( 'auth_popup_user_data_migration_done' );
        self::schedule_user_data_migration( 0 );

        // Schedule import of existing WC addresses into the address book
        delete_option( 'auth_popup_wc_address_import_done' );
        self::schedule_wc_address_import( 0 );

        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'auth_popup_cleanup_otp_logs' );
        flush_rewrite_rules();
    }

    /* ── DB Table Creation ─────────────────────────────────────────── */

    /**
     * Create / upgrade plugin tables. Safe to call on every load via db version check.
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // OTP rate-limit log
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}auth_popup_otp_log (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            phone       VARCHAR(20)     NOT NULL,
            ip_address  VARCHAR(45)     NOT NULL DEFAULT '',
            sent_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY phone   (phone),
            KEY sent_at (sent_at)
        ) {$charset};" );

        // User profiles — fast indexed lookups by phone / OAuth ID
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}auth_popup_user_profiles (
            user_id          BIGINT(20) UNSIGNED NOT NULL,
            phone            VARCHAR(20)         DEFAULT NULL,
            google_id        VARCHAR(100)        DEFAULT NULL,
            facebook_id      VARCHAR(100)        DEFAULT NULL,
            google_avatar    VARCHAR(500)        DEFAULT NULL,
            facebook_avatar  VARCHAR(500)        DEFAULT NULL,
            PRIMARY KEY      (user_id),
            UNIQUE KEY phone       (phone),
            KEY        google_id   (google_id),
            KEY        facebook_id (facebook_id)
        ) {$charset};" );

        // User address book — multiple shipping addresses per user
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}auth_popup_user_addresses (
            id          BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
            user_id     BIGINT(20) UNSIGNED NOT NULL,
            label       VARCHAR(100)        NOT NULL DEFAULT '',
            first_name  VARCHAR(100)        NOT NULL DEFAULT '',
            last_name   VARCHAR(100)        NOT NULL DEFAULT '',
            company     VARCHAR(200)        NOT NULL DEFAULT '',
            address_1   VARCHAR(200)        NOT NULL DEFAULT '',
            address_2   VARCHAR(200)        NOT NULL DEFAULT '',
            city        VARCHAR(100)        NOT NULL DEFAULT '',
            state       VARCHAR(100)        NOT NULL DEFAULT '',
            postcode    VARCHAR(20)         NOT NULL DEFAULT '',
            country     VARCHAR(2)          NOT NULL DEFAULT 'BD',
            phone       VARCHAR(20)         NOT NULL DEFAULT '',
            is_default  TINYINT(1)          NOT NULL DEFAULT 0,
            created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id    (user_id),
            KEY user_default (user_id, is_default)
        ) {$charset};" );

        update_option( 'auth_popup_db_version', '1.2' );
    }

    /* ── Combined Mobile + Email Migration ────────────────────────── */

    private static function schedule_user_data_migration( int $offset ): void {
        if ( get_option( 'auth_popup_user_data_migration_done' ) ) {
            return;
        }

        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action(
                time() + 5,
                'auth_popup_migrate_user_data_batch',
                [ $offset ],
                'auth-popup'
            );
        } else {
            wp_schedule_single_event(
                time() + 10,
                'auth_popup_migrate_user_data_batch',
                [ $offset ]
            );
        }
    }

    /**
     * Process one batch: for each user migrate billing_phone → profiles table
     * AND billing_email → wp_users.user_email (only when user_email is empty).
     * Re-schedules itself until no more users remain.
     */
    public static function run_user_data_migration_batch( int $offset = 0 ): void {
        global $wpdb;

        $user_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT user_id
             FROM {$wpdb->usermeta}
             WHERE meta_key IN ('billing_phone', 'billing_email')
               AND meta_value != ''
             ORDER BY user_id ASC
             LIMIT %d OFFSET %d",
            self::MIGRATION_BATCH,
            $offset
        ) );

        if ( empty( $user_ids ) ) {
            update_option( 'auth_popup_user_data_migration_done', '1' );
            return;
        }

        $profiles_table = $wpdb->prefix . 'auth_popup_user_profiles';

        foreach ( $user_ids as $uid ) {
            $uid = (int) $uid;

            // Phone: billing_phone → profiles table (do not overwrite existing)
            $raw_phone = (string) get_user_meta( $uid, 'billing_phone', true );
            if ( $raw_phone ) {
                $normalized = Auth_Popup_SMS_Service::normalise_phone( $raw_phone );
                if ( strlen( $normalized ) >= 11 ) {
                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO {$profiles_table} (user_id, phone)
                         VALUES (%d, %s)
                         ON DUPLICATE KEY UPDATE
                             phone = IF(phone IS NULL OR phone = '', VALUES(phone), phone)",
                        $uid,
                        $normalized
                    ) );
                }
            }

            // Email: billing_email → wp_users.user_email (only if currently empty)
            $billing_email = (string) get_user_meta( $uid, 'billing_email', true );
            if ( $billing_email ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->users}
                     SET user_email = %s
                     WHERE ID = %d
                       AND ( user_email = '' OR user_email IS NULL )",
                    $billing_email,
                    $uid
                ) );
            }
        }

        self::schedule_user_data_migration( $offset + self::MIGRATION_BATCH );
    }

    /**
     * Return combined migration progress for admin display.
     */
    public static function user_data_migration_status(): array {
        global $wpdb;

        $done = (bool) get_option( 'auth_popup_user_data_migration_done' );

        // Phone counts
        $phone_total    = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta}
             WHERE meta_key = 'billing_phone' AND meta_value != ''"
        );
        $phone_migrated = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}auth_popup_user_profiles
             WHERE phone IS NOT NULL AND phone != ''"
        );

        // Email counts
        $email_total     = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta}
             WHERE meta_key = 'billing_email' AND meta_value != ''"
        );
        $email_remaining = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->usermeta} um
             INNER JOIN {$wpdb->users} u ON u.ID = um.user_id
             WHERE um.meta_key   = 'billing_email'
               AND um.meta_value != ''
               AND ( u.user_email = '' OR u.user_email IS NULL )"
        );
        $email_synced = max( 0, $email_total - $email_remaining );

        // Combined totals
        $grand_total = $phone_total + $email_total;
        $grand_done  = $phone_migrated + $email_synced;
        $percent     = $grand_total > 0
            ? min( 100, (int) round( ( $grand_done / $grand_total ) * 100 ) )
            : 100;

        // Next scheduled run — check new hook first, then legacy hooks
        $next_run = null;
        $hooks    = [ 'auth_popup_migrate_user_data_batch', 'auth_popup_migrate_phones_batch', 'auth_popup_migrate_emails_batch' ];
        if ( function_exists( 'as_get_scheduled_actions' ) ) {
            foreach ( $hooks as $hook ) {
                $pending = as_get_scheduled_actions( [
                    'hook'     => $hook,
                    'status'   => \ActionScheduler_Store::STATUS_PENDING,
                    'per_page' => 1,
                ] );
                if ( ! empty( $pending ) ) {
                    $action   = reset( $pending );
                    $next_run = $action->get_schedule()->get_date()->format( 'Y-m-d H:i:s' );
                    break;
                }
            }
        } else {
            foreach ( $hooks as $hook ) {
                if ( $next = wp_next_scheduled( $hook ) ) {
                    $next_run = date( 'Y-m-d H:i:s', $next );
                    break;
                }
            }
        }

        return compact(
            'done', 'phone_total', 'phone_migrated',
            'email_total', 'email_synced', 'email_remaining',
            'grand_total', 'grand_done', 'percent', 'next_run'
        );
    }

    /* ── Background Phone Migration (legacy — kept for queued batches) ── */

    /**
     * Schedule the next migration batch using Action Scheduler (preferred,
     * bundled with WooCommerce) or WP-Cron as fallback.
     */
    private static function schedule_migration_batch( int $offset ): void {
        if ( get_option( 'auth_popup_phone_migration_done' ) ) {
            return;
        }

        if ( function_exists( 'as_schedule_single_action' ) ) {
            // Action Scheduler — runs reliably in background, not tied to page loads
            as_schedule_single_action(
                time() + 5,
                'auth_popup_migrate_phones_batch',
                [ $offset ],
                'auth-popup'
            );
        } else {
            // WP-Cron fallback
            wp_schedule_single_event(
                time() + 10,
                'auth_popup_migrate_phones_batch',
                [ $offset ]
            );
        }
    }

    /**
     * Process one batch of billing_phone → profiles table migration.
     * Called by Action Scheduler or WP-Cron. Re-schedules itself until done.
     */
    public static function run_migration_batch( int $offset = 0 ): void {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, meta_value
             FROM {$wpdb->usermeta}
             WHERE meta_key = 'billing_phone'
               AND meta_value != ''
             LIMIT %d OFFSET %d",
            self::MIGRATION_BATCH,
            $offset
        ) );

        // No more rows — migration complete
        if ( empty( $rows ) ) {
            update_option( 'auth_popup_phone_migration_done', '1' );
            return;
        }

        $profiles_table = $wpdb->prefix . 'auth_popup_user_profiles';

        foreach ( $rows as $row ) {
            $normalized = Auth_Popup_SMS_Service::normalise_phone( $row->meta_value );

            // Skip if normalisation produced something unusable
            if ( strlen( $normalized ) < 11 ) {
                continue;
            }

            // Insert only — do not overwrite a phone already set by the plugin
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$profiles_table} (user_id, phone)
                 VALUES (%d, %s)
                 ON DUPLICATE KEY UPDATE
                     phone = IF(phone IS NULL OR phone = '', VALUES(phone), phone)",
                (int) $row->user_id,
                $normalized
            ) );
        }

        // Schedule the next batch
        self::schedule_migration_batch( $offset + self::MIGRATION_BATCH );
    }

    /* ── WC Address Import Migration ──────────────────────────────── */

    /**
     * Schedule one batch of the WC address import using Action Scheduler
     * (bundled with WooCommerce) or WP-Cron as fallback.
     */
    public static function schedule_wc_address_import( int $offset ): void {
        if ( get_option( 'auth_popup_wc_address_import_done' ) ) {
            return;
        }

        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action(
                time() + 5,
                'auth_popup_import_wc_addresses',
                [ $offset ],
                'auth-popup'
            );
        } else {
            wp_schedule_single_event(
                time() + 10,
                'auth_popup_import_wc_addresses',
                [ $offset ]
            );
        }
    }

    /**
     * Process one batch: find users with WC billing addresses and import
     * them into wp_auth_popup_user_addresses (skips users who already
     * have entries in that table).
     */
    public static function run_wc_address_import_batch( int $offset = 0 ): void {
        global $wpdb;

        $user_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT user_id
             FROM {$wpdb->usermeta}
             WHERE meta_key = 'billing_first_name'
               AND meta_value != ''
             ORDER BY user_id ASC
             LIMIT %d OFFSET %d",
            self::MIGRATION_BATCH,
            $offset
        ) );

        // No more users — import complete
        if ( empty( $user_ids ) ) {
            update_option( 'auth_popup_wc_address_import_done', '1' );
            return;
        }

        foreach ( $user_ids as $user_id ) {
            Auth_Popup_Address_Manager::import_from_wc( (int) $user_id );
        }

        // Schedule the next batch
        self::schedule_wc_address_import( $offset + self::MIGRATION_BATCH );
    }

    /**
     * Return the current import progress for admin display.
     */
    public static function wc_address_import_status(): array {
        global $wpdb;

        $done  = (bool) get_option( 'auth_popup_wc_address_import_done' );
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta}
             WHERE meta_key = 'billing_first_name' AND meta_value != ''"
        );
        $imported = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}auth_popup_user_addresses"
        );

        return compact( 'done', 'total', 'imported' );
    }

    /* ── Background Email Migration ───────────────────────────────── */

    /**
     * Schedule the next email migration batch.
     * No offset needed — each run queries the first N users still missing an email.
     */
    private static function schedule_email_migration(): void {
        if ( get_option( 'auth_popup_email_migration_done' ) ) {
            return;
        }

        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action(
                time() + 5,
                'auth_popup_migrate_emails_batch',
                [],
                'auth-popup'
            );
        } else {
            wp_schedule_single_event(
                time() + 10,
                'auth_popup_migrate_emails_batch'
            );
        }
    }

    /**
     * Process one batch: copy billing_email → wp_users.user_email for users
     * whose account email is currently empty. Uses a single UPDATE…JOIN for
     * performance. Re-schedules itself until no rows remain.
     */
    public static function run_email_migration_batch(): void {
        global $wpdb;

        // Update up to MIGRATION_BATCH users in one query
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID
             SET u.user_email = um.meta_value
             WHERE um.meta_key   = 'billing_email'
               AND um.meta_value != ''
               AND ( u.user_email = '' OR u.user_email IS NULL )
             LIMIT %d",
            self::MIGRATION_BATCH
        ) );

        // Check if any users still need their email set
        $remaining = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->usermeta} um
             INNER JOIN {$wpdb->users} u ON u.ID = um.user_id
             WHERE um.meta_key   = 'billing_email'
               AND um.meta_value != ''
               AND ( u.user_email = '' OR u.user_email IS NULL )"
        );

        if ( $remaining === 0 ) {
            update_option( 'auth_popup_email_migration_done', '1' );
            return;
        }

        // More rows remaining — schedule next batch
        self::schedule_email_migration();
    }

    /**
     * Return current email migration progress for admin display.
     */
    public static function email_migration_status(): array {
        global $wpdb;

        $done = (bool) get_option( 'auth_popup_email_migration_done' );

        // Total users that have a billing_email value
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id)
             FROM {$wpdb->usermeta}
             WHERE meta_key = 'billing_email' AND meta_value != ''"
        );

        // Remaining: have billing_email but still missing user_email
        $remaining = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->usermeta} um
             INNER JOIN {$wpdb->users} u ON u.ID = um.user_id
             WHERE um.meta_key   = 'billing_email'
               AND um.meta_value != ''
               AND ( u.user_email = '' OR u.user_email IS NULL )"
        );

        $synced  = max( 0, $total - $remaining );
        $percent = $total > 0 ? min( 100, (int) round( ( $synced / $total ) * 100 ) ) : 100;

        // Next scheduled batch
        $next_run = null;
        if ( function_exists( 'as_get_scheduled_actions' ) ) {
            $pending = as_get_scheduled_actions( [
                'hook'     => 'auth_popup_migrate_emails_batch',
                'status'   => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 1,
            ] );
            if ( ! empty( $pending ) ) {
                $action   = reset( $pending );
                $next_run = $action->get_schedule()->get_date()->format( 'Y-m-d H:i:s' );
            }
        } elseif ( $next = wp_next_scheduled( 'auth_popup_migrate_emails_batch' ) ) {
            $next_run = date( 'Y-m-d H:i:s', $next );
        }

        return compact( 'done', 'total', 'synced', 'remaining', 'percent', 'next_run' );
    }

    /* ── Settings ──────────────────────────────────────────────────── */

    public static function default_settings(): array {
        return [
            // SMS
            'sms_api_token'         => '',
            'sms_sender_id'         => '',
            'sms_base_url'          => 'https://se.smsplus.net/api/v1',
            // Google
            'google_client_id'      => '',
            'google_client_secret'  => '',
            // Facebook
            'fb_app_id'             => '',
            'fb_app_secret'         => '',
            // Loyalty
            'loyalty_enabled'       => '1',
            'loyalty_api_url'       => '',
            // General
            'redirect_url'          => home_url(),
            'trigger_selector'      => '.auth-popup-trigger',
            'otp_expiry_minutes'      => 5,
            'otp_max_per_hour'        => 5,
            'otp_max_per_hour_ip'     => 10,
            'otp_max_verify_attempts' => 5,
            'enable_password_login' => '1',
            'enable_otp_login'      => '1',
            'enable_google'         => '1',
            'enable_facebook'       => '1',
            'popup_logo_url'        => '',
            'popup_brand_name'      => get_bloginfo( 'name' ),
            // Checkout
            'checkout_hide_shipping_form'       => '1',
            'checkout_disable_ship_to_different'=> '1',
            // My Account inline form
            'myaccount_inline_form'             => '1',
            // REST API
            'rest_api_key'                      => '',
        ];
    }

    /**
     * Retrieve a specific setting value.
     */
    public static function get_setting( string $key, $default = null ) {
        $settings = get_option( 'auth_popup_settings', self::default_settings() );
        return $settings[ $key ] ?? $default;
    }
}
