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

                    if (!$image_url) {
                        echo '<p>Error retrieving URL for image ID ' . $image_id . '</p>';
                        continue;
                    }

                    // Initialize post usage array
                    $post_ids = [];

                    // Search `post_content` for inline usage
                    global $wpdb;
                    $inline_posts = $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post', 'page') AND post_status = 'publish' AND post_content LIKE %s",
                            '%' . $wpdb->esc_like($image_url) . '%'
                        )
                    );

                    if ($inline_posts) {
                        $post_ids = array_merge($post_ids, $inline_posts);
                    }

                    // Search `wp_postmeta` for custom field references
                    $meta_posts = $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value = %s",
                            $image_id
                        )
                    );

                    if ($meta_posts) {
                        $post_ids = array_merge($post_ids, $meta_posts);
                    }

                    // Remove duplicates
                    $post_ids = array_unique($post_ids);

                    // Output the image and its usage
                    echo '<div class="bulk-alt-item">';
                    echo '<img src="' . esc_url($image_url) . '" class="thumbnail" data-large="' . esc_url($image_url) . '" />';
                    echo '<p class="filename"><a href="' . esc_url($image_url) . '" target="_blank">' . esc_html($image_filename) . '</a></p>';
                    
                    // Display clickable post IDs where the image is used
                    if (!empty($post_ids)) {
                        echo '<div class="image-usage">';
                        echo '<strong>Used in:</strong> ';
                        foreach ($post_ids as $post_id) {
                            echo '<a href="' . esc_url(get_permalink($post_id)) . '" target="_blank">Post ' . esc_html($post_id) . '</a>, ';
                        }
                        echo '</div>';
                    }
                    
                    // Add input field with word count label
                    echo '<label for="alt_text_' . $image_id . '">Alt Text (Should be ~5-15 words): <span class="word-count" data-image-id="' . $image_id . '">(0 words)</span></label>';
                    echo '<input type="text" id="alt_text_' . $image_id . '" class="alt-text-input" name="alt_text[' . $image_id . ']" placeholder="Enter alt text" />';
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

