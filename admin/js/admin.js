jQuery(document).ready(function($) {
    // Manual sync button
    $('#manual-sync-btn').on('click', function() {
        var $button = $(this);
        var $status = $('#sync-status');
        
        $button.prop('disabled', true).text('Syncing...');
        $status.html('<p>Synchronizing tasks to products...</p>');
        
        var limit = $('#sync-limit').val();
        
        $.ajax({
            url: renderProductsAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'manual_sync_products',
                nonce: renderProductsAjax.nonce,
                limit: limit
            },
            success: function(response) {
                if (response.success) {
                    var message = '<p><strong>' + response.data.message + '</strong></p>';
                    
                    // Add detailed breakdown if available
                    if (response.data.details) {
                        var details = response.data.details;
                        message += '<ul>';
                        message += '<li>📦 Products: ' + details.products_synced + ' synced';
                        if (details.products_errors > 0) message += ', ' + details.products_errors + ' errors';
                        if (details.products_skipped > 0) message += ', ' + details.products_skipped + ' skipped';
                        message += '</li>';
                        
                        if (typeof details.brands_synced !== 'undefined') {
                            message += '<li>🏷️ Brands: ' + details.brands_synced + ' synced';
                            if (details.brands_errors > 0) message += ', ' + details.brands_errors + ' errors';
                            message += '</li>';
                            
                            // Add image statistics if available
                            if (response.data.details.brand_images_attempted > 0) {
                                message += '<li>🖼️ Brand Images: ' + response.data.details.brand_images_success + 
                                         '/' + response.data.details.brand_images_attempted + ' downloaded</li>';
                            }
                        }
                        message += '</ul>';
                        
                        // Show brand details if available
                        if (response.data.brand_details && response.data.brand_details.length > 0) {
                            message += '<p><small><strong>Brand details:</strong> ' + response.data.brand_details.join(', ');
                            if (response.data.brand_details.length === 5) {
                                message += ' (and more...)';
                            }
                            message += '</small></p>';
                        }
                    }
                    
                    $status.html('<div class="notice notice-success">' + message + '</div>');
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
    
    // Brand sync button
    $('#sync-brands-btn').on('click', function() {
        var $button = $(this);
        var $status = $('#brands-status');
        
        $button.prop('disabled', true).text('Syncing Brands...');
        $status.html('<p>Synchronizing organizations to brands...</p>');
        
        $.ajax({
            url: renderProductsAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'sync_brands',
                nonce: renderProductsAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    var message = response.data.message;
                    if (response.data.results && response.data.results.length > 0) {
                        message += '<br><small>Details: ' + response.data.results.slice(0, 5).join(', ');
                        if (response.data.results.length > 5) {
                            message += ' and ' + (response.data.results.length - 5) + ' more...';
                        }
                        message += '</small>';
                    }
                    $status.html('<div class="notice notice-success"><p>' + message + '</p></div>');
                } else {
                    var errorMsg = response.data ? (response.data.message || response.data) : 'Unknown error';
                    $status.html('<div class="notice notice-error"><p>Error: ' + errorMsg + '</p></div>');
                }
            },
            error: function() {
                $status.html('<div class="notice notice-error"><p>Failed to sync brands.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Sync Brands Now');
            }
        });
    });
});