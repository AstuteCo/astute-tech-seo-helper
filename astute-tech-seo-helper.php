<?php
/*
Plugin Name: Astute Tech SEO Helper
Description: A plugin for updating image alt text and checking/updating meta descriptions.
Version: 1.5
Author: Astute Communications
*/

// Add the admin menu for the Alt Text Updater and Description Length Checker tabs
add_action('admin_menu', 'astute_tech_seo_helper_menu');
function astute_tech_seo_helper_menu() {
    add_menu_page(
        'Tech SEO Helper',
        'Tech SEO Helper',
        'manage_options',
        'astute-tech-seo-helper',
        'astute_tech_seo_helper_page',
        'dashicons-visibility',
        20
    );
}

// Tab: Bulk Alt Text Updater
function astute_tech_seo_helper_bulk_alt_updater() {
    ?>
    <form id="bulk-alt-form">
        <div class="bulk-alt-grid">
            <?php
            $args = array(
                'post_type'      => 'attachment',
                'post_mime_type' => 'image',
                'post_status'    => 'inherit',
                'posts_per_page' => -1,
                'meta_query'     => array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_wp_attachment_image_alt',
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key'     => '_wp_attachment_image_alt',
                        'value'   => '',
                        'compare' => '='
                    )
                )
            );

            $images = new WP_Query($args);

            if ($images->have_posts()) {
                while ($images->have_posts()) {
                    $images->the_post();
                    $image_id = get_the_ID();
                    $image_url = wp_get_attachment_url($image_id);
                    $image_filename = basename($image_url);

                    echo '<div class="bulk-alt-item">';
                    echo '<img src="' . esc_url($image_url) . '" class="thumbnail" data-large="' . esc_url($image_url) . '" />';
                    echo '<p class="filename">' . esc_html($image_filename) . '</p>';
                    echo '<input type="text" name="alt_text[' . $image_id . ']" placeholder="Enter alt text" />';
                    echo '</div>';
                }
                wp_reset_postdata();
            } else {
                echo '<p>No images without alt text found.</p>';
            }
            ?>
        </div>
        <button type="button" id="bulk-alt-save" class="button button-primary">Save Alt Text</button>
    </form>
    <?php
}

// Tab: Description Length Checker
function astute_tech_seo_helper_description_length_checker() {
    $description_field = '';
    $is_yoast = defined('WPSEO_VERSION');
    $is_rank_math = defined('RANK_MATH_VERSION');

    if ($is_yoast) {
        $description_field = '_yoast_wpseo_metadesc';
    } elseif ($is_rank_math) {
        $description_field = 'rank_math_description';
    }

    if (!$description_field) {
        echo '<p>Neither Yoast SEO nor Rank Math is installed. This feature requires one of these plugins.</p>';
        return;
    }

    // Define post types to exclude from the query
    $excluded_post_types = ['awards']; // Add post types to exclude here
    $all_post_types = array_merge(['post', 'page'], get_post_types(['public' => true, '_builtin' => false]));

    // Filter out excluded post types
    $post_types = array_diff($all_post_types, $excluded_post_types);

    // Query for posts with meta descriptions, ordered by ID
    $args = array(
        'post_type'      => $post_types,
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'orderby'        => 'ID', // Sort by ID
        'order'          => 'ASC'
    );

    $query = new WP_Query($args);

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>ID</th><th>Description</th><th>Description Length</th><th>New Description</th><th>New Description Length</th></tr></thead><tbody>';

    while ($query->have_posts()) {
        $query->the_post();
        $post_id = get_the_ID();

        // Retrieve the current meta description
        $current_description = get_post_meta($post_id, $description_field, true);
        $current_description_length = strlen($current_description);

        // Only display rows for descriptions that fall outside the ideal length range
        if ($current_description_length < 120 || $current_description_length > 160) {
            echo '<tr>';
            echo '<td><a href="' . esc_url(get_edit_post_link($post_id)) . '" target="_blank">' . esc_html($post_id) . '</a></td>';
            echo '<td>' . esc_html($current_description) . '</td>';
            echo '<td>' . esc_html($current_description_length) . '</td>';
            echo '<td><input type="text" class="new-description" name="new_description[' . esc_attr($post_id) . ']" data-post-id="' . esc_attr($post_id) . '" placeholder="Enter new description" /></td>';
            echo '<td><span class="new-description-length" data-post-id="' . esc_attr($post_id) . '">0</span></td>';
            echo '</tr>';
        }
    }
    wp_reset_postdata();

    echo '</tbody></table>';
    echo '<button type="button" id="description-save" class="button button-primary">Save Descriptions</button>';
}

