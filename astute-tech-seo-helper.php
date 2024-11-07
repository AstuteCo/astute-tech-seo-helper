<?php
/*
Plugin Name: Astute Tech SEO Helper
Description: Combines functionalities for viewing meta data by URL and post type in one plugin with tabs.
Version: 1.0
Author: Astute Communications
*/

// Add the admin menu for the combined plugin
add_action('admin_menu', 'astute_tech_seo_helper_menu');
function astute_tech_seo_helper_menu() {
    add_menu_page(
        'Astute Tech SEO Helper',
        'Astute Tech SEO Helper',
        'manage_options',
        'astute-tech-seo-helper',
        'astute_tech_seo_helper_page',
        'dashicons-visibility',
        20
    );
}

// Enqueue JavaScript for tab functionality and AJAX
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
}


// Render the main admin page with tabs
function astute_tech_seo_helper_page() {
    ?>
    <div class="wrap">
        <h1>Astute Tech SEO Helper</h1>
        
        <!-- Tab Navigation -->
        <h2 class="nav-tab-wrapper">
            <a href="#get-meta-url" class="nav-tab nav-tab-active" onclick="showTab(event, 'get-meta-url')">Get Meta from List of URLs</a>
            <a href="#get-meta-type" class="nav-tab" onclick="showTab(event, 'get-meta-type')">Get Meta by Type</a>
        </h2>

        <!-- Tab Content -->
        <div id="get-meta-url" class="tab-content">
            <h2>Get Meta from List of URLs</h2>
            <?php astute_tech_seo_helper_get_meta_url(); ?>
        </div>
        
        <div id="get-meta-type" class="tab-content" style="display:none;">
            <h2>Get Meta by Type</h2>
            <?php astute_tech_seo_helper_get_meta_type(); ?>
        </div>
    </div>
    <?php
}

// Tab: Get Meta from List of URLs
function astute_tech_seo_helper_get_meta_url() {
    ?>
    <form method="post" id="get-meta-url-form">
        <label for="url_list">Enter URLs (one per line):</label><br>
        <textarea id="url_list" name="url_list" rows="10" cols="70"><?php echo isset($_POST['url_list']) ? esc_textarea($_POST['url_list']) : ''; ?></textarea><br><br>
        <?php submit_button('Get Meta Data'); ?>
    </form>
    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['url_list'])) {
        $urls = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($_POST['url_list']))));
        if (!empty($urls)) {
            echo '<h2>Meta Data Results</h2>';
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>KW</th><th>Post ID</th><th>Meta Description</th><th>Title Tag</th><th>Permalink</th></tr></thead><tbody>';

            foreach ($urls as $url) {
                $meta_data = astute_tech_seo_helper_fetch_meta_data($url);

                echo '<tr>';
                echo '<td>' . esc_html($meta_data['KW']) . '</td>';
                echo '<td>' . esc_html($meta_data['Post_ID']) . '</td>';
                echo '<td>' . esc_html($meta_data['Meta_Description']) . '</td>';
                echo '<td>' . esc_html($meta_data['Title_Tag']) . '</td>';
                echo '<td><a href="' . esc_url($url) . '" target="_blank">' . esc_html($url) . '</a></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }
    }
}

// Tab: Get Meta by Type
function astute_tech_seo_helper_get_meta_type() {
    ?>
    <label for="post_type_select">Select Post Type:</label>
    <select id="post_type_select">
        <option value="">Select a post type</option>
        <?php
        $post_types = get_post_types(['public' => true], 'objects');
        foreach ($post_types as $post_type) {
            echo '<option value="' . esc_attr($post_type->name) . '">' . esc_html($post_type->label) . '</option>';
        }
        ?>
    </select>
    <div id="post_type_table"></div>
    <?php
}

// Fetch metadata for each URL
function astute_tech_seo_helper_fetch_meta_data($url) {
    $response = wp_remote_get($url);
    $data = [
        'KW' => 'NOT SET',
        'Post_ID' => 'XXXX',
        'Meta_Description' => '',
        'Title_Tag' => ''
    ];

    if (is_wp_error($response)) {
        return $data;
    }

    $html = wp_remote_retrieve_body($response);

    // Parse the Yoast Focus Keyword if applicable
    if (preg_match('/class="[^"]*?(postid|page-id)-(\d+)[^"]*"/i', $html, $matches)) {
        $data['Post_ID'] = $matches[2];
        
        // Retrieve Yoast Focus Keyword from post meta
        $focus_keyword = get_post_meta($data['Post_ID'], '_yoast_wpseo_focuskw', true);
        if ($focus_keyword) {
            $data['KW'] = $focus_keyword;
        }
    }

    // Meta Description
    if (preg_match('/<meta name="description" content="(.*?)"/i', $html, $matches)) {
        $data['Meta_Description'] = $matches[1];
    }

    // Title Tag
    if (preg_match('/<title>(.*?)<\/title>/i', $html, $matches)) {
        $data['Title_Tag'] = $matches[1];
    }

    return $data;
}

// AJAX handler to fetch posts by type
add_action('wp_ajax_astute_get_posts_by_type', 'astute_get_posts_by_type');
function astute_get_posts_by_type() {
    check_ajax_referer('astute_tech_seo_helper_nonce', 'nonce');

    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
    if (!$post_type) {
        wp_send_json_error('Invalid post type');
    }

    // Set up query args with default ordering by title
    $query_args = [
        'post_type'      => $post_type,
        'posts_per_page' => -1,
        'post_status'    => 'any',  // Pull posts regardless of status
        'orderby'        => 'title',
        'order'          => 'ASC'
    ];

    // For attachments, sort by file URL instead of title
    if ($post_type === 'attachment') {
        $query_args['orderby'] = 'meta_value';
        $query_args['meta_key'] = '_wp_attached_file';
    }

    $posts = get_posts($query_args);

    $data = [];
    foreach ($posts as $post) {
        // Get the file URL for attachments, permalink for other types
        $url = ($post_type === 'attachment') ? wp_get_attachment_url($post->ID) : get_permalink($post);

        $data[] = [
            'ID'    => $post->ID,
            'title' => get_the_title($post),
            'url'   => $url
        ];
    }

    wp_send_json_success($data);
}
