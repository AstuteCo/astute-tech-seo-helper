jQuery(document).ready(function($) {
    // Tab switching logic
    function showTab(event, tabId) {
        event.preventDefault();
        $('.tab-content').hide(); // Hide all tab content
        $('#' + tabId).show();     // Show the selected tab content
        $('.nav-tab').removeClass('nav-tab-active'); // Remove active class from all tabs
        $(event.target).addClass('nav-tab-active');  // Add active class to the clicked tab
    }

    // Initial display setup: show the first tab by default
    $('#bulk-alt').show();

    // Calculate length of new description in real-time
    $(document).on('input', '.new-description', function() {
        const postId = $(this).data('post-id');
        const newDescription = $(this).val();
        const newDescriptionLength = newDescription.length;
        
        // Update the length display
        $(`.new-description-length[data-post-id="${postId}"]`).text(newDescriptionLength);
    });

    // Handle save button click
    $('#description-save').on('click', function() {
        const descriptions = {};

        // Gather new descriptions from input fields
        $('.new-description').each(function() {
            const postId = $(this).data('post-id');
            const newDescription = $(this).val();
            if (newDescription) {
                descriptions[postId] = newDescription;
            }
        });

        // Send descriptions via AJAX
        $.post(astuteTechSEOHelper.ajax_url, {
            action: 'save_new_descriptions',
            nonce: astuteTechSEOHelper.nonce,
            descriptions: descriptions
        }, function(response) {
            if (response.success) {
                alert('Descriptions updated successfully!');
            } else {
                alert('There was an error saving descriptions.');
            }
        });
    });

    // Attach the showTab function to all nav-tab links
    $('.nav-tab').on('click', function(event) {
        showTab(event, $(this).attr('href').substring(1));
    });
});
