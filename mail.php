<?php
/*
Plugin Name: Mail Form Handler
Description: A plugin to handle form submission, send email, and save data to the database.
Version: 1.3
Author: Nehaaajaiswall
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Add a menu item to the WordPress admin dashboard
function add_custom_menu_page() {
    add_menu_page(
        'Form Submissions',          // Page title
        'Form Submissions',          // Menu title
        'manage_options',            // Capability
        'form-submissions',          // Menu slug
        'display_form_submissions',  // Callback function
        'dashicons-forms',           // Icon URL
        6                            // Position
    );
}
add_action('admin_menu', 'add_custom_menu_page');

// Create table on plugin activation
function create_custom_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'form_submissions'; 
    $charset_collate = $wpdb->get_charset_collate();

    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            form_id INT(20),
            form_type VARCHAR(255) NOT NULL,
            submission_data LONGTEXT NOT NULL,
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
register_activation_hook(__FILE__, 'create_custom_table');

// Handle form submission
function handle_form_submission() {
    if (isset($_POST['action']) && $_POST['action'] === 'submit_custom_form') {
        if (check_admin_referer('custom_form_submit', '_wpnonce')) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'form_submissions';

            $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
            $form_type = isset($_POST['form_type']) ? sanitize_text_field($_POST['form_type']) : 'Unknown Form';

            $to = 'nj954385@gmail.com';
            $subject = 'New Form Submission';
            $body = '';

            $form_data = [];
            foreach ($_POST as $key => $value) {
                if (!in_array($key, ['_wpnonce', 'action', '_wp_http_referer', 'form_type', 'form_id'])) {
                    $sanitized_value = is_array($value) ? array_map('sanitize_text_field', $value) : sanitize_text_field($value);
                    $form_data[$key] = $sanitized_value;
                    $body .= ucfirst($key) . ': ' . $sanitized_value . "\n";
                }
            }

            $wpdb->insert(
                $table_name,
                [
                    'form_id' => $form_id,
                    'form_type' => $form_type,
                    'submission_data' => json_encode($form_data),
                ]
            );

            $headers = ['Content-Type: text/plain; charset=UTF-8'];

            if (wp_mail($to, $subject, $body, $headers)) {
                wp_send_json_success(['message' => 'Mail sent successfully.']);
            } else {
                wp_send_json_error(['message' => 'Failed to send email. Please check your mail server settings.']);
            }
        } else {
            wp_send_json_error(['message' => 'Nonce verification failed.']);
        }
    } else {
        wp_send_json_error(['message' => 'Invalid request.']);
    }
}
add_action('wp_ajax_submit_custom_form', 'handle_form_submission');
add_action('wp_ajax_nopriv_submit_custom_form', 'handle_form_submission');

// Shortcode to display the form
function custom_form_shortcode($atts, $content = null) {
    $atts = shortcode_atts([
        'type' => 'default',
        'form_id' => '0',
    ], $atts);
    ob_start(); ?>
<form id="customForm" method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
    <?php wp_nonce_field('custom_form_submit', '_wpnonce'); ?>
    <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url(wp_unslash($_SERVER['REQUEST_URI'])); ?>" />
    <input type="hidden" name="action" value="submit_custom_form" />
    <input type="hidden" name="form_type" value="<?php echo esc_attr($atts['type']); ?>">
    <input type="hidden" name="form_id" value="<?php echo esc_attr($atts['form_id']); ?>"> 
    <?php echo do_shortcode($content); ?>
</form>
<?php
    return ob_get_clean();
}
add_shortcode('custom_form', 'custom_form_shortcode');

// Display form submissions on the admin page
function display_form_submissions() {
    ?>
    <div class="wrap">
        <h1>Form Submissions</h1>
        <form id="submissionsFilter" method="post">
        <label for="sort_order">Sort order:</label>
            <select id="sort_order" name="sort_order">
                <option value="asc">Ascending</option>
                <option value="desc">Descending</option>
            </select>

            <label for="search">Search:</label>
            <input type="text" id="search" name="search" placeholder="Search...">
        </form>
        <div id="submissionsTable">
            <!-- AJAX loaded content will go here -->
        </div>
        <div id="pagination">
            <!-- Pagination will be loaded here -->
        </div>
    </div>

<script type="text/javascript">
  jQuery(document).ready(function($) {
        var currentPage = 1;
        var totalPages = 1;
        var deleteNonce = '<?php echo wp_create_nonce('delete_submission_nonce'); ?>';

        // Form submissions ko pehli baar load karna
        loadSubmissions(currentPage);

        // Sort By dropdown aur Sort Order dropdown ke liye change event listener
        $('#sort_by, #sort_order').on('change', function() {
            loadSubmissions(1); // Sorting aur order change karne par first page load kare
        });

        // Search input field ke liye input event listener
        $('#search').on('input', function() {
            loadSubmissions(1); // Search karte hi first page load kare
        });

        // Pagination ke liye event listener
        $(document).on('click', '.page-nav', function(e) {
            e.preventDefault();
            var page = $(this).data('page');
            loadSubmissions(page); // Specific page load kare
        });

        // Submissions ko load karne ke liye function
        function loadSubmissions(page) {
            var sortBy = $('#sort_by').val(); // User ne jo sort option choose kiya hai
            console.log("sortBy "+ sortBy);
            var sortOrder = $('#sort_order').val(); // User ne jo sort order choose kiya hai
            console.log("sortOrder "+ sortOrder);
            var searchQuery = $('#search').val(); // User ne jo search query input ki hai
            console.log("searchQuery "+ searchQuery);

            $.ajax({
                url: ajaxurl, // WordPress ka AJAX handler URL
                method: 'POST',
                data: {
                    action: 'load_form_submissions', // AJAX action
                    sort_by: sortBy,
                    sort_order: sortOrder, // Include sort order
                    search: searchQuery,
                    page: page,
                    _ajax_nonce: '<?php echo wp_create_nonce('load_form_submissions_nonce'); ?>' // Security nonce
                },
                success: function(response) {
                    console.log("response",response);
                    if (response.success) {
                        $('#submissionsTable').html(response.data.html); // Submissions data ko HTML me insert karna
                        $('#pagination').html(response.data.pagination); // Pagination links ko insert karna
                        currentPage = page; // Current page ko update karna
                        totalPages = response.data.total_pages; // Total pages ko update karna
                    } else {
                        alert(response.data.message); // Error handling
                    }
                }
            });
        }
        $('#applyFilter').on('click', function() {
            loadSubmissions(currentPage);
        });

        $(document).on('click', '.page-nav', function() {
            var page = $(this).data('page');
            if (page > 0 && page <= totalPages) {
                loadSubmissions(page);
            }
        });

        $(document).on('click', '.delete-button', function() {
            var submissionId = $(this).data('id');

            if (confirm('Are you sure you want to delete this record?')) {
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'delete_submission',
                        submission_id: submissionId,
                        _ajax_nonce: deleteNonce
                    },
                    success: function(response) {
                        if (response.success) {
                            loadSubmissions(currentPage);
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        alert('AJAX request failed: ' + error);
                    }
                });
            }
        });

        loadSubmissions(currentPage);
    });
</script>

    <?php
}

function load_form_submissions() {
    check_ajax_referer('load_form_submissions_nonce', '_ajax_nonce');

    global $wpdb;
    $table_name = $wpdb->prefix . 'form_submissions';

    $sort_by = isset($_POST['sort_by']) ? sanitize_text_field($_POST['sort_by']) : 'submitted_at';
    $sort_order = isset($_POST['sort_order']) ? sanitize_text_field($_POST['sort_order']) : 'desc'; // Default to descending
    $search_query = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $per_page = 20;
    $offset = ($page - 1) * $per_page;

    $query = "SELECT * FROM $table_name WHERE 1=1";

    if (is_numeric($search_query)) {
        $query .= $wpdb->prepare(" AND (form_id = %d OR id = %d)", intval($search_query), intval($search_query));
    } else {
        $search_query = '%' . $wpdb->esc_like($search_query) . '%';
        $query .= $wpdb->prepare(
            " AND (form_type LIKE %s 
            OR JSON_UNQUOTE(JSON_EXTRACT(submission_data, '$.first_name')) LIKE %s 
            OR JSON_UNQUOTE(JSON_EXTRACT(submission_data, '$.last_name')) LIKE %s 
            OR JSON_UNQUOTE(JSON_EXTRACT(submission_data, '$.email')) LIKE %s 
            OR JSON_UNQUOTE(JSON_EXTRACT(submission_data, '$.phone')) LIKE %s 
            OR submitted_at LIKE %s)",
            $search_query,
            $search_query,
            $search_query,
            $search_query,
            $search_query,
            $search_query
        );
    }

    $total_count = $wpdb->get_var("SELECT COUNT(*) FROM ($query) as subquery");
    $query .= " ORDER BY $sort_by $sort_order LIMIT $offset, $per_page";
    $results = $wpdb->get_results($query);

    ob_start();

    if ($results) {
        echo '<table class="widefat">';
        echo '<thead><tr><th>ID</th><th>Form type</th><th>First Name</th><th>Last Name</th><th>Email</th><th>Phone</th><th>Submitted At</th><th>Delete</th></tr></thead>';
        echo '<tbody>';

        foreach ($results as $row) {
            $data = json_decode($row->submission_data, true);
            echo '<tr>';
            echo '<td>' . esc_html($row->id) . '</td>';
            echo '<td>' . esc_html($row->form_type) . '</td>';
            echo '<td>' . esc_html($data['first_name'] ?? '') . '</td>';
            echo '<td>' . esc_html($data['last_name'] ?? '') . '</td>';
            echo '<td>' . esc_html($data['email'] ?? '') . '</td>';
            echo '<td>' . esc_html($data['phone'] ?? '') . '</td>';
            echo '<td>' . esc_html($row->submitted_at) . '</td>';
            echo '<td><button class="delete-button" data-id="' . esc_attr($row->id) . '">Delete</button></td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        $total_pages = ceil($total_count / $per_page);
        echo '<div class="pagination">';
        for ($i = 1; $i <= $total_pages; $i++) {
            echo '<a href="#" class="page-nav" data-page="' . $i . '">' . $i . '</a> ';
        }
        echo '</div>';
    } else {
        echo '<p>No submissions found.</p>';
    }

    $html = ob_get_clean();

    wp_send_json_success([
        'html' => $html,
        'total_pages' => $total_pages
    ]);
}
add_action('wp_ajax_load_form_submissions', 'load_form_submissions');


// Handle AJAX request for deleting a form submission
function delete_submission() {
    check_ajax_referer('delete_submission_nonce', '_ajax_nonce');

    if (isset($_POST['submission_id']) && is_numeric($_POST['submission_id'])) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'form_submissions';
        $submission_id = intval($_POST['submission_id']);

        $deleted = $wpdb->delete($table_name, ['id' => $submission_id]);

        if ($deleted) {
            wp_send_json_success(['message' => 'Record deleted successfully.']);
        } else {
            wp_send_json_error(['message' => 'Failed to delete record.']);
        }
    } else {
        wp_send_json_error(['message' => 'Invalid submission ID.']);
    }
}
add_action('wp_ajax_delete_submission', 'delete_submission');



