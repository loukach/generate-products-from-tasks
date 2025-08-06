jQuery(document).ready(function($) {
    // Manual sync button
    $('#manual-sync-btn').on('click', function() {
        var $button = $(this);
        var $status = $('#sync-status');
        
        $button.prop('disabled', true).text('Syncing...');
        $status.html('<p>Synchronizing tasks to products...</p>');
        
        $.ajax({
            url: renderProductsAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'manual_sync_products',
                nonce: renderProductsAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $status.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                } else {
                    $status.html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                }
            },
            error: function() {
                $status.html('<div class="notice notice-error"><p>Failed to sync tasks.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Sync Tasks Now');
            }
        });
    });
    
    // Fetch tasks preview
    $('#fetch-tasks-btn').on('click', function() {
        var $button = $(this);
        var $preview = $('#tasks-preview');
        
        $button.prop('disabled', true).text('Fetching...');
        $preview.html('<p>Loading tasks...</p>');
        
        $.ajax({
            url: renderProductsAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'fetch_tasks_for_products',
                nonce: renderProductsAjax.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    var tasks = response.data;
                    var html = '<div class="tasks-grid">';
                    
                    tasks.slice(0, 10).forEach(function(task) {
                        html += '<div class="task-preview">';
                        html += '<h3>' + (task.title || 'Untitled') + '</h3>';
                        html += '<p><strong>Organization:</strong> ' + (task.organization || 'N/A') + '</p>';
                        html += '<p><strong>Location:</strong> ' + (task.location || 'N/A') + '</p>';
                        if (task.categories && task.categories.length) {
                            html += '<p><strong>Categories:</strong> ' + task.categories.join(', ') + '</p>';
                        }
                        if (task.tags && task.tags.length) {
                            var tagNames = task.tags.map(function(tag) {
                                return typeof tag === 'string' ? tag : tag.name;
                            });
                            html += '<p><strong>Tags:</strong> ' + tagNames.join(', ') + '</p>';
                        }
                        html += '</div>';
                    });
                    
                    html += '</div>';
                    html += '<p><em>Showing first 10 tasks of ' + tasks.length + ' total</em></p>';
                    
                    $preview.html(html);
                } else {
                    $preview.html('<div class="notice notice-error"><p>No tasks found or error fetching tasks.</p></div>');
                }
            },
            error: function() {
                $preview.html('<div class="notice notice-error"><p>Failed to fetch tasks.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Fetch Tasks');
            }
        });
    });
});