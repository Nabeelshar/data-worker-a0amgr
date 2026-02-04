/**
 * Crawler Admin JavaScript
 */

(function($) {
    'use strict';
    
    let statusInterval = null;
    
    $(document).ready(function() {
        initCrawlerForms();
        initQueueActions();
        initLogActions();
        initQueueTreeView();
    });
    
    /**
     * Initialize crawler forms
     */
    function initCrawlerForms() {
        // Category form
        $('#crawler-category-form').on('submit', function(e) {
            e.preventDefault();
            
            const $form = $(this);
            const $button = $form.find('button[type="submit"]');
            const originalText = $button.text();
            
            $button.prop('disabled', true).text(crawlerData.strings.processing);
            
            $.ajax({
                url: crawlerData.ajax_url,
                type: 'POST',
                data: {
                    action: 'crawler_start_category',
                    nonce: crawlerData.nonce,
                    category_url: $('#category_url').val(),
                    max_novels: $('#max_novels').val()
                },
                success: function(response) {
                    if (response.success) {
                        showStatus('success', response.data.message);
                        startStatusPolling();
                    } else {
                        showStatus('error', response.data.message || crawlerData.strings.error);
                    }
                },
                error: function() {
                    showStatus('error', crawlerData.strings.error);
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Single novel form
        $('#crawler-single-form').on('submit', function(e) {
            e.preventDefault();
            
            const $form = $(this);
            const $button = $form.find('button[type="submit"]');
            const originalText = $button.text();
            
            $button.prop('disabled', true).text(crawlerData.strings.processing);
            
            $.ajax({
                url: crawlerData.ajax_url,
                type: 'POST',
                data: {
                    action: 'crawler_start_single',
                    nonce: crawlerData.nonce,
                    novel_url: $('#novel_url').val()
                },
                success: function(response) {
                    if (response.success) {
                        showStatus('success', response.data.message);
                        startStatusPolling();
                    } else {
                        showStatus('error', response.data.message || crawlerData.strings.error);
                    }
                },
                error: function() {
                    showStatus('error', crawlerData.strings.error);
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
    }
    
    /**
     * Show status message
     */
    function showStatus(type, message) {
        const $status = $('#crawler-status');
        $status.html('<p class="status-' + type + '">' + message + '</p>');
    }
    
    /**
     * Start polling for crawler status
     */
    function startStatusPolling() {
        if (statusInterval) {
            clearInterval(statusInterval);
        }
        
        updateStatus();
        statusInterval = setInterval(updateStatus, 5000);
    }
    
    /**
     * Update crawler status
     */
    function updateStatus() {
        $.ajax({
            url: crawlerData.ajax_url,
            type: 'POST',
            data: {
                action: 'crawler_get_status',
                nonce: crawlerData.nonce
            },
            success: function(response) {
                if (response.success) {
                    const stats = response.data;
                    
                    // Show progress bar
                    $('#crawler-progress').show();
                    $('.progress-fill').css('width', stats.progress + '%');
                    $('.progress-text').text(stats.progress + '% Complete');
                    
                    // Update status text
                    const statusText = `
                        <p><strong>Total:</strong> ${stats.total} | 
                        <strong>Pending:</strong> ${stats.pending} | 
                        <strong>Processing:</strong> ${stats.processing} | 
                        <strong>Completed:</strong> ${stats.completed} | 
                        <strong>Failed:</strong> ${stats.failed}</p>
                    `;
                    $('#crawler-status').html(statusText);
                    
                    // Stop polling if nothing pending
                    if (stats.pending === 0 && stats.processing === 0) {
                        clearInterval(statusInterval);
                        if (stats.completed > 0) {
                            showStatus('success', 'Crawling completed!');
                        }
                    }
                }
            }
        });
    }
    
    /**
     * Initialize queue actions
     */
    function initQueueActions() {
        // Process queue now
        $(document).on('click', '#process-queue-now', function(e) {
            e.preventDefault();
            const $button = $(this);
            const originalText = $button.html();
            
            $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Starting...');
            
            $.ajax({
                url: crawlerData.ajax_url,
                type: 'POST',
                data: {
                    action: 'crawler_process_queue_now',
                    nonce: crawlerData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.redirect) {
                            // Show message and reload to start polling
                            $button.html('<span class="spinner-border spinner-border-sm"></span> Processing in background...');
                            
                            // Show status message
                            if ($('#crawler-status').length) {
                                $('#crawler-status').html('<div class="alert alert-info">' + response.data.message + '</div>');
                            }
                            
                            // Start polling for updates
                            startStatusPolling();
                            
                            // Re-enable button after a moment
                            setTimeout(function() {
                                $button.prop('disabled', false).html(originalText);
                            }, 2000);
                        } else {
                            alert(response.data.message);
                            $button.prop('disabled', false).html(originalText);
                        }
                    } else {
                        alert(response.data.message || crawlerData.strings.error);
                        $button.prop('disabled', false).html(originalText);
                    }
                },
                error: function() {
                    alert(crawlerData.strings.error);
                    $button.prop('disabled', false).html(originalText);
                }
            });
        });
        
        // Clear completed
        $(document).on('click', '#clear-completed', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to clear completed items?')) {
                return;
            }
            clearQueue('completed');
        });
        
        // Clear failed
        $(document).on('click', '#clear-failed', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to clear failed items?')) {
                return;
            }
            clearQueue('failed');
        });
        
        // Clear all
        $(document).on('click', '#clear-all', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to clear ALL queue items? This cannot be undone!')) {
                return;
            }
            clearQueue('all');
        });
        
        // Retry item
        $(document).on('click', '.retry-item', function(e) {
            e.preventDefault();
            const id = $(this).data('id');
            retryItem(id);
        });
        
        // Delete item
        $(document).on('click', '.delete-item', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to delete this item?')) {
                return;
            }
            const id = $(this).data('id');
            deleteItem(id);
        });
    }
    
    /**
     * Clear queue
     */
    function clearQueue(actionType) {
        $.ajax({
            url: crawlerData.ajax_url,
            type: 'POST',
            data: {
                action: 'crawler_clear_queue',
                nonce: crawlerData.nonce,
                action_type: actionType
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || crawlerData.strings.error);
                }
            },
            error: function() {
                alert(crawlerData.strings.error);
            }
        });
    }
    
    /**
     * Retry queue item
     */
    function retryItem(id) {
        $.ajax({
            url: crawlerData.ajax_url,
            type: 'POST',
            data: {
                action: 'crawler_retry_item',
                nonce: crawlerData.nonce,
                id: id
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || crawlerData.strings.error);
                }
            },
            error: function() {
                alert(crawlerData.strings.error);
            }
        });
    }
    
    /**
     * Delete queue item
     */
    function deleteItem(id) {
        $.ajax({
            url: crawlerData.ajax_url,
            type: 'POST',
            data: {
                action: 'crawler_delete_item',
                nonce: crawlerData.nonce,
                id: id
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || crawlerData.strings.error);
                }
            },
            error: function() {
                alert(crawlerData.strings.error);
            }
        });
    }
    
    /**
     * Initialize log actions
     */
    function initLogActions() {
        // Clear database logs
        $(document).on('click', '#clear-logs', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to clear database logs? This cannot be undone!')) {
                return;
            }
            
            $.ajax({
                url: crawlerData.ajax_url,
                type: 'POST',
                data: {
                    action: 'crawler_clear_logs',
                    nonce: crawlerData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || crawlerData.strings.error);
                    }
                },
                error: function() {
                    alert(crawlerData.strings.error);
                }
            });
        });
        
        // Clear old log files
        $(document).on('click', '#clear-old-logs', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to delete log files older than 30 days?')) {
                return;
            }
            
            $.ajax({
                url: crawlerData.ajax_url,
                type: 'POST',
                data: {
                    action: 'crawler_clear_old_logs',
                    nonce: crawlerData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message || crawlerData.strings.error);
                    }
                },
                error: function() {
                    alert(crawlerData.strings.error);
                }
            });
        });
        
        // Refresh log files
        $(document).on('click', '#refresh-log-files', function(e) {
            e.preventDefault();
            location.reload();
        });
    }
    
    /**
     * Initialize queue tree view
     */
    function initQueueTreeView() {
        // Toggle children visibility
        $(document).on('click', '.toggle-children', function(e) {
            e.preventDefault();
            const $toggle = $(this);
            const $node = $toggle.closest('.queue-node');
            const $children = $node.find('.queue-children').first();
            
            $children.slideToggle(200);
            $toggle.toggleClass('rotated');
        });
        
        // Auto-refresh queue page if on queue page and items are processing
        if ($('.queue-tree').length > 0) {
            const hasPendingOrProcessing = $('.status-pending, .status-processing').length > 0;
            
            if (hasPendingOrProcessing) {
                // Refresh every 10 seconds to show progress
                setTimeout(function() {
                    location.reload();
                }, 10000);
                
                // Show auto-refresh indicator
                if ($('.queue-actions').length) {
                    $('.queue-actions').append(
                        '<span class="badge bg-info ms-2">' +
                        '<span class="spinner-border spinner-border-sm me-1"></span>' +
                        'Auto-refreshing every 10s...</span>'
                    );
                }
            }
        }
    }
    
})(jQuery);