// Tab for Titles
function astute_tech_seo_helper_title_checker() {
    // Ensure Yoast SEO is available
    if (!defined('WPSEO_VERSION') || !class_exists('WPSEO_Frontend')) {
        echo '<p>Yoast SEO is required for this feature.</p>';
        return;
    }

    // Define the post type to exclude
    $excluded_post_types = ['awards'];
    $all_post_types = array_merge(['post', 'page'], get_post_types(['public' => true, '_builtin' => false]));

    // Filter out the excluded post type
    $post_types = array_diff($all_post_types, $excluded_post_types);

    // Query for posts, ordered by ID
    $args = array(
        'post_type'      => $post_types,
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'orderby'        => 'ID',
        'order'          => 'ASC'
    );

    $query = new WP_Query($args);

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>ID</th><th>Rendered SEO Title</th><th>Title Length</th></tr></thead><tbody>';

    while ($query->have_posts()) {
        $query->the_post();
        $post_id = get_the_ID();

        // Retrieve the raw SEO title template from Yoast metadata
        $raw_title = get_post_meta($post_id, '_yoast_wpseo_title', true);

        // Use wp_title if no Yoast SEO title is set
        if (empty($raw_title)) {
            $raw_title = get_the_title($post_id);
        } else {
            // Use Yoast to replace template variables with actual values
            $raw_title = WPSEO_Frontend::get_instance()->wpseo_replace_vars($raw_title, get_post($post_id));
        }

        // Calculate the length of the rendered title
        $title_length = strlen($raw_title);

        // Only display rows for titles that fall outside the 50-60 character range
        if ($title_length < 50 || $title_length > 60) {
            echo '<tr>';
            echo '<td><a href="' . esc_url(get_edit_post_link($post_id)) . '" target="_blank">' . esc_html($post_id) . '</a></td>';
            echo '<td>' . esc_html($raw_title) . '</td>';
            echo '<td>' . esc_html($title_length) . '</td>';
            echo '</tr>';
        }
    }
    wp_reset_postdata();

    echo '</tbody></table>';
}





// AJAX handler to save new descriptions
add_action('wp_ajax_save_new_descriptions', 'save_new_descriptions');
function save_new_descriptions() {
    check_ajax_referer('astute_tech_seo_helper_nonce', 'nonce');

    if (!current_user_can('manage_options') || !isset($_POST['descriptions'])) {
        wp_send_json_error('Invalid request');
        return;
    }

    $description_field = defined('WPSEO_VERSION') ? '_yoast_wpseo_metadesc' : (defined('RANK_MATH_VERSION') ? 'rank_math_description' : '');
    if (!$description_field) {
        wp_send_json_error('No SEO plugin detected.');
        return;
    }

    foreach ($_POST['descriptions'] as $post_id => $new_description) {
        update_post_meta($post_id, $description_field, sanitize_text_field($new_description));
    }

    wp_send_json_success('Descriptions updated successfully.');
}

// Render the admin page with both tabs
function astute_tech_seo_helper_page() {
    ?>
    <div class="wrap">
        <h1>Tech SEO Helper</h1>
        
        <!-- Tab Navigation -->
        <h2 class="nav-tab-wrapper">
            <a href="#bulk-alt" class="nav-tab nav-tab-active" onclick="showTab(event, 'bulk-alt')">Bulk Alt Text Updater</a>
            <a href="#description-length" class="nav-tab" onclick="showTab(event, 'description-length')">Description Length Checker</a>
            <!--<a href="#title-checker" class="nav-tab" onclick="showTab(event, 'title-checker')">Title Checker</a>-->
        </h2>

        <!-- Tab Content -->
        <div id="bulk-alt" class="tab-content">
            <h2>Bulk Alt Text Updater</h2>
            <?php astute_tech_seo_helper_bulk_alt_updater(); ?>
        </div>

        <div id="description-length" class="tab-content" style="display:none;">
            <h2>Meta Description Length Checker</h2>
            <?php astute_tech_seo_helper_description_length_checker(); ?>
        </div>

        <div id="title-checker" class="tab-content" style="display:none;">
            <h2>Title Checker</h2>
            <?php astute_tech_seo_helper_title_checker(); ?>
        </div>
    </div>
    <?php
}


// Enqueue JavaScript for live calculation of new description length and save button functionality
add_action('admin_enqueue_scripts', 'astute_tech_seo_helper_scripts');
function astute_tech_seo_helper_scripts($hook) {
    if ($hook !== 'toplevel_page_astute-tech-seo-helper') {
        return;
    }

    wp_enqueue_script('astute-tech-seo-helper-js', plugin_dir_url(__FILE__) . 'astute-tech-seo-helper.js', ['jquery'], null, true);
    wp_localize_script('astute-tech-seo-helper-js', 'astuteTechSEOHelper', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('astute_tech_seo_helper_nonce')
    ]);
    wp_enqueue_style('astute-tech-seo-helper-css', plugin_dir_url(__FILE__) . 'style.css');
}
