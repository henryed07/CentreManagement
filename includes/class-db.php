<?php
defined( 'ABSPATH' ) || exit;

class RHCM_DB {

    // ── Activation: create tables ─────────────────────────────────────────────

    public static function install() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();

        // dbDelta rules: no IF NOT EXISTS, two spaces before PRIMARY KEY, no ENUM.
        $courses = "CREATE TABLE {$wpdb->prefix}rhcm_courses (
id INT UNSIGNED NOT NULL AUTO_INCREMENT,
title VARCHAR(200) NOT NULL,
description TEXT,
category VARCHAR(80) NOT NULL DEFAULT '',
icon VARCHAR(30) NOT NULL DEFAULT '',
image_url VARCHAR(500) NOT NULL DEFAULT '',
price DECIMAL(8,2) NOT NULL DEFAULT 0,
duration VARCHAR(80) NOT NULL DEFAULT '',
level VARCHAR(80) NOT NULL DEFAULT '',
rya_cert VARCHAR(80) NOT NULL DEFAULT '',
max_participants INT UNSIGNED NOT NULL DEFAULT 12,
is_active TINYINT(1) NOT NULL DEFAULT 1,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY  (id)
) $c;";

        $sessions = "CREATE TABLE {$wpdb->prefix}rhcm_sessions (
id INT UNSIGNED NOT NULL AUTO_INCREMENT,
course_id INT UNSIGNED NOT NULL,
start_date DATE NOT NULL,
end_date DATE NOT NULL,
spaces INT UNSIGNED,
notes TEXT,
is_active TINYINT(1) NOT NULL DEFAULT 1,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
KEY course_id (course_id),
KEY start_date (start_date)
) $c;";

        $bookings = "CREATE TABLE {$wpdb->prefix}rhcm_bookings (
id INT UNSIGNED NOT NULL AUTO_INCREMENT,
booking_ref VARCHAR(20) NOT NULL,
session_id INT UNSIGNED NOT NULL,
course_id INT UNSIGNED NOT NULL,
first_name VARCHAR(100) NOT NULL,
last_name VARCHAR(100) NOT NULL,
email VARCHAR(200) NOT NULL,
phone VARCHAR(30) NOT NULL DEFAULT '',
dob DATE,
emg_name VARCHAR(200) NOT NULL DEFAULT '',
emg_phone VARCHAR(30) NOT NULL DEFAULT '',
notes TEXT,
discount_code VARCHAR(50) NOT NULL DEFAULT '',
discount_amount DECIMAL(8,2) NOT NULL DEFAULT 0,
status VARCHAR(20) NOT NULL DEFAULT 'confirmed',
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
UNIQUE KEY booking_ref (booking_ref),
KEY session_id (session_id),
KEY email (email)
) $c;";

        $memberships = "CREATE TABLE {$wpdb->prefix}rhcm_memberships (
id INT UNSIGNED NOT NULL AUTO_INCREMENT,
name VARCHAR(200) NOT NULL,
icon VARCHAR(30) NOT NULL DEFAULT '',
tagline VARCHAR(500) NOT NULL DEFAULT '',
price VARCHAR(50) NOT NULL DEFAULT '',
frequency VARCHAR(80) NOT NULL DEFAULT '',
details TEXT,
is_popular TINYINT(1) NOT NULL DEFAULT 0,
info_url VARCHAR(500) NOT NULL DEFAULT '',
sort_order INT NOT NULL DEFAULT 0,
is_active TINYINT(1) NOT NULL DEFAULT 1,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY  (id)
) $c;";

        $discounts = "CREATE TABLE {$wpdb->prefix}rhcm_discounts (
