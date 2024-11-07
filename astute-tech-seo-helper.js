function showTab(event, tabId) {
    event.preventDefault();
    document.querySelectorAll('.tab-content').forEach(tab => tab.style.display = 'none');
    document.getElementById(tabId).style.display = 'block';
    document.querySelectorAll('.nav-tab').forEach(tab => tab.classList.remove('nav-tab-active'));
    event.target.classList.add('nav-tab-active');
}

jQuery(document).ready(function($) {
    // Handle post type selection
    $('#post_type_select').change(function() {
        const postType = $(this).val();
        if (!postType) {
            $('#post_type_table').html('');
            return;
        }

        $.ajax({
            url: astuteTechSEOHelper.ajax_url,
            method: 'POST',
            data: {
                action: 'astute_get_posts_by_type',
                nonce: astuteTechSEOHelper.nonce,
                post_type: postType
            },
            success: function(response) {
                if (response.success) {
                    let tableHtml = '<table class="wp-list-table widefat fixed striped">';
                    tableHtml += '<thead><tr><th>ID</th><th>Title</th><th>URL</th></tr></thead><tbody>';

                    response.data.forEach(function(post) {
                        tableHtml += '<tr>';
                        tableHtml += '<td>' + post.ID + '</td>';
                        tableHtml += '<td>' + post.title + '</td>';
                        tableHtml += '<td><a href="' + post.url + '" target="_blank">' + post.url + '</a></td>';
                        tableHtml += '</tr>';
                    });

                    tableHtml += '</tbody></table>';
                    $('#post_type_table').html(tableHtml);
                } else {
                    $('#post_type_table').html('<p>No posts found.</p>');
                }
            },
            error: function() {
                $('#post_type_table').html('<p>An error occurred while fetching posts.</p>');
            }
        });
    });
});
