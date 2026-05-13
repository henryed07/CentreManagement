<?php
defined( 'ABSPATH' ) || exit;

class RHCM_Shortcodes {

    public function __construct() {
        add_shortcode( 'rhcm_schedule',     [ $this, 'render_schedule' ] );
        add_shortcode( 'rhcm_course',       [ $this, 'render_course' ] );
        add_shortcode( 'rhcm_course_card',  [ $this, 'render_course_card' ] );
        add_shortcode( 'rhcm_courses',      [ $this, 'render_courses' ] );
        add_shortcode( 'rhcm_tag',            [ $this, 'render_tag' ] );
        add_shortcode( 'rhcm_memberships',  [ $this, 'render_memberships' ] );
        add_shortcode( 'rhcm_mem_categories', [ $this, 'render_mem_cat_sc' ] );
        add_shortcode( 'rhcm_session',        [ $this, 'render_session_detail' ] );
        add_shortcode( 'rhcm_membership_join', [ $this, 'render_membership_join' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'init',              [ $this, 'handle_booking_post' ] );
        add_action( 'init',              [ $this, 'handle_join_post' ] );
        add_action( 'wp_ajax_rhcm_validate_discount',        [ $this, 'ajax_validate_discount' ] );
        add_action( 'wp_ajax_nopriv_rhcm_validate_discount', [ $this, 'ajax_validate_discount' ] );
    }

    public function enqueue() {
        global $post;
        if ( ! $post ) return;
        $shortcodes = [ 'rhcm_schedule', 'rhcm_course', 'rhcm_course_card', 'rhcm_courses', 'rhcm_tag', 'rhcm_memberships', 'rhcm_mem_categories', 'rhcm_session', 'rhcm_membership_join' ];
        $needs_assets = false;
        foreach ( $shortcodes as $sc ) {
            if ( has_shortcode( $post->post_content, $sc ) ) { $needs_assets = true; break; }
        }
        if ( $needs_assets ) {
            wp_enqueue_style(  'rhcm-frontend', RHCM_URL . 'assets/css/frontend.css', [], RHCM_VERSION );
            wp_enqueue_script( 'rhcm-frontend', RHCM_URL . 'assets/js/frontend.js',  [], RHCM_VERSION, true );
            wp_localize_script( 'rhcm-frontend', 'RHCM', [
                'pageUrl' => get_permalink( $post->ID ),
                'nonce'   => wp_create_nonce( 'rhcm_frontend' ),
            ] );
        }
    }

    // ── POST handler: dispatches single or cart checkout ─────────────────────

    public function handle_booking_post() {
        if ( ! isset( $_POST['rhcm_booking_submit'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['rhcm_nonce'] ?? '', 'rhcm_book_session' ) ) {
            wp_die( 'Security check failed.' );
        }

        $first = sanitize_text_field( $_POST['first_name'] ?? '' );
        $last  = sanitize_text_field( $_POST['last_name']  ?? '' );
        $email = sanitize_email( $_POST['email'] ?? '' );

        if ( ! $first || ! $last || ! is_email( $email ) ) {
            wp_safe_redirect( add_query_arg( [ 'rhcm_error' => 'validation' ], wp_get_referer() ) );
            exit;
        }

        // Cart checkout: array of session_ids
        if ( ! empty( $_POST['session_ids'] ) && is_array( $_POST['session_ids'] ) ) {
            $session_ids    = array_map( 'intval', $_POST['session_ids'] );
            $session_spaces = isset( $_POST['session_spaces'] ) ? array_map( 'intval', (array) $_POST['session_spaces'] ) : [];
            $refs  = [];
            $mixed = false;

            foreach ( $session_ids as $i => $sid ) {
                $spaces = max( 1, (int) ( $session_spaces[ $i ] ?? 1 ) );
                $data   = array_merge( $_POST, [ 'session_id' => $sid, 'spaces' => $spaces ] );
                $result = RHCM_DB::create_booking( $data );
                if ( ! is_wp_error( $result ) ) {
                    $refs[] = $result['ref'];
                    if ( $result['status'] !== 'confirmed' ) $mixed = true;
                    $this->send_confirmation_email( $result['ref'], $result['status'] );
                }
            }

            $return_url = get_permalink();
            wp_safe_redirect( add_query_arg( [
                'rhcm_confirmed' => 1,
                'refs'          => implode( ',', $refs ),
                'status'        => $mixed ? 'mixed' : 'confirmed',
            ], $return_url ) );
            exit;
        }

        // Single booking
        $session_id = (int) ( $_POST['session_id'] ?? 0 );
        if ( ! $session_id ) {
            wp_safe_redirect( add_query_arg( [ 'rhcm_error' => 'validation' ], wp_get_referer() ) );
            exit;
        }

        $single_data = array_merge( (array) $_POST, [ 'spaces' => max( 1, (int) ( $_POST['spaces'] ?? 1 ) ) ] );
        $result = RHCM_DB::create_booking( $single_data );

        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( add_query_arg( [ 'rhcm_error' => 'server' ], wp_get_referer() ) );
            exit;
        }

        $this->send_confirmation_email( $result['ref'], $result['status'] );

        wp_safe_redirect( add_query_arg( [
            'rhcm_confirmed' => 1,
            'refs'          => $result['ref'],
            'status'        => $result['status'],
        ], get_permalink() ) );
        exit;
    }

    private function send_confirmation_email( string $ref, string $status ) {
        $booking = RHCM_DB::get_booking_by_ref( $ref );
        if ( ! $booking ) return;

        $date = date( 'j F Y', strtotime( $booking['start_date'] ) );
        if ( $booking['start_date'] !== $booking['end_date'] )
            $date .= ' – ' . date( 'j F Y', strtotime( $booking['end_date'] ) );

        $subject = $status === 'confirmed'
            ? 'Booking Confirmed — ' . $booking['course_title']
            : 'Waiting List — ' . $booking['course_title'];

        $body  = "Dear {$booking['first_name']},\n\n";
        $body .= $status === 'confirmed' ? "Your booking is confirmed!\n\n" : "You've been added to the waiting list.\n\n";
        $body .= "Course: {$booking['course_title']}\nDate: $date\nRef: $ref\n\n" . get_bloginfo('name');

        wp_mail( $booking['email'], $subject, $body );
        wp_mail(
            get_option('admin_email'),
            'New Booking: ' . $booking['course_title'] . ' (' . $ref . ')',
            "Ref: $ref\nName: {$booking['first_name']} {$booking['last_name']}\nEmail: {$booking['email']}\nCourse: {$booking['course_title']}\nDate: $date\nStatus: $status"
        );
    }

    // ── [rhcm_schedule] ────────────────────────────────────────────────────────

    public function render_schedule( array $atts ) {
        $atts = shortcode_atts( [ 'category' => 'all' ], $atts );

        $year  = max( (int) date('Y'), (int) ( $_GET['rhcm_year']  ?? date('Y') ) );
        $month = (int) ( $_GET['rhcm_month'] ?? date('n') );
        $month = max( 1, min( 12, $month ) );
        $cat   = sanitize_text_field( $_GET['rhcm_cat'] ?? $atts['category'] );

        $firstDay    = mktime( 0, 0, 0, $month, 1, $year );
        $daysInMonth = (int) date( 't', $firstDay );
        $startDow    = (int) date( 'N', $firstDay );

        $prevMonth = $month === 1 ? 12 : $month - 1;
        $prevYear  = $month === 1 ? $year - 1 : $year;
        $nextMonth = $month === 12 ? 1  : $month + 1;
        $nextYear  = $month === 12 ? $year + 1 : $year;

        $sessions = RHCM_DB::get_sessions( [ 'year' => $year, 'month' => $month, 'category' => $cat ] );

        $byDay  = [];
        foreach ( $sessions as $s ) {
            $d = (int) date( 'j', strtotime( $s['start_date'] ) );
            $byDay[$d][] = $s;
        }

        $colors   = RHCM_DB::category_colors();
        $labels   = RHCM_DB::category_labels();
        $page_url = get_permalink();

        ob_start();

        // Confirmation / error notices
        if ( ! empty( $_GET['rhcm_confirmed'] ) ) {
            $status = $_GET['status'] ?? 'confirmed';
            $refs   = esc_html( $_GET['refs'] ?? '' );
            $count  = count( explode( ',', $refs ) );
            echo '<div class="rhcm-notice rhcm-notice-' . esc_attr( $status === 'confirmed' ? 'confirmed' : 'waiting' ) . '">';
            if ( $status === 'confirmed' ) {
                echo '<strong>' . ( $count > 1 ? $count . ' bookings confirmed!' : 'Booking confirmed!' ) . '</strong> Reference' . ( $count > 1 ? 's' : '' ) . ': <strong>' . $refs . '</strong>. Confirmation emails are on their way.';
            } else {
                echo '<strong>Added to waiting list.</strong> Reference' . ( $count > 1 ? 's' : '' ) . ': <strong>' . $refs . '</strong>. We\'ll be in touch if a space opens up.';
            }
            echo '</div>';
        }
        if ( ! empty( $_GET['rhcm_error'] ) ) {
            echo '<div class="rhcm-notice rhcm-notice-error">Please fill in all required fields and try again.</div>';
        }
        ?>
        <div class="rhcm-schedule">

        <!-- Category filter -->
        <div class="rhcm-filter-strip">
            <?php
            $all_cats = [ 'all' => 'All Courses' ] + $labels;
            foreach ( $all_cats as $key => $label ):
                $url    = add_query_arg( [ 'rhcm_year' => $year, 'rhcm_month' => $month, 'rhcm_cat' => $key ], $page_url );
                $active = $cat === $key ? ' rhcm-active' : '';
            ?>
            <a href="<?= esc_url( $url ) ?>" class="rhcm-filter-btn<?= $active ?>"><?= esc_html( $label ) ?></a>
            <?php endforeach; ?>
        </div>

        <!-- Month nav -->
        <div class="rhcm-cal-nav">
            <h2><?= esc_html( date( 'F Y', $firstDay ) ) ?></h2>
            <div class="rhcm-arrows">
                <a href="<?= esc_url( add_query_arg( [ 'rhcm_year' => $prevYear, 'rhcm_month' => $prevMonth, 'rhcm_cat' => $cat ], $page_url ) ) ?>">&larr; Prev</a>
                <a href="<?= esc_url( add_query_arg( [ 'rhcm_year' => date('Y'), 'rhcm_month' => date('n'), 'rhcm_cat' => $cat ], $page_url ) ) ?>">Today</a>
                <a href="<?= esc_url( add_query_arg( [ 'rhcm_year' => $nextYear, 'rhcm_month' => $nextMonth, 'rhcm_cat' => $cat ], $page_url ) ) ?>">Next &rarr;</a>
            </div>
        </div>

        <!-- Legend -->
        <div class="rhcm-legend">
        <?php foreach ( $labels as $key => $label ): ?>
            <div class="rhcm-legend-item">
                <span class="rhcm-legend-dot" style="background:<?= esc_attr( $colors[$key] ?? '#0a2342' ) ?>"></span>
                <span><?= esc_html( $label ) ?></span>
            </div>
        <?php endforeach; ?>
        </div>

        <!-- Calendar -->
        <div class="rhcm-cal-grid">
            <?php foreach ( ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $h ): ?>
            <div class="rhcm-cal-header"><?= $h ?></div>
            <?php endforeach; ?>

            <?php for ( $i = 1; $i < $startDow; $i++ ): ?>
            <div class="rhcm-cal-day rhcm-empty"></div>
            <?php endfor; ?>

            <?php
            $today = (int) date('j'); $thisM = (int) date('n'); $thisY = (int) date('Y');
            for ( $day = 1; $day <= $daysInMonth; $day++ ):
                $isToday = ( $day === $today && $month === $thisM && $year === $thisY );
                $shown   = array_slice( $byDay[$day] ?? [], 0, 5 );
                $extra   = count( $byDay[$day] ?? [] ) - 5;
                $cls     = 'rhcm-cal-day' . ( $isToday ? ' rhcm-today' : '' ) . ( ! empty( $byDay[$day] ) ? ' rhcm-has-sessions' : '' );
            ?>
            <div class="<?= $cls ?>" data-day="<?= $day ?>">
                <span class="rhcm-day-num"><?= $day ?></span>
                <div class="rhcm-cal-dots">
                <?php foreach ( $shown as $s ): ?>
                    <div class="rhcm-cal-dot" title="<?= esc_attr( $s['title'] ) ?>"
                         style="background:<?= esc_attr( $colors[$s['category']] ?? '#0a2342' ) ?>"></div>
                <?php endforeach; ?>
                <?php if ( $extra > 0 ): ?><span class="rhcm-cal-more">+<?= $extra ?></span><?php endif; ?>
                </div>
            </div>
            <?php endfor; ?>

            <?php
            $totalCells = $startDow - 1 + $daysInMonth;
            $remainder  = (7 - ($totalCells % 7)) % 7;
            for ( $i = 0; $i < $remainder; $i++ ): ?>
            <div class="rhcm-cal-day rhcm-empty"></div>
            <?php endfor; ?>
        </div>

        <!-- Session list -->
        <h3 class="rhcm-session-count">
            <?= count( $sessions ) ?> course<?= count( $sessions ) !== 1 ? 's' : '' ?> in <?= esc_html( date( 'F Y', $firstDay ) ) ?>
        </h3>

        <?php if ( empty( $sessions ) ): ?>
        <div class="rhcm-empty-month">
            <div class="rhcm-empty-icon">&#128197;</div>
            <h3>No courses this month</h3>
            <p>Try another month or category.</p>
            <a href="<?= esc_url( add_query_arg( [ 'rhcm_year' => $nextYear, 'rhcm_month' => $nextMonth, 'rhcm_cat' => $cat ], $page_url ) ) ?>"
               class="rhcm-btn rhcm-btn-primary">View Next Month</a>
        </div>
        <?php else: ?>
        <div class="rhcm-session-grid" id="rhcm-session-list">
        <?php foreach ( $sessions as $s ):
            $left     = (int) $s['total_spaces'] - (int) $s['enrolled'];
            $full     = $left <= 0;
            $pct      = min( 100, round( (int) $s['enrolled'] / max( 1, (int) $s['total_spaces'] ) * 100 ) );
            $bar_col  = $pct >= 100 ? '#c0392b' : ( $pct >= 75 ? '#e07b39' : '#2a7a4a' );
            $date_str = $s['start_date'] === $s['end_date']
                ? date( 'l j F Y', strtotime( $s['start_date'] ) )
                : date( 'j F', strtotime( $s['start_date'] ) ) . ' – ' . date( 'j F Y', strtotime( $s['end_date'] ) );
            $day_num  = (int) date( 'j', strtotime( $s['start_date'] ) );
            $border   = $colors[$s['category']] ?? '#0a2342';
            $s_img    = $s['image_url'] ?? '';
        ?>
        <div class="rhcm-session-card<?= $s_img ? ' rhcm-card-has-img' : '' ?>" id="rhcm-day-<?= $day_num ?>-<?= (int) $s['id'] ?>" style="border-top-color:<?= esc_attr( $border ) ?>">
            <?php if ( $s_img ): ?>
            <img class="rhcm-card-img" src="<?= esc_url( $s_img ) ?>" alt="<?= esc_attr( $s['title'] ) ?>">
            <div class="rhcm-card-content">
            <?php endif; ?>
            <div class="rhcm-sc-header">
                <h3><?= esc_html( $s['icon'] . ' ' . $s['title'] ) ?></h3>
                <span class="rhcm-price">&pound;<?= esc_html( number_format( (float) $s['price'], 0 ) ) ?></span>
            </div>
            <?php if ( $s['rya_cert'] ):
                $badge_style = ! empty( $s['req_badge_color'] ) ? ' style="border-color:' . esc_attr( $s['req_badge_color'] ) . ';color:' . esc_attr( $s['req_badge_color'] ) . '"' : ''; ?>
            <span class="rhcm-req-badge"<?= $badge_style ?>><strong>REQUIRES:</strong> <?= esc_html( $s['rya_cert'] ) ?></span>
            <?php endif; ?>
            <div class="rhcm-meta">
                <span>&#128197; <?= $date_str ?></span>
                <?php if ( $s['duration'] ): ?><span>&#9201; <?= esc_html( $s['duration'] ) ?></span><?php endif; ?>
                <?php if ( $s['level'] ):    ?><span><?= esc_html( $s['level'] ) ?></span><?php endif; ?>
                <?php if ( $s['total_spaces'] ): ?><span>&#128101; Max <?= (int) $s['total_spaces'] ?></span><?php endif; ?>
            </div>
            <?php if ( $s['description'] ): ?><p class="rhcm-desc"><?= esc_html( $s['description'] ) ?></p><?php endif; ?>
            <?php if ( $s['notes'] ): ?><p class="rhcm-session-note">&#8505; <?= esc_html( $s['notes'] ) ?></p><?php endif; ?>

            <div class="rhcm-cap-bar"><div class="rhcm-cap-fill" style="width:<?= $pct ?>%;background:<?= $bar_col ?>"></div></div>
            <p class="rhcm-cap-text">
            <?php if ( $full ): ?>
                &#128308; Fully booked &mdash; join the waiting list
            <?php elseif ( $left <= 2 ): ?>
                &#128992; Only <?= $left ?> place<?= $left !== 1 ? 's' : '' ?> left
            <?php else: ?>
                &#128994; <?= $left ?> of <?= (int) $s['total_spaces'] ?> places available
            <?php endif; ?>
            </p>

            <button class="rhcm-btn rhcm-btn-primary rhcm-btn-full rhcm-add-to-cart"
                    data-session="<?= (int) $s['id'] ?>"
                    data-title="<?= esc_attr( $s['icon'] . ' ' . $s['title'] ) ?>"
                    data-date="<?= esc_attr( $date_str ) ?>"
                    data-price="<?= esc_attr( number_format( (float) $s['price'], 2 ) ) ?>"
                    data-full="<?= $full ? '1' : '0' ?>">
                <?= $full ? 'Join Waiting List' : 'Add to Cart' ?>
            </button>
            <?php if ( $s_img ): ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

        </div><!-- .rhcm-schedule -->

        <?php
        echo $this->cart_html( $page_url );
        echo $this->checkout_modal_html( $page_url );

        return ob_get_clean();
    }

    // ── [rhcm_course id="X"] ───────────────────────────────────────────────────

    public function render_course( array $atts ) {
        $atts    = shortcode_atts( [ 'id' => 0, 'limit' => 5 ], $atts );
        $course  = RHCM_DB::get_course( (int) $atts['id'] );
        if ( ! $course || ! $course['is_active'] ) return '';

        $sessions = RHCM_DB::get_sessions( [ 'course_id' => (int) $atts['id'] ] );
        $today    = date('Y-m-d');
        $sessions = array_filter( $sessions, fn($s) => $s['end_date'] >= $today );
        $sessions = array_slice( array_values( $sessions ), 0, (int) $atts['limit'] );

        $page_url = get_permalink();
        $colors   = RHCM_DB::category_colors();

        ob_start();

        // Confirmation / error notices (same as schedule)
        if ( ! empty( $_GET['rhcm_confirmed'] ) ) {
            $status = $_GET['status'] ?? 'confirmed';
            $refs   = esc_html( $_GET['refs'] ?? '' );
            $count  = count( explode( ',', $refs ) );
            echo '<div class="rhcm-notice rhcm-notice-' . esc_attr( $status === 'confirmed' ? 'confirmed' : 'waiting' ) . '">';
            if ( $status === 'confirmed' ) {
                echo '<strong>' . ( $count > 1 ? $count . ' bookings confirmed!' : 'Booking confirmed!' ) . '</strong> Reference' . ( $count > 1 ? 's' : '' ) . ': <strong>' . $refs . '</strong>. Confirmation emails are on their way.';
            } else {
                echo '<strong>Added to waiting list.</strong> Reference' . ( $count > 1 ? 's' : '' ) . ': <strong>' . $refs . '</strong>. We\'ll be in touch if a space opens up.';
            }
            echo '</div>';
        }
        if ( ! empty( $_GET['rhcm_error'] ) ) {
            echo '<div class="rhcm-notice rhcm-notice-error">Please fill in all required fields and try again.</div>';
        }

        if ( ! empty( $course['image_url'] ) ) {
            echo '<img class="rhcm-course-img-header" src="' . esc_url( $course['image_url'] ) . '" alt="' . esc_attr( $course['title'] ) . '">';
        }

        if ( empty( $sessions ) ): ?>
        <div class="rhcm-empty-month">
            <div class="rhcm-empty-icon">&#128197;</div>
            <h3>No upcoming sessions</h3>
            <p>There are no scheduled sessions for this course yet. Check back soon.</p>
        </div>
        <?php else: ?>
        <div class="rhcm-session-grid">
        <?php foreach ( $sessions as $s ):
            $left     = (int) $s['total_spaces'] - (int) $s['enrolled'];
            $full     = $left <= 0;
            $pct      = min( 100, round( (int) $s['enrolled'] / max( 1, (int) $s['total_spaces'] ) * 100 ) );
            $bar_col  = $pct >= 100 ? '#c0392b' : ( $pct >= 75 ? '#e07b39' : '#2a7a4a' );
            $date_str = $s['start_date'] === $s['end_date']
                ? date( 'l j F Y', strtotime( $s['start_date'] ) )
                : date( 'j F', strtotime( $s['start_date'] ) ) . ' – ' . date( 'j F Y', strtotime( $s['end_date'] ) );
            $border   = $colors[$s['category']] ?? '#0a2342';
        ?>
        <div class="rhcm-session-card" style="border-top-color:<?= esc_attr( $border ) ?>">
            <div class="rhcm-sc-header">
                <h3><?= esc_html( $s['icon'] . ' ' . $s['title'] ) ?></h3>
                <span class="rhcm-price">&pound;<?= esc_html( number_format( (float) $s['price'], 0 ) ) ?></span>
            </div>
            <?php if ( $s['rya_cert'] ):
                $badge_style = ! empty( $s['req_badge_color'] ) ? ' style="border-color:' . esc_attr( $s['req_badge_color'] ) . ';color:' . esc_attr( $s['req_badge_color'] ) . '"' : ''; ?>
            <span class="rhcm-req-badge"<?= $badge_style ?>><strong>REQUIRES:</strong> <?= esc_html( $s['rya_cert'] ) ?></span>
            <?php endif; ?>
            <div class="rhcm-meta">
                <span>&#128197; <?= $date_str ?></span>
                <?php if ( $s['duration'] ): ?><span>&#9201; <?= esc_html( $s['duration'] ) ?></span><?php endif; ?>
                <?php if ( $s['level'] ):    ?><span><?= esc_html( $s['level'] ) ?></span><?php endif; ?>
                <?php if ( $s['total_spaces'] ): ?><span>&#128101; Max <?= (int) $s['total_spaces'] ?></span><?php endif; ?>
            </div>
            <?php if ( $s['description'] ): ?><p class="rhcm-desc"><?= esc_html( $s['description'] ) ?></p><?php endif; ?>
            <?php if ( $s['notes'] ): ?><p class="rhcm-session-note">&#8505; <?= esc_html( $s['notes'] ) ?></p><?php endif; ?>

            <div class="rhcm-cap-bar"><div class="rhcm-cap-fill" style="width:<?= $pct ?>%;background:<?= $bar_col ?>"></div></div>
            <p class="rhcm-cap-text">
            <?php if ( $full ): ?>
                &#128308; Fully booked &mdash; join the waiting list
            <?php elseif ( $left <= 2 ): ?>
                &#128992; Only <?= $left ?> place<?= $left !== 1 ? 's' : '' ?> left
            <?php else: ?>
                &#128994; <?= $left ?> of <?= (int) $s['total_spaces'] ?> places available
            <?php endif; ?>
            </p>

            <button class="rhcm-btn rhcm-btn-primary rhcm-btn-full rhcm-add-to-cart"
                    data-session="<?= (int) $s['id'] ?>"
                    data-title="<?= esc_attr( $s['icon'] . ' ' . $s['title'] ) ?>"
                    data-date="<?= esc_attr( $date_str ) ?>"
                    data-price="<?= esc_attr( number_format( (float) $s['price'], 2 ) ) ?>"
                    data-full="<?= $full ? '1' : '0' ?>">
                <?= $full ? 'Join Waiting List' : 'Add to Cart' ?>
            </button>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif;

        echo $this->cart_html( $page_url );
        echo $this->checkout_modal_html( $page_url );

        return ob_get_clean();
    }

    // ── [rhcm_course_card id="X" schedule_url="/schedule"] ───────────────────

    public function render_course_card( array $atts ) {
        $atts = shortcode_atts( [
            'id'           => 0,
            'schedule_url' => '/schedule',
        ], $atts );

        $course = RHCM_DB::get_course( (int) $atts['id'] );
        if ( ! $course || ! $course['is_active'] ) return '';

        $color      = RHCM_DB::category_colors()[$course['category']] ?? '#0a2342';
        $price      = (float) $course['price'];
        $icon       = $course['icon'] ? esc_html( $course['icon'] ) . ' ' : '';
        $course_img = $course['image_url'] ?? '';

        ob_start();
        ?>
        <div style="max-width:354px">
        <div class="rhcm-session-card<?= $course_img ? ' rhcm-card-has-img' : '' ?>" style="border-top-color:<?= esc_attr( $color ) ?>">
            <?php if ( $course_img ): ?>
            <img class="rhcm-card-img" src="<?= esc_url( $course_img ) ?>" alt="<?= esc_attr( $course['title'] ) ?>">
            <div class="rhcm-card-content">
            <?php endif; ?>
            <div class="rhcm-sc-header">
                <h3><?= $icon ?><?= esc_html( $course['title'] ) ?></h3>
                <?php if ( $price > 0 ): ?>
                <span class="rhcm-price">&pound;<?= number_format( $price, 0 ) ?></span>
                <?php endif; ?>
            </div>
            <?php if ( $course['rya_cert'] ):
                $badge_style = ! empty( $course['req_badge_color'] ) ? ' style="border-color:' . esc_attr( $course['req_badge_color'] ) . ';color:' . esc_attr( $course['req_badge_color'] ) . '"' : ''; ?>
            <span class="rhcm-req-badge"<?= $badge_style ?>><strong>REQUIRES:</strong> <?= esc_html( $course['rya_cert'] ) ?></span>
            <?php endif; ?>

            <div class="rhcm-meta">
                <?php if ( $course['duration'] ): ?><span>&#9201; <?= esc_html( $course['duration'] ) ?></span><?php endif; ?>
                <?php if ( $course['level'] ):    ?><span><?= esc_html( $course['level'] ) ?></span><?php endif; ?>
                <?php if ( $course['max_participants'] ): ?><span>&#128101; Max <?= (int) $course['max_participants'] ?></span><?php endif; ?>
            </div>

            <?php if ( $course['description'] ): ?>
            <p class="rhcm-desc"><?= esc_html( $course['description'] ) ?></p>
            <?php endif; ?>

            <a href="<?= esc_url( $atts['schedule_url'] ) ?>" class="rhcm-btn rhcm-btn-primary rhcm-btn-full">
                See Dates &amp; Book
            </a>
            <?php if ( $course_img ): ?></div><?php endif; ?>
        </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── [rhcm_courses category="" schedule_url="/schedule"] ──────────────────

    public function render_courses( array $atts ) {
        $atts = shortcode_atts( [
            'category'     => '',
            'schedule_url' => '/schedule',
        ], $atts );

        $query = [ 'is_active' => 1 ];
        if ( $atts['category'] ) $query['category'] = sanitize_text_field( $atts['category'] );

        $courses = RHCM_DB::get_courses( $query );
        if ( empty( $courses ) ) return '<p>No courses available yet.</p>';

        $colors    = RHCM_DB::category_colors();
        $labels    = RHCM_DB::category_labels();
        $cat_imgs  = RHCM_DB::get_category_images();

        // Group by category in label order; single-category skips headings
        $single_cat = ! empty( $atts['category'] );

        $grouped = [];
        foreach ( $labels as $key => $label ) $grouped[ $key ] = [];
        foreach ( $courses as $c ) {
            $cat = $c['category'];
            if ( ! isset( $grouped[ $cat ] ) ) $grouped[ $cat ] = [];
            $grouped[ $cat ][] = $c;
        }
        $grouped = array_filter( $grouped );

        ob_start();
        ?>
        <div class="rhcm-courses-wrap">
        <?php foreach ( $grouped as $cat_key => $cat_courses ):
            $label   = $labels[ $cat_key ] ?? ucfirst( str_replace( '_', ' ', $cat_key ) );
            $color   = $colors[ $cat_key ] ?? '#0a2342';
            $cat_img = $cat_imgs[ $cat_key ] ?? '';
        ?>
        <div class="rhcm-cat-section">

            <?php if ( ! $single_cat ): ?>
            <?php if ( $cat_img ): ?>
            <div class="rhcm-cat-banner" style="background-image:url('<?= esc_url( $cat_img ) ?>')">
                <div class="rhcm-cat-banner-overlay" style="border-left:4px solid <?= esc_attr( $color ) ?>">
                    <h3><?= esc_html( $label ) ?></h3>
                </div>
            </div>
            <?php else: ?>
            <div class="rhcm-cat-heading">
                <span class="rhcm-cat-dot" style="background:<?= esc_attr( $color ) ?>"></span>
                <h3><?= esc_html( $label ) ?></h3>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <div class="rhcm-session-grid">
            <?php foreach ( $cat_courses as $course ):
                $price      = (float) $course['price'];
                $icon       = $course['icon'] ? esc_html( $course['icon'] ) . ' ' : '';
                $desc       = esc_html( $course['description'] ?? '' );
                $course_img = $course['image_url'] ?? '';
            ?>
            <div class="rhcm-session-card<?= $course_img ? ' rhcm-card-has-img' : '' ?>" style="border-top-color:<?= esc_attr( $color ) ?>">
                <?php if ( $course_img ): ?>
                <img class="rhcm-card-img" src="<?= esc_url( $course_img ) ?>" alt="<?= esc_attr( $course['title'] ) ?>">
                <div class="rhcm-card-content">
                <?php endif; ?>
                <div class="rhcm-sc-header">
                    <h3><?= $icon ?><?= esc_html( $course['title'] ) ?></h3>
                    <?php if ( $price > 0 ): ?>
                    <span class="rhcm-price">&pound;<?= number_format( $price, 0 ) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ( $course['rya_cert'] ):
                    $badge_style = ! empty( $course['req_badge_color'] ) ? ' style="border-color:' . esc_attr( $course['req_badge_color'] ) . ';color:' . esc_attr( $course['req_badge_color'] ) . '"' : ''; ?>
                <span class="rhcm-req-badge"<?= $badge_style ?>><strong>REQUIRES:</strong> <?= esc_html( $course['rya_cert'] ) ?></span>
                <?php endif; ?>

                <div class="rhcm-meta">
                    <?php if ( $course['duration'] ): ?><span>&#9201; <?= esc_html( $course['duration'] ) ?></span><?php endif; ?>
                    <?php if ( $course['level'] ):    ?><span><?= esc_html( $course['level'] ) ?></span><?php endif; ?>
                    <?php if ( $course['max_participants'] ): ?><span>&#128101; Max <?= (int) $course['max_participants'] ?></span><?php endif; ?>
                </div>

                <?php if ( $desc ): ?>
                <p class="rhcm-desc"><?= $desc ?></p>
                <?php endif; ?>

                <a href="<?= esc_url( $atts['schedule_url'] ) ?>" class="rhcm-btn rhcm-btn-primary rhcm-btn-full">
                    See Dates &amp; Book
                </a>
                <?php if ( $course_img ): ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>

        </div>
        <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── AJAX: discount validation ─────────────────────────────────────────────

    public function ajax_validate_discount() {
        check_ajax_referer( 'rhcm_frontend', 'nonce' );
        $code        = sanitize_text_field( $_POST['code'] ?? '' );
        $session_ids = array_map( 'intval', (array) ( $_POST['session_ids'] ?? [] ) );
        wp_send_json( RHCM_DB::validate_discount( $code, $session_ids ) );
    }

    // ── [rhcm_tag category="X"] ───────────────────────────────────────────────

    public function render_tag( array $atts ) {
        $atts = shortcode_atts( [ 'category' => '' ], $atts );
        $cat  = sanitize_text_field( $atts['category'] );
        if ( ! $cat ) return '';

        $colors = RHCM_DB::category_colors();
        $labels = RHCM_DB::category_labels();

        if ( ! isset( $labels[ $cat ] ) ) return '';

        $label = $labels[ $cat ];
        $color = $colors[ $cat ] ?? '#0a2342';

        return '<span class="rhcm-cat-tag" style="background:' . esc_attr( $color ) . '">' . esc_html( $label ) . '</span>';
    }

    // ── [rhcm_mem_categories] ─────────────────────────────────────────────────

    public function render_mem_cat_sc( array $atts ) {
        $cats = RHCM_DB::get_mem_categories();
        if ( empty( $cats ) ) return '<p>No membership categories available yet.</p>';

        $active = array_filter( $cats, fn( $c ) => (int) ( $c['is_active'] ?? 1 ) !== 0 );
        uasort( $active, fn( $a, $b ) => (int) ( $a['sort_order'] ?? 0 ) <=> (int) ( $b['sort_order'] ?? 0 ) );

        if ( empty( $active ) ) return '<p>No membership categories available yet.</p>';

        ob_start();
        echo '<div class="rhcm-mem-grid rhcm-mem-cat-grid">';
        foreach ( $active as $key => $cat ) {
            echo $this->render_mem_card( [
                'name'       => $cat['label']     ?? $key,
                'icon'       => $cat['icon']      ?? '',
                'tagline'    => $cat['tagline']   ?? '',
                'price'      => $cat['price']     ?? '',
                'frequency'  => $cat['frequency'] ?? '',
                'details'    => $cat['details']   ?? '',
                'is_popular' => $cat['is_popular'] ?? 0,
                'info_url'   => $cat['info_url']  ?? '',
            ] );
        }
        echo '</div>';
        return ob_get_clean();
    }

    // ── [rhcm_memberships category=""] ────────────────────────────────────────

    public function render_memberships( array $atts ) {
        $atts       = shortcode_atts( [ 'category' => '', 'layout' => 'tiles' ], $atts );
        $filter_cat = sanitize_text_field( $atts['category'] );
        $layout     = sanitize_text_field( $atts['layout'] );

        $memberships = RHCM_DB::get_memberships( true );
        if ( empty( $memberships ) ) return '<p>No membership options available yet.</p>';

        if ( $filter_cat ) {
            $memberships = array_values( array_filter( $memberships, fn($m) => ( $m['mem_category'] ?? '' ) === $filter_cat ) );
            if ( empty( $memberships ) ) return '<p>No membership options available in this category.</p>';
        }

        ob_start();
        if ( $layout === 'tiles' ) {
            echo '<div class="rhcm-mem-tile-grid">';
            foreach ( $memberships as $m ) echo $this->render_mem_tile( $m );
            echo '</div>';
        } else {
            echo '<div class="rhcm-mem-grid">';
            foreach ( $memberships as $m ) echo $this->render_mem_card( $m );
            echo '</div>';
        }
        return ob_get_clean();
    }

    private function render_mem_tile( array $m ): string {
        $price   = trim( $m['price'] ?? '' );
        $freq    = trim( $m['frequency'] ?? '' );
        $tagline = trim( $m['tagline'] ?? '' );
        $url     = ! empty( $m['info_url'] ) ? esc_url( $m['info_url'] ) : '#';

        ob_start();
        ?>
        <div class="rhcm-mem-tile">
            <div class="rhcm-mem-tile-header">
                <?php if ( $price ): ?>
                <div class="rhcm-mem-tile-price-wrap">
                    <span class="rhcm-mem-tile-price"><?= esc_html( $price ) ?></span><?php
                    if ( $freq ): ?><span class="rhcm-mem-tile-freq"><?= esc_html( $freq ) ?></span><?php endif; ?>
                </div>
                <?php else: ?>
                <span class="rhcm-mem-contact-pill">Contact us for price</span>
                <?php endif; ?>
            </div>
            <div class="rhcm-mem-tile-body">
                <div class="rhcm-mem-tile-name"><?= esc_html( $m['name'] ) ?></div>
                <?php if ( $tagline ): ?>
                <p class="rhcm-mem-tile-desc"><?= esc_html( $tagline ) ?></p>
                <?php endif; ?>
            </div>
            <div class="rhcm-mem-tile-footer">
                <a href="<?= $url ?>" class="rhcm-mem-tile-btn">Join Now &rarr;</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_mem_card( array $m ): string {
        $featured = (int) ( $m['is_popular'] ?? 0 );
        $lines    = array_filter( array_map( 'trim', explode( "\n", $m['details'] ?? '' ) ) );
        $price    = trim( $m['price'] ?? '' );
        $freq     = trim( $m['frequency'] ?? '' );
        $icon     = trim( $m['icon'] ?? '' );
        $tagline  = trim( $m['tagline'] ?? '' );

        ob_start();
        ?>
        <div class="rhcm-mem-card<?= $featured ? ' rhcm-mem-featured' : '' ?>">
            <div class="rhcm-mem-price-header">
                <?php if ( $featured ): ?>
                <span class="rhcm-featured-badge">Most Popular</span>
                <?php endif; ?>
                <?php if ( $icon ): ?>
                <span class="rhcm-mem-icon"><?= esc_html( $icon ) ?></span>
                <?php endif; ?>
                <div class="rhcm-mem-name"><?= esc_html( $m['name'] ) ?></div>
                <?php if ( $tagline ): ?>
                <div class="rhcm-mem-tagline"><?= esc_html( $tagline ) ?></div>
                <?php endif; ?>
                <?php if ( $price ): ?>
                <span class="rhcm-mem-price"><?= esc_html( $price ) ?></span>
                <?php if ( $freq ): ?><span class="rhcm-mem-freq-inline"><?= esc_html( $freq ) ?></span><?php endif; ?>
                <?php else: ?>
                <span class="rhcm-mem-contact-pill">Contact us for price</span>
                <?php endif; ?>
            </div>
            <div class="rhcm-mem-body">
                <?php if ( ! empty( $lines ) ): ?>
                <ul class="rhcm-mem-features">
                    <?php foreach ( $lines as $line ): ?><li><?= esc_html( $line ) ?></li><?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <a href="<?= $m['info_url'] ? esc_url( $m['info_url'] ) : '#' ?>" class="rhcm-mem-btn">Find Out More &rarr;</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── [rhcm_session id="X"] ─────────────────────────────────────────────────

    public function render_session_detail( array $atts ) {
        $atts = shortcode_atts( [ 'id' => 0 ], $atts );
        $s    = RHCM_DB::get_session( (int) $atts['id'] );
        if ( ! $s ) return '<p class="rhcm-notice rhcm-notice-error">Session not found.</p>';

        $left     = (int) $s['total_spaces'] - (int) $s['enrolled'];
        $full     = $left <= 0;
        $date_str = $s['start_date'] === $s['end_date']
            ? date( 'l j F Y', strtotime( $s['start_date'] ) )
            : date( 'j F', strtotime( $s['start_date'] ) ) . ' – ' . date( 'j F Y', strtotime( $s['end_date'] ) );
        $page_url = get_permalink();
        $price    = (float) $s['price'];
        $max_sel  = $full ? 1 : min( $left, 20 );
        $colors   = RHCM_DB::category_colors();
        $border   = $colors[ $s['category'] ] ?? '#0a2342';
        $icon     = $s['icon'] ? esc_html( $s['icon'] ) . ' ' : '';

        ob_start();

        if ( ! empty( $_GET['rhcm_confirmed'] ) ) {
            $status = $_GET['status'] ?? 'confirmed';
            $refs   = esc_html( $_GET['refs'] ?? '' );
            $cls    = $status === 'confirmed' ? 'confirmed' : 'waiting';
            echo '<div class="rhcm-notice rhcm-notice-' . esc_attr( $cls ) . '">';
            if ( $status === 'confirmed' ) {
                echo '<strong>Booking confirmed!</strong> Reference: <strong>' . $refs . '</strong>. Confirmation email on its way.';
            } else {
                echo '<strong>Added to waiting list.</strong> Reference: <strong>' . $refs . '</strong>. We\'ll be in touch if a space opens up.';
            }
            echo '</div>';
        }
        if ( ! empty( $_GET['rhcm_error'] ) ) {
            echo '<div class="rhcm-notice rhcm-notice-error">Please fill in all required fields and try again.</div>';
        }
        ?>
        <div class="rhcm-session-detail">

            <div class="rhcm-sd-left">
                <?php if ( ! empty( $s['image_url'] ) ): ?>
                <img class="rhcm-sd-img" src="<?= esc_url( $s['image_url'] ) ?>" alt="<?= esc_attr( $s['title'] ) ?>">
                <?php endif; ?>

                <h2 class="rhcm-sd-title"><?= $icon ?><?= esc_html( $s['title'] ) ?></h2>

                <table class="rhcm-sd-table">
                    <tr><th>Date</th><td><?= esc_html( $date_str ) ?></td></tr>
                    <?php if ( $s['duration'] ): ?>
                    <tr><th>Duration</th><td><?= esc_html( $s['duration'] ) ?></td></tr>
                    <?php endif; ?>
                    <?php if ( $s['level'] ): ?>
                    <tr><th>Level</th><td><?= esc_html( $s['level'] ) ?></td></tr>
                    <?php endif; ?>
                    <?php if ( $s['rya_cert'] ): ?>
                    <tr><th>Requires</th><td><span class="rhcm-req-badge"><strong>REQUIRES:</strong> <?= esc_html( $s['rya_cert'] ) ?></span></td></tr>
                    <?php endif; ?>
                    <tr>
                        <th>Availability</th>
                        <td>
                            <?php if ( $full ): ?>
                            <span style="color:var(--rhcm-red)">&#128308; Fully booked</span>
                            <?php elseif ( $left <= 3 ): ?>
                            <span style="color:var(--rhcm-amber)">&#128992; Only <?= $left ?> place<?= $left !== 1 ? 's' : '' ?> left</span>
                            <?php else: ?>
                            <span style="color:var(--rhcm-green)">&#128994; <?= $left ?> of <?= (int) $s['total_spaces'] ?> places available</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ( $s['notes'] ): ?>
                    <tr><th>Notes</th><td><?= esc_html( $s['notes'] ) ?></td></tr>
                    <?php endif; ?>
                </table>

                <?php if ( $s['description'] ): ?>
                <div class="rhcm-sd-desc">
                    <h4>About this course</h4>
                    <p><?= esc_html( $s['description'] ) ?></p>
                </div>
                <?php endif; ?>
            </div>

            <div class="rhcm-sd-right">
                <div class="rhcm-sd-order" style="border-top-color:<?= esc_attr( $border ) ?>">
                    <h3>Your Order</h3>
                    <div class="rhcm-sd-order-name"><?= $icon ?><?= esc_html( $s['title'] ) ?></div>
                    <div class="rhcm-sd-order-date">&#128197; <?= esc_html( $date_str ) ?></div>

                    <div class="rhcm-sd-price-row">
                        <span>Price per person</span>
                        <strong>&pound;<?= number_format( $price, 0 ) ?></strong>
                    </div>

                    <div class="rhcm-spaces-row">
                        <label for="rhcm-spaces-<?= (int) $s['id'] ?>">Spaces</label>
                        <div class="rhcm-spaces-stepper">
                            <button type="button" class="rhcm-spaces-minus" aria-label="Decrease">&#8722;</button>
                            <input type="number" id="rhcm-spaces-<?= (int) $s['id'] ?>"
                                   class="rhcm-spaces-input"
                                   value="1" min="1" max="<?= $max_sel ?>"
                                   data-price="<?= esc_attr( number_format( $price, 2 ) ) ?>">
                            <button type="button" class="rhcm-spaces-plus" aria-label="Increase">&#43;</button>
                        </div>
                    </div>

                    <div class="rhcm-sd-subtotal-row">
                        <span>Subtotal</span>
                        <strong class="rhcm-sd-subtotal">&pound;<?= number_format( $price, 2 ) ?></strong>
                    </div>

                    <button class="rhcm-btn rhcm-btn-primary rhcm-btn-full rhcm-add-to-cart"
                            style="margin-top:16px"
                            data-session="<?= (int) $s['id'] ?>"
                            data-title="<?= esc_attr( $icon . $s['title'] ) ?>"
                            data-date="<?= esc_attr( $date_str ) ?>"
                            data-price="<?= esc_attr( number_format( $price, 2 ) ) ?>"
                            data-spaces="1"
                            data-full="<?= $full ? '1' : '0' ?>">
                        <?= $full ? 'Join Waiting List' : 'Add to Cart' ?>
                    </button>
                    <p class="rhcm-sd-cart-note">Payment arranged after checkout.</p>
                </div>
            </div>

        </div>
        <?php

        echo $this->cart_html( $page_url );
        echo $this->checkout_modal_html( $page_url );

        return ob_get_clean();
    }

    // ── [rhcm_membership_join] ────────────────────────────────────────────────

    public function handle_join_post() {
        if ( ! isset( $_POST['rhcm_join_submit'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['rhcm_join_nonce'] ?? '', 'rhcm_membership_join' ) ) {
            wp_die( 'Security check failed.' );
        }

        $first         = sanitize_text_field( $_POST['first_name'] ?? '' );
        $last          = sanitize_text_field( $_POST['last_name']  ?? '' );
        $email         = sanitize_email( $_POST['email'] ?? '' );
        $membership_id = (int) ( $_POST['category_key'] ?? 0 );

        if ( ! $first || ! $last || ! is_email( $email ) || ! $membership_id ) {
            wp_safe_redirect( add_query_arg( [ 'rhcm_join_error' => 'validation' ], wp_get_referer() ) );
            exit;
        }

        $membership = RHCM_DB::get_membership( $membership_id );
        if ( ! $membership ) {
            wp_safe_redirect( add_query_arg( [ 'rhcm_join_error' => 'validation' ], wp_get_referer() ) );
            exit;
        }
        $cat      = $membership['mem_category'];
        $cat_name = $membership['name'];

        $bolt_ids = [];
        if ( ! empty( $_POST['bolt_ons'] ) && is_array( $_POST['bolt_ons'] ) ) {
            $bolt_ids = array_map( 'intval', $_POST['bolt_ons'] );
        }

        // Validate Direct Debit fields when Paysuite is enabled
        $dd_enabled = RHCM_Paysuite::is_enabled();
        $dd_fields  = [];
        if ( $dd_enabled ) {
            $dd_fields = [
                'title'          => sanitize_text_field( $_POST['title']          ?? '' ),
                'address_line1'  => sanitize_text_field( $_POST['address_line1']  ?? '' ),
                'address_line2'  => sanitize_text_field( $_POST['address_line2']  ?? '' ),
                'postcode'       => strtoupper( sanitize_text_field( $_POST['postcode'] ?? '' ) ),
                'account_holder' => sanitize_text_field( $_POST['account_holder'] ?? '' ),
                'sort_code'      => preg_replace( '/\D/', '', $_POST['sort_code']      ?? '' ),
                'account_number' => preg_replace( '/\D/', '', $_POST['account_number'] ?? '' ),
                'dd_confirm'     => isset( $_POST['dd_confirm'] ),
            ];
            if (
                ! $dd_fields['address_line1'] ||
                ! $dd_fields['postcode'] ||
                ! $dd_fields['account_holder'] ||
                strlen( $dd_fields['sort_code'] )      !== 6 ||
                strlen( $dd_fields['account_number'] ) !== 8 ||
                ! $dd_fields['dd_confirm']
            ) {
                wp_safe_redirect( add_query_arg( [ 'rhcm_join_error' => 'dd_validation' ], wp_get_referer() ) );
                exit;
            }
        }

        // Create application record
        $result = RHCM_DB::create_application( [
            'category_key'  => $cat,
            'category_name' => $cat_name,
            'bolt_on_ids'   => implode( ',', $bolt_ids ),
            'first_name'    => $first,
            'last_name'     => $last,
            'email'         => $email,
            'phone'         => sanitize_text_field( $_POST['phone'] ?? '' ),
            'notes'         => sanitize_textarea_field( $_POST['notes'] ?? '' ),
            'address_line1' => $dd_fields['address_line1'] ?? '',
            'address_line2' => $dd_fields['address_line2'] ?? '',
            'postcode'      => $dd_fields['postcode']      ?? '',
        ] );
        $ref    = $result['ref'];
        $app_id = $result['id'];

        // Set up Direct Debit via Paysuite
        $dd_note = '';
        if ( $dd_enabled ) {
            try {
                $paysuite        = new RHCM_Paysuite();
                $customer_resp   = $paysuite->createCustomer(
                    RHCM_Paysuite::buildCustomerPayload( $ref, array_merge( $dd_fields, [
                        'first_name' => $first,
                        'last_name'  => $last,
                        'email'      => $email,
                    ] ) )
                );
                $paysuite_ref = $customer_resp['CustomerRef'] ?? $ref;
                $paysuite_id  = $customer_resp['Id'] ?? '';

                $monthly_price = RHCM_Paysuite::parsePrice( $membership['price'] ?? '' );
                if ( $monthly_price > 0 && $paysuite_id ) {
                    $paysuite->createContract(
                        $paysuite_id,
                        RHCM_Paysuite::buildContractPayload( $cat_name, $monthly_price )
                    );
                    RHCM_DB::update_application_dd( $app_id, $paysuite_ref, $paysuite_id, 'active' );
                    $dd_note = "\nDirect Debit: set up successfully (£" . number_format( $monthly_price, 2 ) . '/month)';
                } else {
                    RHCM_DB::update_application_dd( $app_id, $paysuite_ref, $paysuite_id, 'pending' );
                    $dd_note = "\nDirect Debit: customer created; contract needs manual setup (price POA)";
                }
            } catch ( \Throwable $e ) {
                RHCM_DB::update_application_dd( $app_id, '', '', 'error' );
                $dd_note = "\nDirect Debit: FAILED — " . $e->getMessage();
            }
        }

        // Notify admin
        $bolt_on_names = '';
        if ( $bolt_ids ) {
            $names = array_filter( array_map( function( $bid ) {
                $b = RHCM_DB::get_bolt_on( $bid );
                return $b ? $b['name'] : null;
            }, $bolt_ids ) );
            $bolt_on_names = implode( ', ', $names );
        }
        wp_mail(
            get_option( 'admin_email' ),
            'New Membership Application — ' . $ref,
            "Ref: $ref\nName: $first $last\nEmail: $email\nCategory: $cat_name\nBolt-ons: " . ( $bolt_on_names ?: 'None' ) . $dd_note
        );
        wp_mail(
            $email,
            'Membership Application Received — ' . get_bloginfo( 'name' ),
            "Hi $first,\n\nThank you for your membership application!\n\nRef: $ref\nMembership: $cat_name" . ( $bolt_on_names ? "\nBolt-ons: $bolt_on_names" : '' ) . "\n\nWe'll be in touch shortly.\n\n" . get_bloginfo( 'name' )
        );

        wp_safe_redirect( add_query_arg( [ 'rhcm_join_done' => 1, 'ref' => $ref ], wp_get_referer() ) );
        exit;
    }

    public function render_membership_join( array $atts ) {
        $atts       = shortcode_atts( [ 'category' => '' ], $atts );
        $filter_cat = sanitize_text_field( $atts['category'] );

        $memberships = RHCM_DB::get_memberships( true );
        if ( $filter_cat ) {
            $memberships = array_values( array_filter( $memberships, fn($m) => ( $m['mem_category'] ?? '' ) === $filter_cat ) );
        }

        $bolt_ons = RHCM_DB::get_bolt_ons( true );
        $page_url = get_permalink();

        ob_start();

        // Completed confirmation
        if ( ! empty( $_GET['rhcm_join_done'] ) ) {
            $ref = esc_html( $_GET['ref'] ?? '' );
            ?>
            <div class="rhcm-join-confirmed">
                <div class="rhcm-join-confirmed-icon">&#10003;</div>
                <h2>Application Received!</h2>
                <p>Thank you — your membership application has been submitted. Reference: <strong><?= $ref ?></strong>.</p>
                <p>We'll be in touch by email shortly to confirm your membership and arrange payment.</p>
            </div>
            <?php
            return ob_get_clean();
        }

        if ( ! empty( $_GET['rhcm_join_error'] ) ) {
            $err_msg = ( $_GET['rhcm_join_error'] === 'dd_validation' )
                ? 'Please complete all Direct Debit fields and confirm the instruction before submitting.'
                : 'Please complete all required fields and try again.';
            echo '<div class="rhcm-notice rhcm-notice-error">' . esc_html( $err_msg ) . '</div>';
        }

        $dd_enabled = RHCM_Paysuite::is_enabled();
        ?>
        <div class="rhcm-join" id="rhcm-join">

            <!-- Progress stepper -->
            <div class="rhcm-join-stepper">
                <div class="rhcm-join-step rhcm-join-step-active" data-step="1">
                    <div class="rhcm-join-step-circle">1</div>
                    <div class="rhcm-join-step-label">Membership</div>
                </div>
                <div class="rhcm-join-connector"></div>
                <div class="rhcm-join-step" data-step="2">
                    <div class="rhcm-join-step-circle">2</div>
                    <div class="rhcm-join-step-label">Add-ons</div>
                </div>
                <div class="rhcm-join-connector"></div>
                <div class="rhcm-join-step" data-step="3">
                    <div class="rhcm-join-step-circle">3</div>
                    <div class="rhcm-join-step-label">Your Details</div>
                </div>
                <?php if ( $dd_enabled ): ?>
                <div class="rhcm-join-connector"></div>
                <div class="rhcm-join-step" data-step="4">
                    <div class="rhcm-join-step-circle">4</div>
                    <div class="rhcm-join-step-label">Direct Debit</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Step 1: Choose Membership -->
            <div class="rhcm-join-panel" id="rhcm-join-panel-1">
                <h2 class="rhcm-join-heading">Choose Membership</h2>
                <p class="rhcm-join-subheading">Choose the membership category that best suits you. Membership runs 1 April &ndash; 31 March.</p>

                <div class="rhcm-join-section-label">Membership Category</div>
                <div class="rhcm-join-option-grid" id="rhcm-join-option-grid">
                <?php foreach ( $memberships as $m ):
                    $m_price = trim( $m['price'] ?? '' );
                    $m_freq  = trim( $m['frequency'] ?? '' );
                ?>
                    <div class="rhcm-join-option-card" data-key="<?= (int) $m['id'] ?>" data-name="<?= esc_attr( $m['name'] ) ?>" data-price="<?= esc_attr( $m_price ) ?>" data-annual="<?= esc_attr( RHCM_Paysuite::parsePrice( $m_price ) ) ?>">
                        <div class="rhcm-join-option-header">
                            <span class="rhcm-join-option-name"><?= esc_html( $m['name'] ) ?></span>
                            <span class="rhcm-join-option-price"><?= esc_html( $m_price ?: 'POA' ) ?><?php if ( $m_freq ): ?><small><?= esc_html( $m_freq ) ?></small><?php endif; ?></span>
                        </div>
                        <div class="rhcm-join-option-body">
                            <?php if ( $m['tagline'] ): ?><p><?= esc_html( $m['tagline'] ) ?></p><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ( empty( $memberships ) ): ?>
                    <p style="color:#6b7280">No membership options found. Add them under <strong>Centre Management &rarr; Memberships</strong>.</p>
                <?php endif; ?>
                </div>

                <button type="button" class="rhcm-btn rhcm-btn-primary rhcm-btn-full rhcm-join-continue" id="rhcm-join-next-1" disabled>
                    Continue &rarr;
                </button>
            </div>

            <!-- Step 2: Add-ons -->
            <div class="rhcm-join-panel" id="rhcm-join-panel-2" style="display:none">
                <h2 class="rhcm-join-heading">Add-ons</h2>
                <p class="rhcm-join-subheading">Enhance your membership with optional extras. All add-ons are optional.</p>

                <?php if ( empty( $bolt_ons ) ): ?>
                <p style="color:#6b7280;padding:20px 0">No add-ons available at this time.</p>
                <?php else: ?>
                <div class="rhcm-join-bolt-ons">
                    <?php foreach ( $bolt_ons as $b ): ?>
                    <label class="rhcm-join-bolt-on" data-id="<?= (int) $b['id'] ?>" data-name="<?= esc_attr( $b['name'] ) ?>" data-price="<?= esc_attr( $b['price'] ?? '' ) ?>">
                        <input type="checkbox" class="rhcm-join-bolt-checkbox" value="<?= (int) $b['id'] ?>">
                        <div class="rhcm-join-bolt-info">
                            <strong class="rhcm-join-bolt-name"><?= esc_html( $b['name'] ) ?></strong>
                            <?php if ( $b['description'] ): ?>
                            <span class="rhcm-join-bolt-desc"><?= esc_html( $b['description'] ) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="rhcm-join-bolt-price">
                            <?php if ( $b['price'] ): ?>
                            <span><?= esc_html( $b['price'] ) ?></span>
                            <?php if ( $b['frequency'] ): ?><small><?= esc_html( $b['frequency'] ) ?></small><?php endif; ?>
                            <?php else: ?><span>POA</span><?php endif; ?>
                        </div>
                        <div class="rhcm-join-bolt-check">&#10003;</div>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="rhcm-join-nav">
                    <button type="button" class="rhcm-btn rhcm-btn-outline rhcm-join-back" data-to="1">&larr; Back</button>
                    <button type="button" class="rhcm-btn rhcm-btn-primary rhcm-join-continue" id="rhcm-join-next-2">Continue &rarr;</button>
                </div>
            </div>

            <!-- Step 3: Your Details (+ step 4 DD when enabled, wrapped in one form) -->
            <?php if ( $dd_enabled ): ?>
            <form id="rhcm-join-form" method="POST" action="<?= esc_url( $page_url ) ?>">
                <?php wp_nonce_field( 'rhcm_membership_join', 'rhcm_join_nonce' ); ?>
                <input type="hidden" name="rhcm_join_submit" value="1">
                <input type="hidden" name="category_key" id="rhcm-join-field-cat" value="">
                <div id="rhcm-join-bolt-inputs"></div>
            <?php endif; ?>

            <div class="rhcm-join-panel" id="rhcm-join-panel-3" style="display:none">
                <h2 class="rhcm-join-heading"><?= $dd_enabled ? 'Your Details' : 'Review &amp; Submit' ?></h2>
                <p class="rhcm-join-subheading">Check your selection and fill in your details.</p>

                <!-- Summary -->
                <div class="rhcm-join-summary">
                    <div class="rhcm-join-summary-row rhcm-join-summary-main">
                        <span class="rhcm-join-summary-label">Membership</span>
                        <span class="rhcm-join-summary-value" id="rhcm-join-summary-cat">&mdash;</span>
                    </div>
                    <div class="rhcm-join-summary-row rhcm-join-summary-price-row">
                        <span class="rhcm-join-summary-label">Category Price</span>
                        <span class="rhcm-join-summary-value" id="rhcm-join-summary-price">&mdash;</span>
                    </div>
                    <div id="rhcm-join-summary-bolt-rows"></div>
                </div>

                <?php if ( ! $dd_enabled ): ?>
                <form id="rhcm-join-form" method="POST" action="<?= esc_url( $page_url ) ?>">
                    <?php wp_nonce_field( 'rhcm_membership_join', 'rhcm_join_nonce' ); ?>
                    <input type="hidden" name="rhcm_join_submit" value="1">
                    <input type="hidden" name="category_key" id="rhcm-join-field-cat" value="">
                    <div id="rhcm-join-bolt-inputs"></div>
                <?php endif; ?>

                    <h4 class="rhcm-section-title">Your Details</h4>

                    <div class="rhcm-form-row">
                        <div class="rhcm-field">
                            <label for="rhcm-join-first">First Name *</label>
                            <input type="text" id="rhcm-join-first" name="first_name" required autocomplete="given-name">
                        </div>
                        <div class="rhcm-field">
                            <label for="rhcm-join-last">Last Name *</label>
                            <input type="text" id="rhcm-join-last" name="last_name" required autocomplete="family-name">
                        </div>
                    </div>
                    <div class="rhcm-form-row">
                        <div class="rhcm-field">
                            <label for="rhcm-join-email">Email Address *</label>
                            <input type="email" id="rhcm-join-email" name="email" required autocomplete="email">
                        </div>
                        <div class="rhcm-field">
                            <label for="rhcm-join-phone">Phone</label>
                            <input type="tel" id="rhcm-join-phone" name="phone" autocomplete="tel" placeholder="07700 900123">
                        </div>
                    </div>
                    <div class="rhcm-field">
                        <label for="rhcm-join-notes">Notes <span class="rhcm-label-hint">(optional)</span></label>
                        <textarea id="rhcm-join-notes" name="notes" rows="3" placeholder="Any additional information…"></textarea>
                    </div>

                    <div class="rhcm-join-nav">
                        <button type="button" class="rhcm-btn rhcm-btn-outline rhcm-join-back" data-to="2">&larr; Back</button>
                        <?php if ( $dd_enabled ): ?>
                        <button type="button" class="rhcm-btn rhcm-btn-primary rhcm-join-continue">Continue &rarr;</button>
                        <?php else: ?>
                        <button type="submit" class="rhcm-btn rhcm-btn-primary">Submit Application &rarr;</button>
                        <?php endif; ?>
                    </div>

                <?php if ( ! $dd_enabled ): ?>
                </form>
                <?php endif; ?>
            </div><!-- #rhcm-join-panel-3 -->

            <?php if ( $dd_enabled ): ?>
            <!-- Step 4: Direct Debit -->
            <div class="rhcm-join-panel" id="rhcm-join-panel-4" style="display:none">
                <h2 class="rhcm-join-heading">Set Up Direct Debit</h2>
                <p class="rhcm-join-subheading">Your membership is paid by monthly Direct Debit via Access Paysuite. First payment on the 21st of the month.</p>

                <div class="rhcm-join-dd-monthly" id="rhcm-join-dd-monthly" style="background:#f0f7ff;border:1px solid #b8d8f8;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:.88rem;color:#1a3a6a">
                    Monthly amount: <strong id="rhcm-join-monthly-amount">&mdash;</strong>
                    <span style="color:#6b7280;font-size:.8rem">&nbsp;&bull;&nbsp;collected on the 21st each month</span>
                </div>

                <h4 class="rhcm-section-title">Home Address</h4>
                <div class="rhcm-field">
                    <label for="rhcm-join-addr1">Address Line 1 *</label>
                    <input type="text" id="rhcm-join-addr1" name="address_line1" required autocomplete="address-line1" placeholder="123 High Street">
                </div>
                <div class="rhcm-field">
                    <label for="rhcm-join-addr2">Address Line 2 <span class="rhcm-label-hint">(optional)</span></label>
                    <input type="text" id="rhcm-join-addr2" name="address_line2" autocomplete="address-line2">
                </div>
                <div class="rhcm-field" style="max-width:180px">
                    <label for="rhcm-join-postcode">Postcode *</label>
                    <input type="text" id="rhcm-join-postcode" name="postcode" required autocomplete="postal-code" placeholder="TW19 5BW" style="text-transform:uppercase">
                </div>

                <h4 class="rhcm-section-title">Bank Account Details</h4>
                <p style="color:#6b7280;font-size:.84rem;margin:0 0 14px">Your bank details are transmitted securely to Access Paysuite and are never stored on our servers.</p>
                <div class="rhcm-field">
                    <label for="rhcm-join-account-holder">Account Holder Name *</label>
                    <input type="text" id="rhcm-join-account-holder" name="account_holder" required autocomplete="name" placeholder="As it appears on your bank statement">
                </div>
                <div class="rhcm-form-row">
                    <div class="rhcm-field">
                        <label for="rhcm-join-sort-code">Sort Code *</label>
                        <input type="text" id="rhcm-join-sort-code" name="sort_code" required placeholder="12-34-56" maxlength="8" inputmode="numeric">
                    </div>
                    <div class="rhcm-field">
                        <label for="rhcm-join-account-number">Account Number *</label>
                        <input type="text" id="rhcm-join-account-number" name="account_number" required placeholder="12345678" maxlength="8" inputmode="numeric">
                    </div>
                </div>

                <h4 class="rhcm-section-title">Direct Debit Guarantee</h4>
                <div style="background:#f0f7ff;border:1px solid #b8d8f8;border-radius:8px;padding:14px 18px;font-size:.82rem;color:#1a3a6a;line-height:1.65;margin-bottom:16px">
                    <strong style="display:block;margin-bottom:8px;font-size:.88rem">&#9873; The Direct Debit Guarantee</strong>
                    <ul style="padding-left:18px;margin:0">
                        <li>This Guarantee is offered by all banks and building societies that accept instructions to pay Direct Debits.</li>
                        <li>If there are any changes to the amount, date or frequency of your Direct Debit, Queen Mary Sailing Club will notify you 10 working days in advance.</li>
                        <li>If an error is made in the payment of your Direct Debit you are entitled to a full and immediate refund from your bank or building society.</li>
                        <li>You can cancel a Direct Debit at any time by contacting your bank. Please also notify us.</li>
                    </ul>
                </div>
                <div class="rhcm-field">
                    <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;font-weight:400">
                        <input type="checkbox" name="dd_confirm" value="1" id="rhcm-join-dd-confirm"
                               style="margin-top:3px;flex-shrink:0;accent-color:#0a2342;width:17px;height:17px">
                        <span style="font-size:.86rem;color:#374151">
                            I confirm that I am the account holder and the only person required to authorise debits from this account.
                            I instruct Queen Mary Sailing Club to set up a Direct Debit on this account and understand I have rights under the Direct Debit Guarantee.
                        </span>
                    </label>
                </div>

                <div class="rhcm-join-nav">
                    <button type="button" class="rhcm-btn rhcm-btn-outline rhcm-join-back" data-to="3">&larr; Back</button>
                    <button type="submit" class="rhcm-btn rhcm-btn-primary">&#10003; Set Up Direct Debit &amp; Join</button>
                </div>
            </div><!-- #rhcm-join-panel-4 -->
            </form>
            <?php endif; ?>

        </div><!-- .rhcm-join -->
        <?php
        return ob_get_clean();
    }

    // ── Cart HTML (floating button + drawer) ──────────────────────────────────

    private function cart_html( string $page_url ): string {
        ob_start();
        ?>
        <!-- Cart drawer backdrop -->
        <div id="rhcm-cart-backdrop" class="rhcm-cart-backdrop" style="display:none"></div>

        <!-- Cart drawer -->
        <div id="rhcm-cart-drawer" class="rhcm-cart-drawer" aria-label="Booking basket">
            <div class="rhcm-cart-drawer-header">
                <h3>&#128722; Your Basket</h3>
                <button id="rhcm-cart-close" aria-label="Close basket">&times;</button>
            </div>
            <div id="rhcm-cart-items" class="rhcm-cart-items">
                <p class="rhcm-cart-empty-msg">Your basket is empty.</p>
            </div>
            <div class="rhcm-cart-drawer-footer">
                <div class="rhcm-cart-total-row">
                    <span>Total</span>
                    <strong id="rhcm-cart-total">&pound;0.00</strong>
                </div>
                <button id="rhcm-cart-checkout-btn" class="rhcm-btn rhcm-btn-primary rhcm-btn-full" disabled>
                    Proceed to Checkout
                </button>
                <button id="rhcm-cart-clear" class="rhcm-cart-clear-link">Clear basket</button>
            </div>
        </div>

        <!-- Floating cart button -->
        <div id="rhcm-cart-fab" class="rhcm-cart-fab" style="display:none" role="button" tabindex="0" aria-label="Open basket">
            <span class="rhcm-cart-fab-icon">&#128722;</span>
            <span id="rhcm-cart-badge" class="rhcm-cart-badge">0</span>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── Checkout modal HTML ───────────────────────────────────────────────────

    private function checkout_modal_html( string $page_url ): string {
        ob_start();
        ?>
        <div id="rhcm-modal" class="rhcm-modal" role="dialog" aria-modal="true" aria-labelledby="rhcm-modal-title" style="display:none">
            <div class="rhcm-modal-backdrop"></div>
            <div class="rhcm-modal-box">
                <button class="rhcm-modal-close" aria-label="Close">&times;</button>

                <!-- Basket summary (populated by JS) -->
                <div class="rhcm-modal-summary">
                    <h2 id="rhcm-modal-title">Your Booking</h2>
                    <div id="rhcm-modal-items" class="rhcm-modal-item-list"></div>
                    <div class="rhcm-modal-total-row">
                        <span>Total</span>
                        <strong id="rhcm-modal-total"></strong>
                    </div>
                </div>

                <form id="rhcm-book-form" method="POST" action="<?= esc_url( $page_url ) ?>">
                    <?php wp_nonce_field( 'rhcm_book_session', 'rhcm_nonce' ); ?>
                    <input type="hidden" name="rhcm_booking_submit" value="1">
                    <!-- session_ids[] inputs injected by JS -->
                    <div id="rhcm-session-inputs"></div>

                    <h4 class="rhcm-section-title">Your Details</h4>

                    <div class="rhcm-form-row">
                        <div class="rhcm-field">
                            <label for="rhcm-first-name">First Name *</label>
                            <input type="text" id="rhcm-first-name" name="first_name" required autocomplete="given-name">
                        </div>
                        <div class="rhcm-field">
                            <label for="rhcm-last-name">Last Name *</label>
                            <input type="text" id="rhcm-last-name" name="last_name" required autocomplete="family-name">
                        </div>
                    </div>
                    <div class="rhcm-form-row">
                        <div class="rhcm-field">
                            <label for="rhcm-email">Email Address *</label>
                            <input type="email" id="rhcm-email" name="email" required autocomplete="email">
                        </div>
                        <div class="rhcm-field">
                            <label for="rhcm-phone">Phone</label>
                            <input type="tel" id="rhcm-phone" name="phone" autocomplete="tel" placeholder="07700 900123">
                        </div>
                    </div>
                    <div class="rhcm-field">
                        <label for="rhcm-dob">Date of Birth <span class="rhcm-label-hint">(required for under 18s)</span></label>
                        <input type="date" id="rhcm-dob" name="dob" style="max-width:220px">
                    </div>

                    <h4 class="rhcm-section-title">Emergency Contact</h4>
                    <div class="rhcm-form-row">
                        <div class="rhcm-field">
                            <label for="rhcm-emg-name">Contact Name</label>
                            <input type="text" id="rhcm-emg-name" name="emg_name" placeholder="Full name">
                        </div>
                        <div class="rhcm-field">
                            <label for="rhcm-emg-phone">Contact Phone</label>
                            <input type="tel" id="rhcm-emg-phone" name="emg_phone" placeholder="07700 900456">
                        </div>
                    </div>

                    <div class="rhcm-field">
                        <label for="rhcm-notes">Special Requirements <span class="rhcm-label-hint">(optional)</span></label>
                        <textarea id="rhcm-notes" name="notes" rows="3"
                            placeholder="Dietary requirements, access needs, medical conditions..."></textarea>
                    </div>

                    <!-- Discount code -->
                    <div class="rhcm-discount-row">
                        <input type="text" id="rhcm-discount-input" placeholder="Discount code" autocomplete="off" style="text-transform:uppercase">
                        <button type="button" id="rhcm-discount-apply" class="rhcm-btn rhcm-btn-outline">Apply</button>
                    </div>
                    <div id="rhcm-discount-msg" class="rhcm-discount-msg" style="display:none"></div>
                    <input type="hidden" name="discount_code" id="rhcm-discount-code-val" value="">
                    <input type="hidden" name="discount_amount" id="rhcm-discount-amount-val" value="0">

                    <div id="rhcm-waiting-notice" class="rhcm-waiting-notice" style="display:none">
                        &#9888; One or more sessions are full &mdash; you will be added to the waiting list.
                    </div>

                    <div class="rhcm-modal-actions">
                        <button type="submit" class="rhcm-btn rhcm-btn-primary rhcm-btn-full" id="rhcm-submit-btn">
                            &#10003; Confirm Booking
                        </button>
                        <p class="rhcm-payment-note">Payment arranged after confirmation.</p>
                    </div>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
