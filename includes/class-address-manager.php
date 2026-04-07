<?php
defined( 'ABSPATH' ) || exit;

/**
 * CRUD operations for the user address book.
 * Each user can have multiple shipping addresses; one is marked as default.
 */
class Auth_Popup_Address_Manager {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'auth_popup_user_addresses';
    }

    /* ── Read ───────────────────────────────────────────────────────── */

    public static function get_addresses( int $user_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY is_default DESC, id ASC',
                $user_id
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function get_address( int $user_id, int $address_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE id = %d AND user_id = %d',
                $address_id,
                $user_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_default( int $user_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND is_default = 1 LIMIT 1',
                $user_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /* ── Write ──────────────────────────────────────────────────────── */

    /**
     * Create or update an address.
     *
     * @param int   $user_id
     * @param array $data        Address field values.
     * @param int   $address_id  0 = insert, >0 = update existing row.
     * @return int|\WP_Error     Row ID on success.
     */
    public static function save( int $user_id, array $data, int $address_id = 0 ): int|\WP_Error {
        global $wpdb;

        $fields = self::sanitize_fields( $data );

        $missing = self::validate_required( $fields );
        if ( $missing ) {
            return new \WP_Error(
                'invalid_address',
                /* translators: %s: comma-separated field names */
                sprintf( __( 'Required fields missing: %s', 'auth-popup' ), implode( ', ', $missing ) )
            );
        }

        if ( ! empty( $fields['phone'] ) && ! self::validate_phone( $fields['phone'] ) ) {
            return new \WP_Error(
                'invalid_phone',
                __( 'Please enter a valid Bangladeshi mobile number (e.g. 01712345678).', 'auth-popup' )
            );
        }

        if ( ! empty( $fields['state'] ) && ! in_array( $fields['state'], self::valid_bd_states(), true ) ) {
            return new \WP_Error(
                'invalid_state',
                __( 'Please select a valid district.', 'auth-popup' )
            );
        }

        if ( $address_id > 0 ) {
            // Update — verify ownership first
            if ( ! self::get_address( $user_id, $address_id ) ) {
                return new \WP_Error( 'not_found', __( 'Address not found.', 'auth-popup' ) );
            }

            $wpdb->update(
                self::table(),
                $fields,
                [ 'id' => $address_id, 'user_id' => $user_id ],
                self::field_formats( $fields ),
                [ '%d', '%d' ]
            );

            return $address_id;
        }

        // Insert — auto-default if this is the user's first address
        $count = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE user_id = %d', $user_id )
        );

        $row = array_merge( [ 'user_id' => $user_id, 'is_default' => (int) ( $count === 0 ) ], $fields );

        $wpdb->insert(
            self::table(),
            $row,
            array_merge( [ '%d', '%d' ], self::field_formats( $fields ) )
        );

        return (int) $wpdb->insert_id;
    }

    public static function delete( int $user_id, int $address_id ): bool {
        global $wpdb;

        $existing = self::get_address( $user_id, $address_id );
        if ( ! $existing ) {
            return false;
        }

        $deleted = (bool) $wpdb->delete(
            self::table(),
            [ 'id' => $address_id, 'user_id' => $user_id ],
            [ '%d', '%d' ]
        );

        // Promote next address to default when the deleted one was default
        if ( $deleted && $existing['is_default'] ) {
            $next_id = $wpdb->get_var(
                $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE user_id = %d ORDER BY id ASC LIMIT 1', $user_id )
            );
            if ( $next_id ) {
                $wpdb->update(
                    self::table(),
                    [ 'is_default' => 1 ],
                    [ 'id' => (int) $next_id, 'user_id' => $user_id ],
                    [ '%d' ],
                    [ '%d', '%d' ]
                );
            }
        }

        return $deleted;
    }

    public static function set_default( int $user_id, int $address_id ): bool {
        global $wpdb;

        if ( ! self::get_address( $user_id, $address_id ) ) {
            return false;
        }

        // Clear all defaults for this user, then set the new one
        $wpdb->update( self::table(), [ 'is_default' => 0 ], [ 'user_id' => $user_id ], [ '%d' ], [ '%d' ] );
        $wpdb->update(
            self::table(),
            [ 'is_default' => 1 ],
            [ 'id' => $address_id, 'user_id' => $user_id ],
            [ '%d' ],
            [ '%d', '%d' ]
        );

        return true;
    }

    /* ── Helpers ────────────────────────────────────────────────────── */

    private static function sanitize_fields( array $data ): array {
        return [
            'label'      => sanitize_text_field( $data['label']      ?? '' ),
            'first_name' => sanitize_text_field( $data['first_name'] ?? '' ),
            'last_name'  => sanitize_text_field( $data['last_name']  ?? '' ),
            'company'    => sanitize_text_field( $data['company']    ?? '' ),
            'address_1'  => sanitize_text_field( $data['address_1']  ?? '' ),
            'address_2'  => sanitize_text_field( $data['address_2']  ?? '' ),
            'city'       => sanitize_text_field( $data['city']       ?? '' ),
            'state'      => sanitize_text_field( $data['state']      ?? '' ),
            'postcode'   => sanitize_text_field( $data['postcode']   ?? '' ),
            'country'    => sanitize_text_field( $data['country']    ?? 'BD' ),
            'phone'      => sanitize_text_field( $data['phone']      ?? '' ),
        ];
    }

    private static function validate_required( array $fields ): array {
        $required = [ 'first_name', 'phone', 'address_1', 'state' ];
        $missing  = [];
        foreach ( $required as $key ) {
            if ( empty( $fields[ $key ] ) ) {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    /**
     * Validate the phone field specifically.
     * Reuses the same regex as Auth_Popup_SMS_Service::is_valid_phone().
     */
    private static function validate_phone( string $phone ): bool {
        $clean = preg_replace( '/\D/', '', $phone );
        return (bool) preg_match( '/^(880|00880)?0?1[3-9]\d{8}$/', $clean );
    }

    /**
     * Valid WooCommerce state codes for Bangladesh (i18n/states.php).
     */
    private static function valid_bd_states(): array {
        return [
            'BD-01','BD-02','BD-03','BD-04','BD-05','BD-06','BD-07','BD-08',
            'BD-09','BD-10','BD-11','BD-12','BD-13','BD-14','BD-15','BD-16',
            'BD-17','BD-18','BD-19','BD-20','BD-21','BD-22','BD-23','BD-24',
            'BD-25','BD-26','BD-27','BD-28','BD-29','BD-30','BD-31','BD-32',
            'BD-33','BD-34','BD-35','BD-36','BD-37','BD-38','BD-39','BD-40',
            'BD-41','BD-42','BD-43','BD-44','BD-45','BD-46','BD-47','BD-48',
            'BD-49','BD-50','BD-51','BD-52','BD-53','BD-54','BD-55','BD-56',
            'BD-57','BD-58','BD-59','BD-60','BD-61','BD-62','BD-63','BD-64',
        ];
    }

    /** Return %s for every field (all address fields are strings). */
    private static function field_formats( array $fields ): array {
        return array_fill( 0, count( $fields ), '%s' );
    }

    /* ── WooCommerce sync ───────────────────────────────────────────── */

    /**
     * Write an address into WooCommerce's usermeta fields so the
     * /my-account/edit-address/ page and order records stay in sync.
     * Only call this for the user's default address.
     */
    public static function sync_to_wc( int $user_id, array $address ): void {
        $map = [
            'first_name' => [ 'billing_first_name', 'shipping_first_name' ],
            'last_name'  => [ 'billing_last_name',  'shipping_last_name'  ],
            'phone'      => [ 'billing_phone' ],
            'address_1'  => [ 'billing_address_1',  'shipping_address_1'  ],
            'address_2'  => [ 'billing_address_2',  'shipping_address_2'  ],
            'city'       => [ 'billing_city',        'shipping_city'       ],
            'state'      => [ 'billing_state',       'shipping_state'      ],
            'postcode'   => [ 'billing_postcode',    'shipping_postcode'   ],
            'country'    => [ 'billing_country',     'shipping_country'    ],
        ];

        foreach ( $map as $our_field => $wc_keys ) {
            if ( ! isset( $address[ $our_field ] ) ) {
                continue;
            }
            foreach ( $wc_keys as $wc_key ) {
                update_user_meta( $user_id, $wc_key, $address[ $our_field ] );
            }
        }

        // Keep WC customer object cache fresh
        if ( function_exists( 'wc_get_customer' ) ) {
            $customer = new \WC_Customer( $user_id );
            $customer->save();
        }
    }

    /* ── Import from WooCommerce usermeta ───────────────────────────── */

    /**
     * Import a single user's WC billing address into the address table.
     * Skips users who already have at least one saved address.
     * Returns true if an address was inserted.
     */
    public static function import_from_wc( int $user_id ): bool {
        global $wpdb;

        // Skip if already has addresses in our table
        $existing = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE user_id = %d', $user_id )
        );
        if ( $existing > 0 ) {
            return false;
        }

        $first_name = (string) get_user_meta( $user_id, 'billing_first_name', true );
        $address_1  = (string) get_user_meta( $user_id, 'billing_address_1',  true );

        // Only import if minimum required fields exist
        if ( empty( $first_name ) || empty( $address_1 ) ) {
            return false;
        }

        $phone   = (string) get_user_meta( $user_id, 'billing_phone',    true );
        $state   = (string) get_user_meta( $user_id, 'billing_state',    true );
        $country = (string) get_user_meta( $user_id, 'billing_country',  true );

        $wpdb->insert(
            self::table(),
            [
                'user_id'    => $user_id,
                'label'      => 'Home',
                'first_name' => $first_name,
                'last_name'  => (string) get_user_meta( $user_id, 'billing_last_name',  true ),
                'phone'      => $phone,
                'address_1'  => $address_1,
                'address_2'  => (string) get_user_meta( $user_id, 'billing_address_2',  true ),
                'city'       => (string) get_user_meta( $user_id, 'billing_city',       true ),
                'state'      => $state,
                'postcode'   => (string) get_user_meta( $user_id, 'billing_postcode',   true ),
                'country'    => $country ?: 'BD',
                'is_default' => 1,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
        );

        return (bool) $wpdb->insert_id;
    }
}
