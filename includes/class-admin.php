<?php
defined( 'ABSPATH' ) || exit;

class RHCM_Admin {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'admin_post_rhcm_save_course',  [ $this, 'handle_save_course' ] );
        add_action( 'admin_post_rhcm_delete_course',[ $this, 'handle_delete_course' ] );
        add_action( 'admin_post_rhcm_save_session', [ $this, 'handle_save_session' ] );
        add_action( 'admin_post_rhcm_delete_session',[ $this, 'handle_delete_session' ] );
        add_action( 'admin_post_rhcm_update_booking_status', [ $this, 'handle_booking_status' ] );
        add_action( 'admin_post_rhcm_admin_add_booking',    [ $this, 'handle_admin_add_booking' ] );
        add_action( 'admin_post_rhcm_save_membership',     [ $this, 'handle_save_membership' ] );
        add_action( 'admin_post_rhcm_delete_membership',   [ $this, 'handle_delete_membership' ] );
        add_action( 'admin_post_rhcm_save_discount',         [ $this, 'handle_save_discount' ] );
        add_action( 'admin_post_rhcm_delete_discount',       [ $this, 'handle_delete_discount' ] );
        add_action( 'admin_post_rhcm_save_category_images',  [ $this, 'handle_save_category_images' ] );
    }

    public function register_menus() {
        add_menu_page( 'Centre Management', 'Centre Management', 'manage_options', 'rhcm', [ $this, 'page_dashboard' ], 'dashicons-calendar-alt', 30 );
        add_submenu_page( 'rhcm', 'Courses',  'Courses',  'manage_options', 'rhcm-courses',  [ $this, 'page_courses' ] );
        add_submenu_page( 'rhcm', 'Sessions', 'Sessions', 'manage_options', 'rhcm-sessions', [ $this, 'page_sessions' ] );
        add_submenu_page( 'rhcm', 'Bookings', 'Bookings', 'manage_options', 'rhcm-bookings', [ $this, 'page_bookings' ] );
        add_submenu_page( 'rhcm', 'Memberships', 'Memberships', 'manage_options', 'rhcm-memberships', [ $this, 'page_memberships' ] );
        add_submenu_page( 'rhcm', 'Discount Codes', 'Discount Codes', 'manage_options', 'rhcm-discounts', [ $this, 'page_discounts' ] );
        add_submenu_page( 'rhcm', 'Category Images', 'Category Images', 'manage_options', 'rhcm-category-images', [ $this, 'page_category_images' ] );
        add_submenu_page( 'rhcm', 'Shortcodes &amp; Help', 'Shortcodes &amp; Help', 'manage_options', 'rhcm-settings', [ $this, 'page_settings' ] );
    }

    public function enqueue( $hook ) {
        if ( strpos( $hook, 'rhcm' ) === false ) return;
        wp_enqueue_style( 'rhcm-admin', RHCM_URL . 'admin/css/admin.css', [], RHCM_VERSION );
        wp_enqueue_media();
        wp_enqueue_script( 'rhcm-admin', RHCM_URL . 'admin/js/admin.js', [ 'jquery' ], RHCM_VERSION, true );
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function page_dashboard() {
        global $wpdb;
        $total_courses  = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rhcm_courses WHERE is_active=1" );
        $total_sessions = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rhcm_sessions WHERE is_active=1 AND start_date >= CURDATE()" );
        $total_bookings = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rhcm_bookings WHERE status='confirmed'" );
        $recent = RHCM_DB::get_bookings( [] );
        $recent = array_slice( $recent, 0, 10 );
        ?>
        <div class="wrap rhcm-wrap">
        <h1>Centre Management &mdash; Dashboard</h1>
        <div class="rhcm-stat-row">
            <div class="rhcm-stat"><span class="rhcm-stat-num"><?= esc_html( $total_courses ) ?></span><span>Active Courses</span></div>
            <div class="rhcm-stat"><span class="rhcm-stat-num"><?= esc_html( $total_sessions ) ?></span><span>Upcoming Sessions</span></div>
            <div class="rhcm-stat"><span class="rhcm-stat-num"><?= esc_html( $total_bookings ) ?></span><span>Confirmed Bookings</span></div>
        </div>

        <h2 style="margin-top:28px">Recent Bookings</h2>
        <table class="wp-list-table widefat fixed striped rhcm-table">
            <thead><tr>
                <th>Ref</th><th>Name</th><th>Course</th><th>Date</th><th>Status</th><th>Booked</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $recent as $b ): ?>
            <tr>
                <td><strong><?= esc_html( $b['booking_ref'] ) ?></strong></td>
                <td><?= esc_html( $b['first_name'] . ' ' . $b['last_name'] ) ?></td>
                <td><?= esc_html( $b['course_title'] ) ?></td>
                <td><?= esc_html( date( 'j M Y', strtotime( $b['start_date'] ) ) ) ?></td>
                <td><span class="rhcm-badge rhcm-badge-<?= esc_attr( $b['status'] ) ?>"><?= esc_html( ucfirst( $b['status'] ) ) ?></span></td>
                <td><?= esc_html( date( 'j M Y H:i', strtotime( $b['created_at'] ) ) ) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ( empty( $recent ) ): ?><tr><td colspan="6">No bookings yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    // ── Courses ───────────────────────────────────────────────────────────────

    public function page_courses() {
        $action  = $_GET['action'] ?? 'list';
        $id      = (int) ( $_GET['id'] ?? 0 );
        $notice  = $_GET['notice'] ?? '';

        if ( $action === 'edit' || $action === 'new' ) {
            $course = $id ? RHCM_DB::get_course( $id ) : [];
            $this->render_course_form( $course, $notice );
        } else {
            $this->render_course_list( $notice );
        }
    }

    private function render_course_list( string $notice ) {
        $courses = RHCM_DB::get_courses();
        ?>
        <div class="wrap rhcm-wrap">
        <h1 class="wp-heading-inline">Courses</h1>
        <a href="<?= esc_url( admin_url( 'admin.php?page=rhcm-courses&action=new' ) ) ?>" class="page-title-action">Add New</a>
        <?php if ( $notice === 'saved' ):   ?><div class="notice notice-success is-dismissible"><p>Course saved.</p></div><?php endif; ?>
        <?php if ( $notice === 'deleted' ): ?><div class="notice notice-success is-dismissible"><p>Course deleted.</p></div><?php endif; ?>

        <table class="wp-list-table widefat fixed striped rhcm-table" style="margin-top:16px">
            <thead><tr>
                <th>Title</th><th>Category</th><th>Price</th><th>Duration</th><th>Level</th><th>Participants</th><th>Active</th><th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $courses as $c ): ?>
            <tr>
                <td><strong><?= esc_html( $c['icon'] . ' ' . $c['title'] ) ?></strong></td>
                <td><?= esc_html( ucfirst( $c['category'] ) ) ?></td>
                <td>&pound;<?= esc_html( number_format( (float) $c['price'], 2 ) ) ?></td>
                <td><?= esc_html( $c['duration'] ) ?></td>
                <td><?= esc_html( $c['level'] ) ?></td>
                <td><?= esc_html( $c['max_participants'] ) ?></td>
                <td><?= $c['is_active'] ? '&#10003;' : '&mdash;' ?></td>
                <td>
                    <a href="<?= esc_url( admin_url( 'admin.php?page=rhcm-courses&action=edit&id=' . $c['id'] ) ) ?>">Edit</a>
                    &nbsp;|&nbsp;
                    <a href="<?= esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rhcm_delete_course&id=' . $c['id'] ), 'rhcm_delete_course_' . $c['id'] ) ) ?>"
                       onclick="return confirm('Delete this course and all its sessions?')" style="color:#c0392b">Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if ( empty( $courses ) ): ?><tr><td colspan="8">No courses yet. <a href="<?= esc_url( admin_url( 'admin.php?page=rhcm-courses&action=new' ) ) ?>">Add one</a>.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    private function render_course_form( array $c, string $notice ) {
        $id       = (int) ( $c['id'] ?? 0 );
        $labels   = RHCM_DB::category_labels();
        ?>
        <div class="wrap rhcm-wrap">
        <h1><?= $id ? 'Edit Course' : 'Add Course' ?></h1>
        <a href="<?= esc_url( admin_url( 'admin.php?page=rhcm-courses' ) ) ?>">&larr; Back to Courses</a>
        <?php if ( $notice === 'saved' ): ?><div class="notice notice-success is-dismissible"><p>Course saved.</p></div><?php endif; ?>

        <form method="POST" action="<?= esc_url( admin_url( 'admin-post.php' ) ) ?>" class="rhcm-form">
            <?php wp_nonce_field( 'rhcm_save_course', 'rhcm_nonce' ); ?>
            <input type="hidden" name="action" value="rhcm_save_course">
            <input type="hidden" name="id" value="<?= (int) $id ?>">

            <div class="rhcm-form-grid">
                <div class="rhcm-field">
                    <label>Title *</label>
                    <input type="text" name="title" value="<?= esc_attr( $c['title'] ?? '' ) ?>" required>
                </div>
                <div class="rhcm-field">
                    <label>Icon (emoji)</label>
                    <input type="text" name="icon" value="<?= esc_attr( $c['icon'] ?? '' ) ?>" placeholder="&#9925;" style="max-width:80px">
                </div>
                <div class="rhcm-field">
                    <label>Category</label>
                    <select name="category">
                        <option value="">— none —</option>
                        <?php foreach ( $labels as $key => $label ): ?>
                        <option value="<?= esc_attr( $key ) ?>" <?= selected( $c['category'] ?? '', $key, false ) ?>><?= esc_html( $label ) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rhcm-field">
                    <label>Price (&pound;) *</label>
                    <input type="number" name="price" step="0.01" min="0" value="<?= esc_attr( $c['price'] ?? '' ) ?>" required>
                </div>
                <div class="rhcm-field">
                    <label>Duration</label>
                    <input type="text" name="duration" value="<?= esc_attr( $c['duration'] ?? '' ) ?>" placeholder="e.g. 2 days">
                </div>
                <div class="rhcm-field">
                    <label>Level</label>
                    <input type="text" name="level" value="<?= esc_attr( $c['level'] ?? '' ) ?>" placeholder="e.g. Beginner">
                </div>
                <div class="rhcm-field">
                    <label>RYA Certificate</label>
                    <input type="text" name="rya_cert" value="<?= esc_attr( $c['rya_cert'] ?? '' ) ?>" placeholder="e.g. RYA Level 1">
                </div>
                <div class="rhcm-field">
                    <label>Max Participants</label>
                    <input type="number" name="max_participants" min="1" value="<?= esc_attr( $c['max_participants'] ?? 12 ) ?>">
                </div>
                <div class="rhcm-field rhcm-field-full">
                    <label>Description</label>
                    <textarea name="description" rows="5"><?= esc_textarea( $c['description'] ?? '' ) ?></textarea>
                </div>
                <div class="rhcm-field rhcm-field-full">
                    <label>Course Image</label>
                    <div class="rhcm-img-picker">
                        <input type="hidden" name="image_url" id="rhcm-course-img-url" value="<?= esc_attr( $c['image_url'] ?? '' ) ?>">
                        <?php $has_img = ! empty( $c['image_url'] ); ?>
                        <img id="rhcm-course-img-preview" src="<?= esc_url( $c['image_url'] ?? '' ) ?>"
                             style="<?= $has_img ? '' : 'display:none;' ?>max-width:320px;border-radius:6px;margin-bottom:8px;display:block">
                        <button type="button" class="button rhcm-media-btn"
                                data-input="rhcm-course-img-url"
                                data-img="rhcm-course-img-preview"><?= $has_img ? 'Change Image' : 'Select Image' ?></button>
                        <button type="button" class="button rhcm-media-remove"
                                data-input="rhcm-course-img-url"
                                data-img="rhcm-course-img-preview"
                                style="<?= $has_img ? '' : 'display:none' ?>">Remove</button>
                    </div>
                </div>
                <div class="rhcm-field">
                    <label>
                        <input type="checkbox" name="is_active" value="1" <?= checked( $c['is_active'] ?? 1, 1, false ) ?>>
                        Active (show on front-end)
                    </label>
                </div>
            </div>

            <p><button type="submit" class="button button-primary">Save Course</button></p>
        </form>
        </div>
        <?php
    }

    public function handle_save_course() {
        check_admin_referer( 'rhcm_save_course', 'rhcm_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised' );
        $id = RHCM_DB::save_course( $_POST, (int) ( $_POST['id'] ?? 0 ) );
        wp_redirect( admin_url( 'admin.php?page=rhcm-courses&action=edit&id=' . $id . '&notice=saved' ) );
        exit;
    }

    public function handle_delete_course() {
        $id = (int) ( $_GET['id'] ?? 0 );
        check_admin_referer( 'rhcm_delete_course_' . $id );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised' );
        RHCM_DB::delete_course( $id );
        wp_redirect( admin_url( 'admin.php?page=rhcm-courses&notice=deleted' ) );
        exit;
    }

    // ── Sessions ──────────────────────────────────────────────────────────────

    public function page_sessions() {
        $action = $_GET['action'] ?? 'list';
        $id     = (int) ( $_GET['id'] ?? 0 );
        $notice = $_GET['notice'] ?? '';

        if ( $action === 'edit' || $action === 'new' ) {
            $session = $id ? RHCM_DB::get_session( $id ) : [];
            // for new sessions, pre-select course from ?course_id=
            if ( ! $id && ! empty( $_GET['course_id'] ) ) {
                $session['course_id'] = (int) $_GET['course_id'];
            }
            $this->render_session_form( $session, $notice );
        } else {
            $this->render_session_list( $notice );
        }
    }

    private function render_session_list( string $notice ) {
        $course_filter = (int) ( $_GET['course_id'] ?? 0 );
        $args = $course_filter ? [ 'course_id' => $course_filter ] : [];
        // Override is_active filter for admin — show all
        global $wpdb;
        $ts = $wpdb->prefix . 'rhcm_sessions';
        $tc = $wpdb->prefix . 'rhcm_courses';
        $where = 'WHERE 1=1';
        $params = [];
        if ( $course_filter ) { $where .= ' AND s.course_id = %d'; $params[] = $course_filter; }
        $sql = "SELECT s.*, c.title AS course_title, c.icon,
                       COALESCE(s.spaces, c.max_participants) AS total_spaces,
                       (SELECT COUNT(*) FROM {$wpdb->prefix}rhcm_bookings b WHERE b.session_id = s.id AND b.status='confirmed') AS enrolled
                FROM $ts s JOIN $tc c ON s.course_id = c.id $where ORDER BY s.start_date DESC LIMIT 100";
        $sessions = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );

        $new_url = admin_url( 'admin.php?page=rhcm-sessions&action=new' . ( $course_filter ? '&course_id=' . $course_filter : '' ) );
        ?>
        <div class="wrap rhcm-wrap">
        <h1 class="wp-heading-inline">Sessions</h1>
        <a href="<?= esc_url( $new_url ) ?>" class="page-title-action">Add New</a>
        <?php if ( $notice === 'saved' ):   ?><div class="notice notice-success is-dismissible"><p>Session saved.</p></div><?php endif; ?>
        <?php if ( $notice === 'deleted' ): ?><div class="notice notice-success is-dismissible"><p>Session deleted.</p></div><?php endif; ?>

        <table class="wp-list-table widefat fixed striped rhcm-table" style="margin-top:16px">
            <thead><tr>
                <th>Course</th><th>Start Date</th><th>End Date</th><th>Spaces</th><th>Enrolled</th><th>Active</th><th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $sessions as $s ): ?>
            <tr>
                <td><?= esc_html( ( $s['icon'] ?? '' ) . ' ' . $s['course_title'] ) ?></td>
                <td><?= esc_html( date( 'j M Y', strtotime( $s['start_date'] ) ) ) ?></td>
                <td><?= esc_html( date( 'j M Y', strtotime( $s['end_date'] ) ) ) ?></td>
                <td><?= esc_html( $s['total_spaces'] ) ?></td>
                <td><?= esc_html( $s['enrolled'] ) ?></td>
                <td><?= $s['is_active'] ? '&#10003;' : '&mdash;' ?></td>
                <td>
                    <a href="<?= esc_url( admin_url( 'admin.php?page=rhcm-sessions&action=edit&id=' . $s['id'] ) ) ?>">Edit</a>
                    &nbsp;|&nbsp;
                    <a href="<?= esc_url( admin_url( 'admin.php?page=rhcm-bookings&session_id=' . $s['id'] ) ) ?>">Bookings (<?= (int) $s['enrolled'] ?>)</a>
                    &nbsp;|&nbsp;
                    <a href="<?= esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rhcm_delete_session&id=' . $s['id'] ), 'rhcm_delete_session_' . $s['id'] ) ) ?>"
                       onclick="return confirm('Delete this session?')" style="color:#c0392b">Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if ( empty( $sessions ) ): ?><tr><td colspan="7">No sessions found.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    private function render_session_form( array $s, string $notice ) {
        $id      = (int) ( $s['id'] ?? 0 );
        $courses = RHCM_DB::get_courses( [ 'is_active' => 1 ] );
        ?>
        <div class="wrap rhcm-wrap">
        <h1><?= $id ? 'Edit Session' : 'Add Session' ?></h1>
        <a href="<?= esc_url( admin_url( 'admin.php?page=rhcm-sessions' ) ) ?>">&larr; Back to Sessions</a>
        <?php if ( $notice === 'saved' ): ?><div class="notice notice-success is-dismissible"><p>Session saved.</p></div><?php endif; ?>

        <form method="POST" action="<?= esc_url( admin_url( 'admin-post.php' ) ) ?>" class="rhcm-form">
            <?php wp_nonce_field( 'rhcm_save_session', 'rhcm_nonce' ); ?>
            <input type="hidden" name="action" value="rhcm_save_session">
            <input type="hidden" name="id" value="<?= (int) $id ?>">

            <div class="rhcm-form-grid">
                <div class="rhcm-field rhcm-field-full">
                    <label>Course *</label>
                    <select name="course_id" required>
                        <option value="">— select —</option>
                        <?php foreach ( $courses as $c ): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= selected( $s['course_id'] ?? 0, $c['id'], false ) ?>><?= esc_html( $c['icon'] . ' ' . $c['title'] ) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rhcm-field">
                    <label>Start Date *</label>
                    <input type="date" name="start_date" value="<?= esc_attr( $s['start_date'] ?? '' ) ?>" required>
                </div>
                <div class="rhcm-field">
                    <label>End Date</label>
                    <input type="date" name="end_date" value="<?= esc_attr( $s['end_date'] ?? '' ) ?>" placeholder="Leave blank for single day">
                </div>
                <div class="rhcm-field">
                    <label>Override Spaces <small>(leave blank to use course default)</small></label>
                    <input type="number" name="spaces" min="0" value="<?= esc_attr( $s['spaces'] ?? '' ) ?>">
                </div>
                <div class="rhcm-field rhcm-field-full">
                    <label>Notes (shown on card)</label>
                    <textarea name="notes" rows="3"><?= esc_textarea( $s['notes'] ?? '' ) ?></textarea>
                </div>
                <div class="rhcm-field">
                    <label>
                        <input type="checkbox" name="is_active" value="1" <?= checked( $s['is_active'] ?? 1, 1, false ) ?>>
                        Active (show on front-end)
                    </label>
                </div>
            </div>

            <p><button type="submit" class="button button-primary">Save Session</button></p>
        </form>
        </div>
        <?php
    }

    public function handle_save_session() {
        check_admin_referer( 'rhcm_save_session', 'rhcm_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised' );
        // If end_date blank, default to start_date
        if ( empty( $_POST['end_date'] ) ) $_POST['end_date'] = $_POST['start_date'];
        $id = RHCM_DB::save_session( $_POST, (int) ( $_POST['id'] ?? 0 ) );
        wp_redirect( admin_url( 'admin.php?page=rhcm-sessions&action=edit&id=' . $id . '&notice=saved' ) );
        exit;
    }

    public function handle_delete_session() {
        $id = (int) ( $_GET['id'] ?? 0 );
        check_admin_referer( 'rhcm_delete_session_' . $id );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised' );
        RHCM_DB::delete_session( $id );
        wp_redirect( admin_url( 'admin.php?page=rhcm-sessions&notice=deleted' ) );
        exit;
    }

    // ── Bookings ──────────────────────────────────────────────────────────────

    public function page_bookings() {
        $action     = $_GET['action'] ?? 'list';
        $notice     = $_GET['notice'] ?? '';

        if ( $action === 'add' ) {
            $this->render_add_booking_form( $notice );
            return;
        }

        $session_id = (int) ( $_GET['session_id'] ?? 0 );
        $args       = $session_id ? [ 'session_id' => $session_id ] : [];
        $bookings   = RHCM_DB::get_bookings( $args );
        ?>
        <div class="wrap rhcm-wrap">
        <h1 class="wp-heading-inline">Bookings<?= $session_id ? ' &mdash; Session #' . $session_id : '' ?></h1>
        <a href="<?= esc_url( admin_url( 'admin.php?page=rhcm-bookings&action=add' ) ) ?>" class="page-title-action">+ Add Booking</a>
        <?php if ( $session_id ): ?>
            <a href="<?= esc_url( admin_url( 'admin.php?page=rhcm-bookings' ) ) ?>" style="margin-left:12px;font-size:.9rem">&larr; All Bookings</a>
        <?php endif; ?>
        <?php if ( $notice === 'updated' ): ?><div class="notice notice-success is-dismissible"><p>Booking updated.</p></div><?php endif; ?>
        <?php if ( $notice === 'added' ):   ?><div class="notice notice-success is-dismissible"><p>Booking added.</p></div><?php endif; ?>

        <table class="wp-list-table widefat fixed striped rhcm-table" style="margin-top:16px">
            <thead><tr>
                <th>Ref</th><th>Name</th><th>Email</th><th>Course</th><th>Session Date</th><th>Status</th><th>Booked At</th><th>Action</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $bookings as $b ): ?>
            <tr>
                <td><strong><?= esc_html( $b['booking_ref'] ) ?></strong></td>
                <td><?= esc_html( $b['first_name'] . ' ' . $b['last_name'] ) ?></td>
                <td><a href="mailto:<?= esc_attr( $b['email'] ) ?>"><?= esc_html( $b['email'] ) ?></a></td>
                <td><?= esc_html( $b['course_title'] ) ?></td>
                <td><?= esc_html( date( 'j M Y', strtotime( $b['start_date'] ) ) ) ?></td>
                <td>
                    <form method="POST" action="<?= esc_url( admin_url( 'admin-post.php' ) ) ?>" style="display:inline">
                        <?php wp_nonce_field( 'rhcm_update_booking_' . $b['id'], 'rhcm_nonce' ); ?>
                        <input type="hidden" name="action" value="rhcm_update_booking_status">
                        <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                        <input type="hidden" name="redirect_session" value="<?= (int) $session_id ?>">
                        <select name="status" onchange="this.form.submit()" class="rhcm-status-select rhcm-status-<?= esc_attr( $b['status'] ) ?>">
                            <?php foreach ( ['confirmed','waiting','cancelled'] as $st ): ?>
                            <option value="<?= $st ?>" <?= selected( $b['status'], $st, false ) ?>><?= ucfirst( $st ) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </td>
                <td><?= esc_html( date( 'j M Y H:i', strtotime( $b['created_at'] ) ) ) ?></td>
                <td>
                    <a href="#" onclick="rhcmShowDetails(this)" data-details="<?= esc_attr( json_encode([
                        'Phone' => $b['phone'], 'DOB' => $b['dob'],
                        'Emergency' => $b['emg_name'] . ' ' . $b['emg_phone'],
                        'Notes' => $b['notes'],
                    ]) ) ?>">Details</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if ( empty( $bookings ) ): ?><tr><td colspan="8">No bookings found.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>

        <div id="rhcm-detail-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
            <div style="background:#fff;border-radius:8px;padding:28px;max-width:420px;width:90%;position:relative">
                <button onclick="document.getElementById('rhcm-detail-modal').style.display='none'" style="position:absolute;top:12px;right:12px;background:none;border:none;font-size:1.2rem;cursor:pointer">&times;</button>
                <h3 style="margin-top:0">Booking Details</h3>
                <div id="rhcm-detail-body"></div>
            </div>
        </div>
        <script>
        function rhcmShowDetails(el) {
            var d = JSON.parse(el.dataset.details);
            var html = '<dl style="margin:0">';
            for (var k in d) { if (d[k]) html += '<dt style="font-weight:600;margin-top:10px">'+k+'</dt><dd style="margin:0">'+d[k]+'</dd>'; }
            html += '</dl>';
            document.getElementById('rhcm-detail-body').innerHTML = html;
            document.getElementById('rhcm-detail-modal').style.display = 'flex';
            return false;
        }
        </script>
        <?php
    }

    private function render_add_booking_form( string $notice ) {
        global $wpdb;
        // Upcoming sessions with course info
        $sessions = $wpdb->get_results(
            "SELECT s.id, s.start_date, s.end_date,
                    c.title, c.icon, c.price,
                    COALESCE(s.spaces, c.max_participants) AS total_spaces,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}rhcm_bookings b WHERE b.session_id = s.id AND b.status='confirmed') AS enrolled
             FROM {$wpdb->prefix}rhcm_sessions s
             JOIN {$wpdb->prefix}rhcm_courses  c ON s.course_id = c.id
             WHERE s.is_active = 1 AND c.is_active = 1 AND s.end_date >= CURDATE()
             ORDER BY s.start_date ASC
             LIMIT 200",
            ARRAY_A
        );
        ?>
        <div class="wrap rhcm-wrap">
        <h1>Add Booking</h1>
        <a href="<?= esc_url( admin_url( 'admin.php?page=rhcm-bookings' ) ) ?>">&larr; Back to Bookings</a>
        <?php if ( $notice === 'added' ): ?><div class="notice notice-success is-dismissible"><p>Booking added successfully.</p></div><?php endif; ?>

        <form method="POST" action="<?= esc_url( admin_url( 'admin-post.php' ) ) ?>" class="rhcm-form">
            <?php wp_nonce_field( 'rhcm_admin_add_booking', 'rhcm_nonce' ); ?>
            <input type="hidden" name="action" value="rhcm_admin_add_booking">

            <div class="rhcm-form-grid">

                <div class="rhcm-field rhcm-field-full">
                    <label>Session *</label>
                    <select name="session_id" required>
                        <option value="">— select a session —</option>
                        <?php foreach ( $sessions as $s ):
                            $date = date( 'j M Y', strtotime( $s['start_date'] ) );
                            if ( $s['start_date'] !== $s['end_date'] )
                                $date .= ' – ' . date( 'j M Y', strtotime( $s['end_date'] ) );
                            $spaces_left = (int) $s['total_spaces'] - (int) $s['enrolled'];
                            $label = esc_html( $s['icon'] . ' ' . $s['title'] . ' — ' . $date . ' (' . $spaces_left . ' spaces)' );
                        ?>
                        <option value="<?= (int) $s['id'] ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ( empty( $sessions ) ): ?>
                    <p style="color:#c0392b;font-size:.82rem;margin-top:4px">No upcoming sessions found. <a href="<?= esc_url( admin_url( 'admin.php?page=rhcm-sessions&action=new' ) ) ?>">Add a session first.</a></p>
                    <?php endif; ?>
                </div>

                <div class="rhcm-field">
                    <label>First Name *</label>
                    <input type="text" name="first_name" required autocomplete="off">
                </div>
                <div class="rhcm-field">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" required autocomplete="off">
                </div>
                <div class="rhcm-field">
                    <label>Email *</label>
                    <input type="email" name="email" required autocomplete="off">
                </div>
                <div class="rhcm-field">
                    <label>Phone</label>
                    <input type="tel" name="phone" placeholder="07700 900123">
                </div>
                <div class="rhcm-field">
                    <label>Date of Birth</label>
                    <input type="date" name="dob">
                </div>
                <div class="rhcm-field">
                    <label>Status</label>
                    <select name="status">
                        <option value="confirmed">Confirmed</option>
                        <option value="waiting">Waiting List</option>
                    </select>
                </div>
                <div class="rhcm-field">
                    <label>Emergency Contact Name</label>
                    <input type="text" name="emg_name">
                </div>
                <div class="rhcm-field">
                    <label>Emergency Contact Phone</label>
                    <input type="tel" name="emg_phone">
                </div>
                <div class="rhcm-field rhcm-field-full">
                    <label>Notes</label>
                    <textarea name="notes" rows="3" placeholder="Special requirements, medical info..."></textarea>
                </div>
                <div class="rhcm-field">
                    <label>
                        <input type="checkbox" name="send_email" value="1" checked>
                        Send confirmation email to participant
                    </label>
                </div>
            </div>

            <p><button type="submit" class="button button-primary">Add Booking</button></p>
        </form>
        </div>
        <?php
    }

    public function handle_admin_add_booking() {
        check_admin_referer( 'rhcm_admin_add_booking', 'rhcm_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised' );

        $session_id = (int) ( $_POST['session_id'] ?? 0 );
        $first = sanitize_text_field( $_POST['first_name'] ?? '' );
        $last  = sanitize_text_field( $_POST['last_name']  ?? '' );
        $email = sanitize_email( $_POST['email'] ?? '' );

        if ( ! $session_id || ! $first || ! $last || ! is_email( $email ) ) {
            wp_die( 'Please fill in all required fields.' );
        }

        // Admin can force the status; generate ref manually
        $session = RHCM_DB::get_session( $session_id );
        if ( ! $session ) wp_die( 'Session not found.' );

        $status = in_array( $_POST['status'] ?? '', ['confirmed','waiting','cancelled'] )
            ? $_POST['status']
            : 'confirmed';

        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        global $wpdb;
        do {
            $ref = 'CM';
            for ( $i = 0; $i < 6; $i++ ) $ref .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}rhcm_bookings WHERE booking_ref = %s", $ref
            ) );
        } while ( $exists );

        $wpdb->insert( $wpdb->prefix . 'rhcm_bookings', [
            'booking_ref' => $ref,
            'session_id'  => $session_id,
            'course_id'   => (int) $session['course_id'],
            'first_name'  => $first,
            'last_name'   => sanitize_text_field( $_POST['last_name'] ),
            'email'       => $email,
            'phone'       => sanitize_text_field( $_POST['phone'] ?? '' ),
            'dob'         => ! empty( $_POST['dob'] ) ? $_POST['dob'] : null,
            'emg_name'    => sanitize_text_field( $_POST['emg_name']  ?? '' ),
            'emg_phone'   => sanitize_text_field( $_POST['emg_phone'] ?? '' ),
            'notes'       => sanitize_textarea_field( $_POST['notes'] ?? '' ),
            'status'      => $status,
        ] );

        if ( ! empty( $_POST['send_email'] ) ) {
            $date = date( 'j F Y', strtotime( $session['start_date'] ) );
            if ( $session['start_date'] !== $session['end_date'] )
                $date .= ' – ' . date( 'j F Y', strtotime( $session['end_date'] ) );
            $msg  = "Dear $first,\n\nYour booking has been confirmed.\n\n";
            $msg .= "Course: {$session['title']}\nDate: $date\nRef: $ref\nStatus: " . ucfirst( $status ) . "\n\n";
            $msg .= get_bloginfo('name');
            wp_mail( $email, 'Booking Confirmation — ' . $session['title'], $msg );
        }

        wp_redirect( admin_url( 'admin.php?page=rhcm-bookings&notice=added' ) );
        exit;
    }

    // ── Memberships ───────────────────────────────────────────────────────────

    public function page_memberships() {
        $action = $_GET['action'] ?? 'list';
        $id     = (int) ( $_GET['id'] ?? 0 );
        $notice = $_GET['notice'] ?? '';
        if ( $action === 'edit' || $action === 'new' ) {
            $m = $id ? RHCM_DB::get_membership( $id ) : [];
            $this->render_membership_form( $m, $notice );
        } else {
            $this->render_membership_list( $notice );
        }
    }

    private function render_membership_list( string $notice ) {
        $memberships = RHCM_DB::get_memberships();
        ?>
        <div class="wrap rhcm-wrap">
        <h1 class="wp-heading-inline">Memberships</h1>
        <a href="<?= esc_url( admin_url('admin.php?page=rhcm-memberships&action=new') ) ?>" class="page-title-action">Add New</a>
        <?php if ( $notice === 'saved' ):   ?><div class="notice notice-success is-dismissible"><p>Membership saved.</p></div><?php endif; ?>
        <?php if ( $notice === 'deleted' ): ?><div class="notice notice-success is-dismissible"><p>Membership deleted.</p></div><?php endif; ?>
        <p style="color:#6b7280;margin:12px 0 4px">Display on your site with the shortcode <code>[rhcm_memberships]</code></p>
        <table class="wp-list-table widefat fixed striped rhcm-table" style="margin-top:12px">
            <thead><tr><th>Order</th><th>Icon</th><th>Name</th><th>Price</th><th>Frequency</th><th>Popular</th><th>Active</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ( $memberships as $m ): ?>
            <tr>
                <td><?= (int) $m['sort_order'] ?></td>
                <td style="font-size:1.3rem"><?= esc_html( $m['icon'] ) ?></td>
                <td><strong><?= esc_html( $m['name'] ) ?></strong><br><span style="color:#6b7280;font-size:.8rem"><?= esc_html( $m['tagline'] ) ?></span></td>
                <td><?= esc_html( $m['price'] ) ?></td>
                <td><?= esc_html( $m['frequency'] ) ?></td>
                <td><?= $m['is_popular'] ? '<span style="color:#c8a84b;font-weight:700">&#9733; Yes</span>' : '&mdash;' ?></td>
                <td><?= $m['is_active'] ? '&#10003;' : '&mdash;' ?></td>
                <td>
                    <a href="<?= esc_url( admin_url('admin.php?page=rhcm-memberships&action=edit&id=' . $m['id']) ) ?>">Edit</a>
                    &nbsp;|&nbsp;
                    <a href="<?= esc_url( wp_nonce_url( admin_url('admin-post.php?action=rhcm_delete_membership&id=' . $m['id']), 'rhcm_delete_membership_' . $m['id'] ) ) ?>"
                       onclick="return confirm('Delete this membership option?')" style="color:#c0392b">Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if ( empty( $memberships ) ): ?><tr><td colspan="8">No memberships yet. <a href="<?= esc_url( admin_url('admin.php?page=rhcm-memberships&action=new') ) ?>">Add one</a>.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    private function render_membership_form( array $m, string $notice ) {
        $id = (int) ( $m['id'] ?? 0 );
        ?>
        <div class="wrap rhcm-wrap">
        <h1><?= $id ? 'Edit Membership' : 'Add Membership' ?></h1>
        <a href="<?= esc_url( admin_url('admin.php?page=rhcm-memberships') ) ?>">&larr; Back to Memberships</a>
        <?php if ( $notice === 'saved' ): ?><div class="notice notice-success is-dismissible"><p>Membership saved.</p></div><?php endif; ?>

        <form method="POST" action="<?= esc_url( admin_url('admin-post.php') ) ?>" class="rhcm-form">
            <?php wp_nonce_field( 'rhcm_save_membership', 'rhcm_nonce' ); ?>
            <input type="hidden" name="action" value="rhcm_save_membership">
            <input type="hidden" name="id" value="<?= (int) $id ?>">

            <div class="rhcm-form-grid">
                <div class="rhcm-field">
                    <label>Icon (emoji)</label>
                    <input type="text" name="icon" value="<?= esc_attr( $m['icon'] ?? '' ) ?>" placeholder="⚓" style="max-width:80px">
                </div>
                <div class="rhcm-field">
                    <label>Sort Order</label>
                    <input type="number" name="sort_order" value="<?= esc_attr( $m['sort_order'] ?? 0 ) ?>" style="max-width:100px">
                </div>
                <div class="rhcm-field rhcm-field-full">
                    <label>Name *</label>
                    <input type="text" name="name" value="<?= esc_attr( $m['name'] ?? '' ) ?>" required placeholder="e.g. Standard Membership">
                </div>
                <div class="rhcm-field rhcm-field-full">
                    <label>Tagline <small>(short subtitle shown under the name)</small></label>
                    <input type="text" name="tagline" value="<?= esc_attr( $m['tagline'] ?? '' ) ?>" placeholder="e.g. Own a dinghy? This is your membership.">
                </div>
                <div class="rhcm-field">
                    <label>Price <small>(display text)</small></label>
                    <input type="text" name="price" value="<?= esc_attr( $m['price'] ?? '' ) ?>" placeholder="e.g. From £62  or  £5">
                </div>
                <div class="rhcm-field">
                    <label>Frequency <small>(shown below price)</small></label>
                    <input type="text" name="frequency" value="<?= esc_attr( $m['frequency'] ?? '' ) ?>" placeholder="e.g. per month  or  per year">
                </div>
                <div class="rhcm-field rhcm-field-full">
                    <label>Details / Features <small>(one bullet point per line)</small></label>
                    <textarea name="details" rows="7" placeholder="Full club access year-round&#10;Race &amp; compete with the fleet&#10;25% discount on all RYA courses"><?= esc_textarea( $m['details'] ?? '' ) ?></textarea>
                </div>
                <div class="rhcm-field rhcm-field-full">
                    <label>Destination URL <small>(the "Find Out More" button links here)</small></label>
                    <input type="url" name="info_url" value="<?= esc_attr( $m['info_url'] ?? '' ) ?>" placeholder="https://yoursite.com/standard-membership">
                </div>
                <div class="rhcm-field">
                    <label><input type="checkbox" name="is_popular" value="1" <?= checked( $m['is_popular'] ?? 0, 1, false ) ?>> Mark as <strong>Most Popular</strong></label>
                </div>
                <div class="rhcm-field">
                    <label><input type="checkbox" name="is_active" value="1" <?= checked( $m['is_active'] ?? 1, 1, false ) ?>> Active (show on front-end)</label>
                </div>
            </div>
            <p><button type="submit" class="button button-primary">Save Membership</button></p>
        </form>
        </div>
        <?php
    }

    public function handle_save_membership() {
        check_admin_referer( 'rhcm_save_membership', 'rhcm_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised' );
        $id = RHCM_DB::save_membership( $_POST, (int) ( $_POST['id'] ?? 0 ) );
        wp_redirect( admin_url( 'admin.php?page=rhcm-memberships&action=edit&id=' . $id . '&notice=saved' ) );
        exit;
    }

    public function handle_delete_membership() {
        $id = (int) ( $_GET['id'] ?? 0 );
        check_admin_referer( 'rhcm_delete_membership_' . $id );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised' );
        RHCM_DB::delete_membership( $id );
        wp_redirect( admin_url( 'admin.php?page=rhcm-memberships&notice=deleted' ) );
        exit;
    }

    // ── Category Images ───────────────────────────────────────────────────────

    public function page_category_images() {
        $notice  = $_GET['notice'] ?? '';
        $labels  = RHCM_DB::category_labels();
        $colors  = RHCM_DB::category_colors();
        $images  = RHCM_DB::get_category_images();
        ?>
        <div class="wrap rhcm-wrap">
        <h1>Category Images</h1>
        <p style="color:#6b7280;margin-bottom:20px">Set a banner image for each course category. These appear above the course cards in the <code>[rhcm_courses]</code> shortcode.</p>
        <?php if ( $notice === 'saved' ): ?><div class="notice notice-success is-dismissible"><p>Category images saved.</p></div><?php endif; ?>

        <form method="POST" action="<?= esc_url( admin_url( 'admin-post.php' ) ) ?>" class="rhcm-form" style="max-width:700px">
            <?php wp_nonce_field( 'rhcm_save_category_images', 'rhcm_nonce' ); ?>
            <input type="hidden" name="action" value="rhcm_save_category_images">

            <div style="display:flex;flex-direction:column;gap:24px;background:#fff;border-radius:8px;padding:24px;box-shadow:0 1px 4px rgba(0,0,0,.08)">
            <?php foreach ( $labels as $key => $label ):
                $img_url  = $images[ $key ] ?? '';
                $input_id = 'rhcm-cat-img-' . esc_attr( $key );
                $prev_id  = 'rhcm-cat-prev-' . esc_attr( $key );
                $has_img  = ! empty( $img_url );
                $color    = $colors[ $key ] ?? '#0a2342';
            ?>
            <div style="display:flex;align-items:flex-start;gap:20px;padding-bottom:20px;border-bottom:1px solid #e5e7eb">
                <span style="width:12px;height:12px;border-radius:50%;background:<?= esc_attr($color) ?>;flex-shrink:0;margin-top:4px"></span>
                <div style="flex:1">
                    <strong style="display:block;margin-bottom:10px;color:#0a2342"><?= esc_html( $label ) ?></strong>
                    <input type="hidden" name="images[<?= esc_attr( $key ) ?>]" id="<?= $input_id ?>" value="<?= esc_attr( $img_url ) ?>">
                    <img id="<?= $prev_id ?>" src="<?= esc_url( $img_url ) ?>"
                         style="<?= $has_img ? '' : 'display:none;' ?>max-width:280px;height:120px;object-fit:cover;border-radius:6px;display:block;margin-bottom:8px">
                    <button type="button" class="button rhcm-media-btn"
                            data-input="<?= $input_id ?>"
                            data-img="<?= $prev_id ?>"><?= $has_img ? 'Change Image' : 'Select Image' ?></button>
                    <button type="button" class="button rhcm-media-remove"
                            data-input="<?= $input_id ?>"
                            data-img="<?= $prev_id ?>"
                            style="<?= $has_img ? '' : 'display:none' ?>">Remove</button>
                </div>
            </div>
            <?php endforeach; ?>
            </div>

            <p><button type="submit" class="button button-primary">Save Category Images</button></p>
        </form>
        </div>
        <?php
    }

    public function handle_save_category_images() {
        check_admin_referer( 'rhcm_save_category_images', 'rhcm_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised' );
        $raw    = (array) ( $_POST['images'] ?? [] );
        $clean  = [];
        foreach ( $raw as $key => $url ) {
            $clean[ sanitize_key( $key ) ] = esc_url_raw( $url );
        }
        RHCM_DB::save_category_images( $clean );
        wp_redirect( admin_url( 'admin.php?page=rhcm-category-images&notice=saved' ) );
        exit;
    }

    // ── Discounts ─────────────────────────────────────────────────────────────

    public function page_discounts() {
        $action = $_GET['action'] ?? 'list';
        $id     = (int) ( $_GET['id'] ?? 0 );
        $notice = $_GET['notice'] ?? '';
        if ( $action === 'edit' || $action === 'new' ) {
            $d = $id ? RHCM_DB::get_discount( $id ) : [];
            $this->render_discount_form( $d, $notice );
        } else {
            $this->render_discount_list( $notice );
        }
    }

    private function render_discount_list( string $notice ) {
        $discounts = RHCM_DB::get_discounts();
        ?>
        <div class="wrap rhcm-wrap">
        <h1 class="wp-heading-inline">Discount Codes</h1>
        <a href="<?= esc_url( admin_url('admin.php?page=rhcm-discounts&action=new') ) ?>" class="page-title-action">Add New</a>
        <?php if ( $notice === 'saved' ):   ?><div class="notice notice-success is-dismissible"><p>Discount code saved.</p></div><?php endif; ?>
        <?php if ( $notice === 'deleted' ): ?><div class="notice notice-success is-dismissible"><p>Discount code deleted.</p></div><?php endif; ?>

        <table class="wp-list-table widefat fixed striped rhcm-table" style="margin-top:16px">
            <thead><tr><th>Code</th><th>Description</th><th>Discount</th><th>Courses</th><th>Used</th><th>Expires</th><th>Active</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ( $discounts as $d ):
                $used = (int) $d['uses_count'];
                $limit = $d['uses_limit'] ? (int) $d['uses_limit'] : '&infin;';
                $course_names = '';
                if ( $d['course_ids'] ) {
                    global $wpdb;
                    $ids  = array_map('intval', explode(',', $d['course_ids']));
                    $ph   = implode(',', array_fill(0, count($ids), '%d'));
                    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT title FROM {$wpdb->prefix}rhcm_courses WHERE id IN ($ph)", ...$ids ), ARRAY_A );
                    $course_names = implode(', ', array_column($rows, 'title'));
                }
            ?>
            <tr>
                <td><strong style="font-family:monospace;font-size:.95rem"><?= esc_html( $d['code'] ) ?></strong></td>
                <td><?= esc_html( $d['description'] ) ?></td>
                <td>
                    <?php if ( $d['type'] === 'percent' ): ?>
                        <?= esc_html( $d['amount'] ) ?>% off
                    <?php else: ?>
                        &pound;<?= esc_html( number_format((float)$d['amount'], 2) ) ?> off
                    <?php endif; ?>
                    <?php if ( $d['min_order'] > 0 ): ?>
                        <span style="color:#6b7280;font-size:.78rem">(min &pound;<?= esc_html(number_format((float)$d['min_order'],2)) ?>)</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:.82rem"><?= $course_names ? esc_html($course_names) : '<span style="color:#6b7280">All courses</span>' ?></td>
                <td><?= $used ?> / <?= $limit ?></td>
                <td><?= $d['expires_at'] ? esc_html( date('j M Y', strtotime($d['expires_at'])) ) : '<span style="color:#6b7280">&mdash;</span>' ?></td>
                <td><?= $d['is_active'] ? '&#10003;' : '&mdash;' ?></td>
                <td>
                    <a href="<?= esc_url( admin_url('admin.php?page=rhcm-discounts&action=edit&id=' . $d['id']) ) ?>">Edit</a>
                    &nbsp;|&nbsp;
                    <a href="<?= esc_url( wp_nonce_url( admin_url('admin-post.php?action=rhcm_delete_discount&id=' . $d['id']), 'rhcm_delete_discount_' . $d['id'] ) ) ?>"
                       onclick="return confirm('Delete this discount code?')" style="color:#c0392b">Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if ( empty($discounts) ): ?><tr><td colspan="8">No discount codes yet. <a href="<?= esc_url( admin_url('admin.php?page=rhcm-discounts&action=new') ) ?>">Create one</a>.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    private function render_discount_form( array $d, string $notice ) {
        $id      = (int) ( $d['id'] ?? 0 );
        $courses = RHCM_DB::get_courses( ['is_active' => 1] );
        $linked  = $d['course_ids'] ? array_map('intval', explode(',', $d['course_ids'])) : [];
        ?>
        <div class="wrap rhcm-wrap">
        <h1><?= $id ? 'Edit Discount Code' : 'Add Discount Code' ?></h1>
        <a href="<?= esc_url( admin_url('admin.php?page=rhcm-discounts') ) ?>">&larr; Back to Discount Codes</a>
        <?php if ( $notice === 'saved' ): ?><div class="notice notice-success is-dismissible"><p>Discount code saved.</p></div><?php endif; ?>

        <form method="POST" action="<?= esc_url( admin_url('admin-post.php') ) ?>" class="rhcm-form">
            <?php wp_nonce_field( 'rhcm_save_discount', 'rhcm_nonce' ); ?>
            <input type="hidden" name="action" value="rhcm_save_discount">
            <input type="hidden" name="id" value="<?= (int) $id ?>">

            <div class="rhcm-form-grid">
                <div class="rhcm-field">
                    <label>Code *</label>
                    <input type="text" name="code" value="<?= esc_attr( $d['code'] ?? '' ) ?>" required
                           placeholder="e.g. SUMMER25" style="text-transform:uppercase;font-family:monospace;font-size:1rem">
                    <small style="color:#6b7280">Codes are case-insensitive and saved in uppercase.</small>
                </div>
                <div class="rhcm-field">
                    <label>Description <small>(shown to visitor when applied)</small></label>
                    <input type="text" name="description" value="<?= esc_attr( $d['description'] ?? '' ) ?>" placeholder="e.g. Summer 2025 discount">
                </div>
                <div class="rhcm-field">
                    <label>Discount Type</label>
                    <select name="type">
                        <option value="percent" <?= selected( $d['type'] ?? 'percent', 'percent', false ) ?>>Percentage (%)</option>
                        <option value="fixed"   <?= selected( $d['type'] ?? '',        'fixed',   false ) ?>>Fixed amount (&pound;)</option>
                    </select>
                </div>
                <div class="rhcm-field">
                    <label>Amount <small>(% or &pound; depending on type)</small></label>
                    <input type="number" name="amount" step="0.01" min="0" value="<?= esc_attr( $d['amount'] ?? '' ) ?>" required>
                </div>
                <div class="rhcm-field">
                    <label>Minimum Order (&pound;) <small>(0 = no minimum)</small></label>
                    <input type="number" name="min_order" step="0.01" min="0" value="<?= esc_attr( $d['min_order'] ?? '0' ) ?>">
                </div>
                <div class="rhcm-field">
                    <label>Usage Limit <small>(leave blank = unlimited)</small></label>
                    <input type="number" name="uses_limit" min="1" value="<?= esc_attr( $d['uses_limit'] ?? '' ) ?>" placeholder="e.g. 50">
                </div>
                <div class="rhcm-field">
                    <label>Expiry Date <small>(leave blank = never expires)</small></label>
                    <input type="date" name="expires_at" value="<?= esc_attr( $d['expires_at'] ?? '' ) ?>">
                </div>
                <div class="rhcm-field">
                    <label><input type="checkbox" name="is_active" value="1" <?= checked( $d['is_active'] ?? 1, 1, false ) ?>> Active</label>
                </div>
                <div class="rhcm-field rhcm-field-full">
                    <label>Applies to Courses <small>(tick specific courses, or leave all unticked to apply to every course)</small></label>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:6px;margin-top:6px">
                    <?php foreach ( $courses as $c ): ?>
                        <label style="display:flex;align-items:center;gap:8px;font-weight:400;font-size:.84rem;background:#f8f9fb;padding:7px 10px;border-radius:5px">
                            <input type="checkbox" name="course_ids[]" value="<?= (int) $c['id'] ?>" <?= in_array((int)$c['id'], $linked) ? 'checked' : '' ?>>
                            <?= esc_html( $c['icon'] . ' ' . $c['title'] ) ?>
                        </label>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <p><button type="submit" class="button button-primary">Save Discount Code</button></p>
        </form>
        </div>
        <?php
    }

    public function handle_save_discount() {
        check_admin_referer( 'rhcm_save_discount', 'rhcm_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised' );
        $id = RHCM_DB::save_discount( $_POST, (int) ( $_POST['id'] ?? 0 ) );
        wp_redirect( admin_url( 'admin.php?page=rhcm-discounts&action=edit&id=' . $id . '&notice=saved' ) );
        exit;
    }

    public function handle_delete_discount() {
        $id = (int) ( $_GET['id'] ?? 0 );
        check_admin_referer( 'rhcm_delete_discount_' . $id );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised' );
        RHCM_DB::delete_discount( $id );
        wp_redirect( admin_url( 'admin.php?page=rhcm-discounts&notice=deleted' ) );
        exit;
    }

    // ── Shortcodes & Help ─────────────────────────────────────────────────────

    public function page_settings() {
        $cats = RHCM_DB::category_labels();
        ?>
        <div class="wrap rhcm-wrap">
        <h1>Shortcodes &amp; Help</h1>
        <p style="color:#6b7280;margin-bottom:28px">
            Place these shortcodes in any WordPress page or post to display the booking system on your site.
        </p>

        <!-- ── [rhcm_schedule] ── -->
        <div class="rhcm-help-card">
            <div class="rhcm-help-card-header">
                <code class="rhcm-sc-code">[rhcm_schedule]</code>
                <span class="rhcm-help-badge">Calendar + Course List</span>
            </div>
            <p class="rhcm-help-desc">
                Displays a full monthly calendar with colour-coded session dots, category filter buttons, month navigation,
                and a grid of session cards below the calendar. Visitors can click a calendar day to jump to its sessions.
                Each card shows the course title, date, price, capacity bar, and an <strong>Add to Cart</strong> button.
            </p>

            <h4 class="rhcm-help-params-title">Parameters</h4>
            <table class="rhcm-help-table">
                <thead><tr><th>Parameter</th><th>Default</th><th>Description</th></tr></thead>
                <tbody>
                    <tr>
                        <td><code>category</code></td>
                        <td><code>all</code></td>
                        <td>Pre-filter the calendar to a single category on load. Visitors can still switch categories using the filter buttons. See category values below.</td>
                    </tr>
                </tbody>
            </table>

            <h4 class="rhcm-help-params-title">Examples</h4>
            <div class="rhcm-help-examples">
                <div class="rhcm-help-example">
                    <code>[rhcm_schedule]</code>
                    <span>Show all categories</span>
                </div>
                <div class="rhcm-help-example">
                    <code>[rhcm_schedule category="adult"]</code>
                    <span>Open with Sailing pre-selected</span>
                </div>
                <div class="rhcm-help-example">
                    <code>[rhcm_schedule category="junior"]</code>
                    <span>Open with Junior courses pre-selected</span>
                </div>
            </div>
        </div>

        <!-- ── [rhcm_course] ── -->
        <div class="rhcm-help-card">
            <div class="rhcm-help-card-header">
                <code class="rhcm-sc-code">[rhcm_course id="X"]</code>
                <span class="rhcm-help-badge">Single Course Cards</span>
            </div>
            <p class="rhcm-help-desc">
                Displays upcoming session cards for one specific course, styled identically to the cards on the schedule page.
                Each card shows the session date, price, capacity bar, course description and an <strong>Add to Cart</strong> button.
                Use this on a dedicated course page to let visitors book directly without browsing the full calendar.
            </p>

            <h4 class="rhcm-help-params-title">Parameters</h4>
            <table class="rhcm-help-table">
                <thead><tr><th>Parameter</th><th>Default</th><th>Required</th><th>Description</th></tr></thead>
                <tbody>
                    <tr>
                        <td><code>id</code></td>
                        <td>&mdash;</td>
                        <td><span class="rhcm-required">Yes</span></td>
                        <td>The numeric ID of the course. Find this in the <a href="<?= esc_url( admin_url('admin.php?page=rhcm-courses') ) ?>">Courses</a> list (shown in the edit URL as <code>id=X</code>).</td>
                    </tr>
                    <tr>
                        <td><code>limit</code></td>
                        <td><code>5</code></td>
                        <td>No</td>
                        <td>Maximum number of upcoming sessions to display.</td>
                    </tr>
                </tbody>
            </table>

            <h4 class="rhcm-help-params-title">Examples</h4>
            <div class="rhcm-help-examples">
                <div class="rhcm-help-example">
                    <code>[rhcm_course id="3"]</code>
                    <span>Show up to 5 upcoming sessions for course #3</span>
                </div>
                <div class="rhcm-help-example">
                    <code>[rhcm_course id="3" limit="3"]</code>
                    <span>Show only the next 3 sessions</span>
                </div>
            </div>

            <!-- Course ID quick reference -->
            <?php
            $courses = RHCM_DB::get_courses( [ 'is_active' => 1 ] );
            if ( $courses ):
            ?>
            <h4 class="rhcm-help-params-title">Your Course IDs</h4>
            <table class="rhcm-help-table">
                <thead><tr><th>ID</th><th>Course</th><th>Category</th><th>Shortcode</th></tr></thead>
                <tbody>
                <?php foreach ( $courses as $c ): ?>
                <tr>
                    <td><strong><?= (int) $c['id'] ?></strong></td>
                    <td><?= esc_html( $c['icon'] . ' ' . $c['title'] ) ?></td>
                    <td><?= esc_html( ucfirst( $c['category'] ) ) ?></td>
                    <td>
                        <code class="rhcm-copy-code" title="Click to copy">[rhcm_course id="<?= (int) $c['id'] ?>"]</code>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- ── [rhcm_course_card] ── -->
        <div class="rhcm-help-card">
            <div class="rhcm-help-card-header">
                <code class="rhcm-sc-code">[rhcm_course_card id="X"]</code>
                <span class="rhcm-help-badge">Course Overview Card</span>
            </div>
            <p class="rhcm-help-desc">
                Displays a single compact card for one course showing its title, price, duration, level, RYA certification,
                description, and a <strong>View Schedule &rarr;</strong> link that takes visitors to your bookings page.
                Use this on a homepage, course overview, or marketing page to highlight a specific course.
            </p>

            <h4 class="rhcm-help-params-title">Parameters</h4>
            <table class="rhcm-help-table">
                <thead><tr><th>Parameter</th><th>Default</th><th>Required</th><th>Description</th></tr></thead>
                <tbody>
                    <tr>
                        <td><code>id</code></td>
                        <td>&mdash;</td>
                        <td><span class="rhcm-required">Yes</span></td>
                        <td>The numeric ID of the course. See the Course IDs table above.</td>
                    </tr>
                    <tr>
                        <td><code>schedule_url</code></td>
                        <td><code>/schedule</code></td>
                        <td>No</td>
                        <td>The URL the "View Schedule" button links to. Set this to the page where you have placed <code>[rhcm_schedule]</code>.</td>
                    </tr>
                </tbody>
            </table>

            <h4 class="rhcm-help-params-title">Examples</h4>
            <div class="rhcm-help-examples">
                <div class="rhcm-help-example">
                    <code>[rhcm_course_card id="3"]</code>
                    <span>Show the overview card for course #3, linking to /schedule</span>
                </div>
                <div class="rhcm-help-example">
                    <code>[rhcm_course_card id="3" schedule_url="/book"]</code>
                    <span>Link the button to /book instead</span>
                </div>
            </div>

            <?php if ( $courses ): ?>
            <h4 class="rhcm-help-params-title">Your Course IDs</h4>
            <table class="rhcm-help-table">
                <thead><tr><th>ID</th><th>Course</th><th>Shortcode</th></tr></thead>
                <tbody>
                <?php foreach ( $courses as $c ): ?>
                <tr>
                    <td><strong><?= (int) $c['id'] ?></strong></td>
                    <td><?= esc_html( $c['icon'] . ' ' . $c['title'] ) ?></td>
                    <td><code class="rhcm-copy-code" title="Click to copy">[rhcm_course_card id="<?= (int) $c['id'] ?>"]</code></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- ── [rhcm_courses] ── -->
        <div class="rhcm-help-card">
            <div class="rhcm-help-card-header">
                <code class="rhcm-sc-code">[rhcm_courses]</code>
                <span class="rhcm-help-badge">Courses by Category</span>
            </div>
            <p class="rhcm-help-desc">
                Displays active courses as session-style cards (title, price, duration, level, description, <strong>View Schedule &rarr;</strong> button).
                Without <code>category</code>: shows all courses grouped under coloured category headings.
                With <code>category</code>: shows only that category's courses as a flat card grid — no heading.
            </p>
            <h4 class="rhcm-help-params-title">Parameters</h4>
            <table class="rhcm-help-table">
                <thead><tr><th>Parameter</th><th>Default</th><th>Description</th></tr></thead>
                <tbody>
                    <tr>
                        <td><code>category</code></td>
                        <td><em>all</em></td>
                        <td>Filter to a single category. Use the key values from the Category Reference table above.</td>
                    </tr>
                    <tr>
                        <td><code>schedule_url</code></td>
                        <td><code>/schedule</code></td>
                        <td>URL the "View Schedule" button links to on every card.</td>
                    </tr>
                </tbody>
            </table>
            <h4 class="rhcm-help-params-title">Examples</h4>
            <div class="rhcm-help-examples">
                <div class="rhcm-help-example">
                    <code class="rhcm-copy-code">[rhcm_courses]</code>
                    <span>All courses grouped by category</span>
                </div>
                <div class="rhcm-help-example">
                    <code class="rhcm-copy-code">[rhcm_courses category="wingsurfing"]</code>
                    <span>Only Wingsurfing courses, flat grid</span>
                </div>
                <div class="rhcm-help-example">
                    <code class="rhcm-copy-code">[rhcm_courses category="windsurfing" schedule_url="/book"]</code>
                    <span>Windsurfing courses, linking to /book</span>
                </div>
            </div>
        </div>

        <!-- ── [rhcm_tag] ── -->
        <div class="rhcm-help-card">
            <div class="rhcm-help-card-header">
                <code class="rhcm-sc-code">[rhcm_tag category="X"]</code>
                <span class="rhcm-help-badge">Category Badge</span>
            </div>
            <p class="rhcm-help-desc">
                Renders a small inline coloured pill badge showing the category name. Drop it anywhere in page content — headings, paragraphs, buttons — to label a course category.
            </p>
            <h4 class="rhcm-help-params-title">Parameters</h4>
            <table class="rhcm-help-table">
                <thead><tr><th>Parameter</th><th>Required</th><th>Description</th></tr></thead>
                <tbody>
                    <tr>
                        <td><code>category</code></td>
                        <td><span class="rhcm-required">Yes</span></td>
                        <td>The category key. See the Category Values reference below.</td>
                    </tr>
                </tbody>
            </table>
            <h4 class="rhcm-help-params-title">Quick Reference</h4>
            <div class="rhcm-help-examples" style="flex-wrap:wrap">
                <?php foreach ( $cats as $key => $label ):
                    $cat_colors = RHCM_DB::category_colors();
                    $bg = $cat_colors[ $key ] ?? '#0a2342';
                ?>
                <div class="rhcm-help-example" style="align-items:center">
                    <code class="rhcm-copy-code" title="Click to copy">[rhcm_tag category="<?= esc_attr( $key ) ?>"]</code>
                    <span style="background:<?= esc_attr($bg) ?>;color:#fff;font-size:.75rem;font-weight:700;padding:3px 12px;border-radius:100px;letter-spacing:.04em;text-transform:uppercase"><?= esc_html( $label ) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── [rhcm_memberships] ── -->
        <div class="rhcm-help-card">
            <div class="rhcm-help-card-header">
                <code class="rhcm-sc-code">[rhcm_memberships]</code>
                <span class="rhcm-help-badge">Membership Cards</span>
            </div>
            <p class="rhcm-help-desc">
                Displays all active membership options as a responsive grid of cards — matching the Queen Mary membership page layout.
                Each card shows the icon, name, tagline, price, frequency, bullet-point features list, and a <strong>Find Out More</strong> button.
                The card marked <em>Most Popular</em> is highlighted with a gold border and badge.
                Manage options under <a href="<?= esc_url( admin_url('admin.php?page=rhcm-memberships') ) ?>">Centre Management &rarr; Memberships</a>.
            </p>
            <h4 class="rhcm-help-params-title">Examples</h4>
            <div class="rhcm-help-examples">
                <div class="rhcm-help-example">
                    <code class="rhcm-copy-code">[rhcm_memberships]</code>
                    <span>Show all active membership options</span>
                </div>
            </div>
        </div>

        <!-- ── Category reference ── -->
        <div class="rhcm-help-card">
            <div class="rhcm-help-card-header">
                <span class="rhcm-sc-code" style="font-size:.95rem">Category Values</span>
                <span class="rhcm-help-badge">Reference</span>
            </div>
            <p class="rhcm-help-desc">Use these values in the <code>category</code> parameter of <code>[rhcm_schedule]</code>.</p>
            <table class="rhcm-help-table">
                <thead><tr><th>Value</th><th>Label shown on site</th></tr></thead>
                <tbody>
                    <tr><td><code>all</code></td><td>All Courses (default)</td></tr>
                    <?php foreach ( $cats as $key => $label ): ?>
                    <tr><td><code><?= esc_html( $key ) ?></code></td><td><?= esc_html( $label ) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- ── Cart behaviour ── -->
        <div class="rhcm-help-card">
            <div class="rhcm-help-card-header">
                <span class="rhcm-sc-code" style="font-size:.95rem">Basket &amp; Checkout</span>
                <span class="rhcm-help-badge">How it works</span>
            </div>
            <ul class="rhcm-help-list">
                <li>Visitors click <strong>Add to Cart</strong> on any session card to add it to their basket.</li>
                <li>A floating basket button (bottom-right of the page) shows the number of sessions added. Clicking it opens a slide-in basket panel.</li>
                <li>The basket panel lists all selected sessions with prices and a running total. Items can be removed individually or the basket can be cleared.</li>
                <li>Clicking <strong>Proceed to Checkout</strong> opens a form to enter participant details (name, email, phone, date of birth, emergency contact, special requirements). One set of details covers all sessions in the basket.</li>
                <li>On submission, one booking record is created per session. Confirmation emails are sent to the participant and to the site admin email.</li>
                <li>The basket clears automatically when the browser tab or window is closed.</li>
                <li>If a session is fully booked, visitors are added to the waiting list instead.</li>
            </ul>
        </div>

        </div><!-- .wrap -->

        <style>
        .rhcm-help-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,.1);
            margin-bottom: 24px;
            overflow: hidden;
        }
        .rhcm-help-card-header {
            background: #0a2342;
            color: #fff;
            padding: 16px 22px;
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .rhcm-sc-code {
            background: rgba(255,255,255,.15);
            color: #fff;
            padding: 5px 12px;
            border-radius: 5px;
            font-size: 1rem;
            font-family: monospace;
            letter-spacing: .02em;
        }
        .rhcm-help-badge {
            background: #c8a84b;
            color: #0a2342;
            border-radius: 20px;
            padding: 3px 12px;
            font-size: .75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .rhcm-help-desc {
            padding: 18px 22px 4px;
            color: #374151;
            font-size: .9rem;
            line-height: 1.6;
            margin: 0;
        }
        .rhcm-help-params-title {
            padding: 14px 22px 6px;
            color: #0a2342;
            font-size: .82rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin: 0;
            border-top: 1px solid #f0f0f0;
            margin-top: 14px;
        }
        .rhcm-help-table {
            width: calc(100% - 44px);
            margin: 8px 22px 18px;
            border-collapse: collapse;
            font-size: .84rem;
        }
        .rhcm-help-table th {
            background: #f8f9fb;
            text-align: left;
            padding: 8px 12px;
            color: #6b7280;
            font-weight: 600;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            border-bottom: 1px solid #e5e7eb;
        }
        .rhcm-help-table td {
            padding: 9px 12px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: top;
            color: #374151;
        }
        .rhcm-help-table tr:last-child td { border-bottom: none; }
        .rhcm-help-table code {
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: .82rem;
        }
        .rhcm-required {
            background: #fee2e2;
            color: #991b1b;
            border-radius: 4px;
            padding: 2px 8px;
            font-size: .75rem;
            font-weight: 600;
        }
        .rhcm-help-examples {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 8px 22px 18px;
        }
        .rhcm-help-example {
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: .84rem;
        }
        .rhcm-help-example code {
            background: #f3f4f6;
            padding: 5px 12px;
            border-radius: 5px;
            font-family: monospace;
            white-space: nowrap;
        }
        .rhcm-help-example span { color: #6b7280; }
        .rhcm-copy-code {
            cursor: pointer;
            background: #f3f4f6;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: .82rem;
            transition: background .15s;
        }
        .rhcm-copy-code:hover { background: #dbeafe; }
        .rhcm-copy-code.rhcm-copied { background: #dcfce7; color: #166534; }
        .rhcm-help-list {
            padding: 14px 22px 18px 38px;
            margin: 0;
            color: #374151;
            font-size: .88rem;
            line-height: 1.7;
        }
        </style>
        <script>
        document.querySelectorAll('.rhcm-copy-code').forEach(function(el) {
            el.addEventListener('click', function() {
                navigator.clipboard.writeText(el.textContent.trim()).then(function() {
                    el.classList.add('rhcm-copied');
                    var orig = el.textContent;
                    el.textContent = 'Copied!';
                    setTimeout(function() {
                        el.textContent = orig;
                        el.classList.remove('rhcm-copied');
                    }, 1800);
                });
            });
        });
        </script>
        <?php
    }

    public function handle_booking_status() {
        $bid = (int) ( $_POST['booking_id'] ?? 0 );
        check_admin_referer( 'rhcm_update_booking_' . $bid, 'rhcm_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised' );
        $status = in_array( $_POST['status'], ['confirmed','waiting','cancelled'] ) ? $_POST['status'] : 'confirmed';
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'rhcm_bookings', [ 'status' => $status ], [ 'id' => $bid ] );
        $redirect_session = (int) ( $_POST['redirect_session'] ?? 0 );
        $qs = $redirect_session ? '&session_id=' . $redirect_session : '';
        wp_redirect( admin_url( 'admin.php?page=rhcm-bookings' . $qs . '&notice=updated' ) );
        exit;
    }
}