id INT UNSIGNED NOT NULL AUTO_INCREMENT,
code VARCHAR(50) NOT NULL,
description VARCHAR(200) NOT NULL DEFAULT '',
type VARCHAR(20) NOT NULL DEFAULT 'percent',
amount DECIMAL(8,2) NOT NULL DEFAULT 0,
course_ids TEXT,
min_order DECIMAL(8,2) NOT NULL DEFAULT 0,
uses_limit INT,
uses_count INT NOT NULL DEFAULT 0,
expires_at DATE,
is_active TINYINT(1) NOT NULL DEFAULT 1,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
UNIQUE KEY code (code)
) $c;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $courses );
        dbDelta( $sessions );
        dbDelta( $bookings );
        dbDelta( $memberships );
        dbDelta( $discounts );
        update_option( 'rhcm_db_version', RHCM_VERSION );
    }

    // ── Courses ───────────────────────────────────────────────────────────────

    public static function get_courses( array $args = [] ) {
        global $wpdb;
        $t   = $wpdb->prefix . 'rhcm_courses';
        $where = 'WHERE 1=1';
        $params = [];

        if ( isset( $args['is_active'] ) ) {
            $where   .= ' AND is_active = %d';
            $params[] = (int) $args['is_active'];
        }
        if ( ! empty( $args['category'] ) ) {
            $where   .= ' AND category = %s';
            $params[] = $args['category'];
        }

        $sql = "SELECT * FROM $t $where ORDER BY title";
        return $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );
    }

    public static function get_course( int $id ) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}rhcm_courses WHERE id = %d", $id ),
            ARRAY_A
        );
    }

    public static function save_course( array $data, int $id = 0 ) {
        global $wpdb;
        $t = $wpdb->prefix . 'rhcm_courses';
        $row = [
            'title'            => sanitize_text_field( $data['title'] ?? '' ),
            'description'      => sanitize_textarea_field( $data['description'] ?? '' ),
            'category'         => sanitize_text_field( $data['category'] ?? '' ),
            'icon'             => sanitize_text_field( $data['icon'] ?? '' ),
            'image_url'        => esc_url_raw( $data['image_url'] ?? '' ),
            'price'            => (float) ( $data['price'] ?? 0 ),
            'duration'         => sanitize_text_field( $data['duration'] ?? '' ),
            'level'            => sanitize_text_field( $data['level'] ?? '' ),
            'rya_cert'         => sanitize_text_field( $data['rya_cert'] ?? '' ),
            'max_participants' => max( 1, (int) ( $data['max_participants'] ?? 12 ) ),
            'is_active'        => isset( $data['is_active'] ) ? 1 : 0,
        ];
        if ( $id ) {
            $wpdb->update( $t, $row, [ 'id' => $id ] );
            return $id;
        }
        $wpdb->insert( $t, $row );
        return $wpdb->insert_id;
    }

    public static function delete_course( int $id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'rhcm_courses', [ 'id' => $id ] );
        $wpdb->delete( $wpdb->prefix . 'rhcm_sessions', [ 'course_id' => $id ] );
    }

    // ── Sessions ──────────────────────────────────────────────────────────────

    public static function get_sessions( array $args = [] ) {
        global $wpdb;
        $cs = $wpdb->prefix . 'rhcm_sessions';
        $cc = $wpdb->prefix . 'rhcm_courses';
        $where = 'WHERE c.is_active = 1 AND s.is_active = 1';
        $params = [];

        if ( ! empty( $args['year'] ) && ! empty( $args['month'] ) ) {
            $where   .= ' AND YEAR(s.start_date) = %d AND MONTH(s.start_date) = %d';
            $params[] = (int) $args['year'];
            $params[] = (int) $args['month'];
        }
        if ( ! empty( $args['category'] ) && $args['category'] !== 'all' ) {
            $where   .= ' AND c.category = %s';
            $params[] = $args['category'];
        }
        if ( ! empty( $args['course_id'] ) ) {
            $where   .= ' AND s.course_id = %d';
            $params[] = (int) $args['course_id'];
        }

        $sql = "
            SELECT s.*,
                   c.title, c.icon, c.category, c.price, c.duration, c.level, c.rya_cert, c.description,
                   COALESCE(s.spaces, c.max_participants) AS total_spaces,
                   (SELECT COUNT(*) FROM {$wpdb->prefix}rhcm_bookings b
                    WHERE b.session_id = s.id AND b.status = 'confirmed') AS enrolled
            FROM $cs s
            JOIN $cc c ON s.course_id = c.id
            $where
            ORDER BY s.start_date, c.title
        ";

        return $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );
    }

    public static function get_session( int $id ) {
        global $wpdb;
        $cs = $wpdb->prefix . 'rhcm_sessions';
        $cc = $wpdb->prefix . 'rhcm_courses';
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT s.*, c.title, c.icon, c.category, c.price, c.duration, c.level, c.rya_cert, c.description,
                        COALESCE(s.spaces, c.max_participants) AS total_spaces,
                        (SELECT COUNT(*) FROM {$wpdb->prefix}rhcm_bookings b WHERE b.session_id = s.id AND b.status = 'confirmed') AS enrolled
                 FROM $cs s JOIN $cc c ON s.course_id = c.id
                 WHERE s.id = %d AND s.is_active = 1 AND c.is_active = 1",
                $id
            ),
            ARRAY_A
        );
    }

    public static function save_session( array $data, int $id = 0 ) {
        global $wpdb;
        $t = $wpdb->prefix . 'rhcm_sessions';
        $row = [
            'course_id'  => (int) ( $data['course_id'] ?? 0 ),
            'start_date' => sanitize_text_field( $data['start_date'] ?? '' ),
            'end_date'   => sanitize_text_field( $data['end_date'] ?? $data['start_date'] ?? '' ),
            'spaces'     => ! empty( $data['spaces'] ) ? (int) $data['spaces'] : null,
            'notes'      => sanitize_textarea_field( $data['notes'] ?? '' ),
            'is_active'  => isset( $data['is_active'] ) ? 1 : 0,
        ];
        if ( $id ) {
            $wpdb->update( $t, $row, [ 'id' => $id ] );
            return $id;
        }
        $wpdb->insert( $t, $row );
        return $wpdb->insert_id;
    }

    public static function delete_session( int $id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'rhcm_sessions', [ 'id' => $id ] );
    }

    // ── Bookings ──────────────────────────────────────────────────────────────

    public static function get_bookings( array $args = [] ) {
        global $wpdb;
        $tb = $wpdb->prefix . 'rhcm_bookings';
        $ts = $wpdb->prefix . 'rhcm_sessions';
        $tc = $wpdb->prefix . 'rhcm_courses';
        $where = 'WHERE 1=1';
        $params = [];

        if ( ! empty( $args['session_id'] ) ) {
            $where   .= ' AND b.session_id = %d';
            $params[] = (int) $args['session_id'];
        }
        if ( ! empty( $args['status'] ) ) {
            $where   .= ' AND b.status = %s';
            $params[] = $args['status'];
        }
        if ( ! empty( $args['email'] ) ) {
            $where   .= ' AND b.email = %s';
            $params[] = $args['email'];
        }

        $sql = "
            SELECT b.*, c.title AS course_title, s.start_date, s.end_date
            FROM $tb b
            JOIN $ts s ON b.session_id = s.id
            JOIN $tc c ON b.course_id  = c.id
            $where
            ORDER BY b.created_at DESC
        ";

        return $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );
    }

    public static function get_booking_by_ref( string $ref ) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT b.*, c.title AS course_title, s.start_date, s.end_date
                 FROM {$wpdb->prefix}rhcm_bookings b
                 JOIN {$wpdb->prefix}rhcm_sessions s ON b.session_id = s.id
                 JOIN {$wpdb->prefix}rhcm_courses  c ON b.course_id  = c.id
                 WHERE b.booking_ref = %s",
                $ref
            ),
            ARRAY_A
        );
    }

    public static function create_booking( array $data ) {
        global $wpdb;

        $session = self::get_session( (int) $data['session_id'] );
        if ( ! $session ) return new WP_Error( 'not_found', 'Session not found.' );

        $spaces_left = (int) $session['total_spaces'] - (int) $session['enrolled'];
        $status      = $spaces_left > 0 ? 'confirmed' : 'waiting';

        // unique ref
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $ref = 'CM';
            for ( $i = 0; $i < 6; $i++ ) $ref .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}rhcm_bookings WHERE booking_ref = %s", $ref
            ) );
        } while ( $exists );

        $wpdb->insert( $wpdb->prefix . 'rhcm_bookings', [
            'booking_ref'     => $ref,
            'session_id'      => (int) $data['session_id'],
            'course_id'       => (int) $session['course_id'],
            'first_name'      => sanitize_text_field( $data['first_name'] ?? '' ),
            'last_name'       => sanitize_text_field( $data['last_name']  ?? '' ),
            'email'           => sanitize_email( $data['email'] ?? '' ),
            'phone'           => sanitize_text_field( $data['phone'] ?? '' ),
            'dob'             => ! empty( $data['dob'] ) ? $data['dob'] : null,
            'emg_name'        => sanitize_text_field( $data['emg_name']  ?? '' ),
            'emg_phone'       => sanitize_text_field( $data['emg_phone'] ?? '' ),
            'notes'           => sanitize_textarea_field( $data['notes'] ?? '' ),
            'discount_code'   => sanitize_text_field( $data['discount_code']   ?? '' ),
            'discount_amount' => (float) ( $data['discount_amount'] ?? 0 ),
            'status'          => $status,
        ] );

        // Increment discount usage count
        if ( ! empty( $data['discount_code'] ) ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}rhcm_discounts SET uses_count = uses_count + 1 WHERE code = %s",
                strtoupper( sanitize_text_field( $data['discount_code'] ) )
            ) );
        }

        return [ 'ref' => $ref, 'status' => $status ];
    }

    // ── Memberships ───────────────────────────────────────────────────────────

    public static function get_memberships( bool $active_only = false ) {
        global $wpdb;
        $where = $active_only ? 'WHERE is_active = 1' : '';
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}rhcm_memberships $where ORDER BY sort_order ASC, id ASC",
            ARRAY_A
        );
    }

    public static function get_membership( int $id ) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}rhcm_memberships WHERE id = %d", $id ),
            ARRAY_A
        );
    }

    public static function save_membership( array $data, int $id = 0 ) {
        global $wpdb;
        $t = $wpdb->prefix . 'rhcm_memberships';
        $row = [
            'name'       => sanitize_text_field( $data['name']      ?? '' ),
            'icon'       => sanitize_text_field( $data['icon']      ?? '' ),
            'tagline'    => sanitize_text_field( $data['tagline']   ?? '' ),
            'price'      => sanitize_text_field( $data['price']     ?? '' ),
            'frequency'  => sanitize_text_field( $data['frequency'] ?? '' ),
            'details'    => sanitize_textarea_field( $data['details'] ?? '' ),
            'is_popular' => isset( $data['is_popular'] ) ? 1 : 0,
            'info_url'   => esc_url_raw( $data['info_url'] ?? '' ),
            'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
            'is_active'  => isset( $data['is_active'] ) ? 1 : 0,
        ];
        if ( $id ) { $wpdb->update( $t, $row, [ 'id' => $id ] ); return $id; }
        $wpdb->insert( $t, $row );
        return $wpdb->insert_id;
    }

    public static function delete_membership( int $id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'rhcm_memberships', [ 'id' => $id ] );
    }

    // ── Discounts ─────────────────────────────────────────────────────────────

    public static function get_discounts() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}rhcm_discounts ORDER BY created_at DESC",
            ARRAY_A
        );
    }

    public static function get_discount( int $id ) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}rhcm_discounts WHERE id = %d", $id ),
            ARRAY_A
        );
    }

    public static function save_discount( array $data, int $id = 0 ) {
        global $wpdb;
        $t = $wpdb->prefix . 'rhcm_discounts';
        $course_ids = '';
        if ( ! empty( $data['course_ids'] ) && is_array( $data['course_ids'] ) ) {
            $course_ids = implode( ',', array_map( 'intval', $data['course_ids'] ) );
        }
        $row = [
            'code'        => strtoupper( sanitize_text_field( $data['code'] ?? '' ) ),
            'description' => sanitize_text_field( $data['description'] ?? '' ),
            'type'        => in_array( $data['type'] ?? '', ['percent','fixed'] ) ? $data['type'] : 'percent',
            'amount'      => max( 0, (float) ( $data['amount'] ?? 0 ) ),
            'course_ids'  => $course_ids,
            'min_order'   => max( 0, (float) ( $data['min_order'] ?? 0 ) ),
            'uses_limit'  => ! empty( $data['uses_limit'] ) ? (int) $data['uses_limit'] : null,
            'expires_at'  => ! empty( $data['expires_at'] ) ? $data['expires_at'] : null,
            'is_active'   => isset( $data['is_active'] ) ? 1 : 0,
        ];
        if ( $id ) { $wpdb->update( $t, $row, [ 'id' => $id ] ); return $id; }
        $wpdb->insert( $t, $row );
        return $wpdb->insert_id;
    }

    public static function delete_discount( int $id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'rhcm_discounts', [ 'id' => $id ] );
    }

    public static function validate_discount( string $code, array $session_ids = [] ) {
        global $wpdb;
        $code = strtoupper( sanitize_text_field( $code ) );

        $d = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rhcm_discounts WHERE code = %s AND is_active = 1", $code
        ), ARRAY_A );

        if ( ! $d ) return [ 'valid' => false, 'message' => 'Invalid discount code.' ];

        if ( $d['expires_at'] && $d['expires_at'] < date('Y-m-d') )
            return [ 'valid' => false, 'message' => 'This discount code has expired.' ];

        if ( $d['uses_limit'] && (int) $d['uses_count'] >= (int) $d['uses_limit'] )
            return [ 'valid' => false, 'message' => 'This discount code has reached its usage limit.' ];

        // If restricted to specific courses, check at least one session matches
        if ( ! empty( $d['course_ids'] ) && ! empty( $session_ids ) ) {
            $allowed = array_map( 'intval', explode( ',', $d['course_ids'] ) );
            $placeholders = implode( ',', array_fill( 0, count( $session_ids ), '%d' ) );
            $course_ids_in_cart = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT course_id FROM {$wpdb->prefix}rhcm_sessions WHERE id IN ($placeholders)",
                ...$session_ids
            ) );
            $matches = array_intersect( array_map( 'intval', $course_ids_in_cart ), $allowed );
            if ( empty( $matches ) )
                return [ 'valid' => false, 'message' => 'This code does not apply to your selected courses.' ];
        }

        return [
            'valid'       => true,
            'type'        => $d['type'],
            'amount'      => (float) $d['amount'],
            'min_order'   => (float) $d['min_order'],
            'description' => $d['description'],
            'message'     => 'Discount applied: ' . $d['description'],
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function get_category_images() {
        return (array) get_option( 'rhcm_category_images', [] );
    }

    public static function save_category_images( array $data ) {
        update_option( 'rhcm_category_images', $data );
    }

    public static function category_colors() {
        return [
            'sailing'      => '#0a2342',
            'keelboat'     => '#3a8a5a',
            'powerboat'    => '#c0392b',
            'windsurfing'  => '#4a9eca',
            'wingsurfing'  => '#2a9d8f',
            'sup'          => '#e07b39',
            'wind_foiling' => '#8e44ad',
            'wing_foiling' => '#c8a84b',
            'board_hire'   => '#7f8c8d',
        ];
    }

    public static function category_labels() {
        return [
            'sailing'      => 'RYA Dinghy Sailing',
            'keelboat'     => 'RYA Keelboat',
            'powerboat'    => 'RYA Powerboat',
            'windsurfing'  => 'RYA Windsurfing',
            'wingsurfing'  => 'RYA Wingsurfing',
            'sup'          => 'RYA Stand Up Paddle',
            'wind_foiling' => 'RYA Wind Foiling',
            'wing_foiling' => 'RYA Wing Foiling',
            'board_hire'   => 'Board Hire',
        ];
    }
}