// AJAX handler to save alt text updates
add_action('wp_ajax_bulk_alt_updater_save_alt_text', 'bulk_alt_updater_save_alt_text');
function bulk_alt_updater_save_alt_text() {
    check_ajax_referer('astute_tech_seo_helper_nonce', 'nonce');

    if (!current_user_can('manage_options') || !isset($_POST['alt_text'])) {
        wp_send_json_error('Invalid request');
        return;
    }

    foreach ($_POST['alt_text'] as $image_id => $alt_text) {
        update_post_meta($image_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
    }

    wp_send_json_success('Alt text updated successfully.');
}


// Tab: Description Length Checker
function astute_tech_seo_helper_description_length_checker() {
    $description_field = '';
    $focus_keyword_field = '';
    $is_yoast = defined('WPSEO_VERSION');
    $is_rank_math = defined('RANK_MATH_VERSION');

    // Define the meta fields for description and focus keyword based on the SEO plugin
    if ($is_yoast) {
        $description_field = '_yoast_wpseo_metadesc';
        $focus_keyword_field = '_yoast_wpseo_focuskw';
    } elseif ($is_rank_math) {
        $description_field = 'rank_math_description';
        $focus_keyword_field = 'rank_math_focus_keyword';
    }

    if (!$description_field) {
        echo '<p>Neither Yoast SEO nor Rank Math is installed. This feature requires one of these plugins.</p>';
        return;
    }

    // Define post types to exclude from the query
    $excluded_post_types = ['awards', 'video'];
    $all_post_types = array_merge(['post', 'page'], get_post_types(['public' => true, '_builtin' => false]));

    // Filter out excluded post types
    $post_types = array_diff($all_post_types, $excluded_post_types);

    // Query for posts with meta descriptions, ordered by ID
    $args = array(
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'ID', // Sort by ID
        'order'          => 'ASC'
    );

    $query = new WP_Query($args);

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>ID</th><th>Post Type</th><th>Title</th><th>Focus Keyword</th><th>Description</th><th>Description Length</th><th>New Description</th><th>New Description Length</th></tr></thead><tbody>';

    while ($query->have_posts()) {
        $query->the_post();
        $post_id = get_the_ID();
        $title = get_the_title($post_id);
        $post_type = get_post_type($post_id);

        // Retrieve the current meta description
        $current_description = get_post_meta($post_id, $description_field, true);
        $current_description_length = strlen($current_description);

        // Retrieve the focus keyword
        $focus_keyword = get_post_meta($post_id, $focus_keyword_field, true);

        // Only display rows for descriptions that fall outside the ideal length range
        if ($current_description_length < 120 || $current_description_length > 160) {
            echo '<tr>';
            echo '<td><a href="' . esc_url(get_edit_post_link($post_id)) . '" target="_blank">' . esc_html($post_id) . '</a></td>';
            echo '<td>' . esc_html($post_type) . '</td>';
            echo '<td>' . esc_html($title) . '</td>';
            echo '<td>' . esc_html($focus_keyword) . '</td>';
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

    // Define the post types to include/exclude
    $excluded_post_types = ['awards', 'video'];
    $all_post_types = array_merge(['post', 'page'], get_post_types(['public' => true, '_builtin' => false]));
    $post_types = array_diff($all_post_types, $excluded_post_types);

    // Query for published posts
    $args = array(
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'ID',
        'order'          => 'ASC'
    );

    $query = new WP_Query($args);

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>ID</th><th>Post Type</th><th>Rendered SEO Title</th><th>Title Length</th><th>Update Title</th></tr></thead><tbody>';

    while ($query->have_posts()) {
        $query->the_post();
        $post_id = get_the_ID();
        $post_type = get_post_type($post_id);

        // Retrieve the raw SEO title template
        $raw_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
        if (empty($raw_title)) {
            $raw_title = get_the_title($post_id);
        }

        // Render the title
        $rendered_title = str_replace(
            ['%%title%%', '%%page%%', '%%sep%%', '%%sitename%%'],
            [get_the_title($post_id), '', '|', get_bloginfo('name')],
            $raw_title
        );
        $rendered_title = str_replace('%', '', $rendered_title);
        $title_length = strlen($rendered_title);

        // **Only display posts where title is below 50 or above 60 characters**
        if ($title_length < 50 || $title_length > 60) {
            echo '<tr>';
            echo '<td><a href="' . esc_url(get_edit_post_link($post_id)) . '" target="_blank">' . esc_html($post_id) . '</a></td>';
            echo '<td>' . esc_html($post_type) . '</td>';
            echo '<td><input type="text" class="update-title" data-post-id="' . esc_attr($post_id) . '" value="' . esc_attr($rendered_title) . '" /></td>';
            echo '<td>' . esc_html($title_length) . '</td>';
            echo '<td><span class="update-title-length" data-post-id="' . esc_attr($post_id) . '">' . esc_html($title_length) . '</span></td>';
            echo '</tr>';
        }
    }

    wp_reset_postdata();

    echo '</tbody></table>';
    echo '<button type="button" id="save-titles" class="button button-primary">Save Titles</button>';
}

// Tab for Search Content
function astute_tech_seo_helper_search_content() {
    ?>
    <div class="wrap">
        <h2>Search Content</h2>
        <form method="post" action="">
            <input type="text" name="search_terms" placeholder="Enter search terms..." required>
            <label>
                <input type="checkbox" name="include_drafts" value="1" <?php checked(isset($_POST['include_drafts'])); ?>>
                Include Drafts
            </label>
            <?php submit_button('Search'); ?>
        </form>
    <?php

    if (isset($_POST['search_terms']) && !empty($_POST['search_terms'])) {
        global $wpdb;
        $search_terms = sanitize_text_field($_POST['search_terms']);
        $include_drafts = isset($_POST['include_drafts']) ? 'publish, draft' : 'publish';
        
        // Query to search in `wp_posts` (title, content, and excerpt) and `wp_postmeta` (ACF and custom fields)
        $query = "
            SELECT DISTINCT p.ID, p.post_type, p.post_title, p.post_content, p.post_excerpt, pm.meta_value AS custom_field
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE (p.post_status IN ($include_drafts)) 
                AND (p.post_type IN ('post', 'page'))
                AND (p.post_title LIKE %s 
                    OR p.post_content LIKE %s 
                    OR p.post_excerpt LIKE %s 
                    OR pm.meta_value LIKE %s)
                AND p.post_type != 'revision'
        ";
        
        // Prepare search term with wildcards for partial matching
        $search_like = '%' . $wpdb->esc_like($search_terms) . '%';
        $results = $wpdb->get_results($wpdb->prepare($query, $search_like, $search_like, $search_like, $search_like));
        
        if ($results) {
            // Count the number of rows
            $total_rows = count($results);

            // Output the table with the additional Post Title column
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>ID</th><th>Post Type</th><th>Post Title</th><th>In-Context Preview</th></tr></thead><tbody>';
            
            foreach ($results as $result) {
                $post_id = $result->ID;
                $post_type = $result->post_type;
                $post_title = $result->post_title;
                
                // Search for the term within title, content, excerpt, or custom fields
                $content_sources = [$post_title, $result->post_content, $result->post_excerpt, $result->custom_field];
                $context_snippet = '';

                foreach ($content_sources as $content) {
                    if (stripos($content, $search_terms) !== false) {
                        // Locate the search term and extract surrounding context
                        $position = stripos($content, $search_terms);
                        $context_snippet = '... ' . wp_html_excerpt($content, $position - 20, 20) . 
                                           '<strong>' . substr($content, $position, strlen($search_terms)) . '</strong>' . 
                                           wp_html_excerpt($content, $position + strlen($search_terms), 20) . ' ...';
                        break;
                    }
                }
                
                echo '<tr>';
                echo '<td><a href="' . esc_url(get_edit_post_link($post_id)) . '" target="_blank">' . esc_html($post_id) . '</a></td>';
                echo '<td>' . esc_html($post_type) . '</td>';
                echo '<td>' . esc_html($post_title) . '</td>';
                echo '<td>' . $context_snippet . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';

            // Display the total row count below the table
            echo '<p><strong>Total Rows:</strong> ' . esc_html($total_rows) . '</p>';
        } else {
            echo '<p>No results found for "<strong>' . esc_html($search_terms) . '</strong>".</p>';
        }
    }
    echo '</div>';
}

add_action('wp_ajax_save_updated_titles', 'save_updated_titles');
function save_updated_titles() {
    check_ajax_referer('astute_tech_seo_helper_nonce', 'nonce');

    if (!current_user_can('manage_options') || !isset($_POST['titles'])) {
        wp_send_json_error('Invalid request');
        return;
    }

    foreach ($_POST['titles'] as $post_id => $new_title) {
        update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($new_title));
    }

    wp_send_json_success('Titles updated successfully.');
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

// Render the admin page with all tabs, including the new Search Content tab
function astute_tech_seo_helper_page() {
    ?>
    <div class="wrap">
        <h1>Tech SEO Helper</h1>
        
        <!-- Tab Navigation -->
        <h2 class="nav-tab-wrapper">
            <a href="#bulk-alt" class="nav-tab nav-tab-active" onclick="showTab(event, 'bulk-alt')">Bulk Alt Text Updater</a>
            <a href="#description-length" class="nav-tab" onclick="showTab(event, 'description-length')">Description Length Checker</a>
            <a href="#title-checker" class="nav-tab" onclick="showTab(event, 'title-checker')">Title Checker</a>
            <a href="#search-content" class="nav-tab" onclick="showTab(event, 'search-content')">Search Content</a>
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

        <div id="search-content" class="tab-content" style="display:none;">
            <h2>Search Content</h2>
            <form id="search-content-form">
                <input type="text" id="search-terms" name="search_terms" placeholder="Enter search terms..." required>
                <label>
                    <input type="checkbox" id="include-drafts" name="include_drafts" value="1">
                    Include Drafts
                </label>
                <?php submit_button('Search'); ?>
            </form>
            <div id="search-results"></div> <!-- Container for AJAX results -->
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

// Enqueue the AJAX script
function astute_tech_seo_helper_enqueue_scripts() {
    if (isset($_GET['page']) && $_GET['page'] === 'astute-tech-seo-helper') {
        wp_enqueue_script('astute-tech-seo-helper-ajax', plugin_dir_url(__FILE__) . 'js/astute-tech-seo-helper-ajax.js', ['jquery'], null, true);
    }
}
add_action('admin_enqueue_scripts', 'astute_tech_seo_helper_enqueue_scripts');

// AJAX handler for search content
function astute_tech_seo_helper_search_content_ajax() {
    check_ajax_referer('astute_tech_seo_helper_nonce', 'nonce');

    global $wpdb;
    $search_terms = sanitize_text_field($_POST['search_terms']);
    $include_drafts = $_POST['include_drafts'] ? ['publish', 'draft'] : ['publish'];

    // Prepare the search term for partial matching
    $search_like = '%' . $wpdb->esc_like($search_terms) . '%';

    // Updated query to search in `wp_posts` and `wp_postmeta`, explicitly excluding revisions
    $query = "
        SELECT DISTINCT p.ID, p.post_type, p.post_title, p.post_content, p.post_excerpt, MAX(pm.meta_value) AS custom_field
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE p.post_status IN ('" . implode("','", $include_drafts) . "') 
            AND p.post_type IN ('post', 'page')
            AND (p.post_title LIKE %s 
                OR p.post_content LIKE %s 
                OR p.post_excerpt LIKE %s 
                OR (pm.meta_value LIKE %s AND pm.meta_key IS NOT NULL))
        AND p.post_type != 'revision'
        GROUP BY p.ID
    ";

    $results = $wpdb->get_results($wpdb->prepare($query, $search_like, $search_like, $search_like, $search_like));
    
    if ($results) {
        ob_start();
        
        // Count total rows
        $total_rows = count($results);

        // Output table with Post Title column
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>ID</th><th>Post Type</th><th>Post Title</th><th>In-Context Preview</th></tr></thead><tbody>';

        foreach ($results as $result) {
            $post_id = $result->ID;
            $post_type = $result->post_type;
            $post_title = $result->post_title;

            // Search for the term within title, content, excerpt, or custom fields
            $content_sources = [$post_title, $result->post_content, $result->post_excerpt, $result->custom_field];
            $context_snippet = '';

            foreach ($content_sources as $content) {
                if (stripos($content, $search_terms) !== false) {
                    // Locate the first occurrence of the search term
                    $position = stripos($content, $search_terms);
            
                    // Set the snippet to 30 characters before and 30 characters after the match
                    $before = substr($content, max(0, $position - 30), 30);
                    $match = substr($content, $position, strlen($search_terms));  // Matched term in bold
                    $after = substr($content, $position + strlen($search_terms), 30);
            
                    // Concatenate with bold match term and ellipses
                    $context_snippet = '... ' . esc_html($before) . '<strong>' . esc_html($match) . '</strong>' . esc_html($after) . ' ...';
                    break;
                }
            }

            echo '<tr>';
            echo '<td><a href="' . esc_url(get_edit_post_link($post_id)) . '" target="_blank">' . esc_html($post_id) . '</a></td>';
            echo '<td>' . esc_html($post_type) . '</td>';
            echo '<td>' . esc_html($post_title) . '</td>';
            echo '<td>' . $context_snippet . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p><strong>Total Rows:</strong> ' . esc_html($total_rows) . '</p>'; // Display total row count
        
        $output = ob_get_clean();
    } else {
        $output = '<p>No results found for "<strong>' . esc_html($search_terms) . '</strong>".</p>';
    }

    echo $output;
    wp_die(); // Properly end AJAX request
}
add_action('wp_ajax_astute_tech_seo_helper_search_content', 'astute_tech_seo_helper_search_content_ajax');
