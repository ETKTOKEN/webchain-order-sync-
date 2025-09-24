jQuery(document).ready(function($) {
    console.log('WebChain: Admin JS loaded');

    // Connection tester
    $('#test-connection').on('click', function() {
        const $btn = $(this);
        const $status = $('#connection-status');
        const email = $('#webchain_user_email').val().trim();
        const wallet = $('#webchain_wallet').val().trim();
        
        if (!email || !wallet) {
            showAlert('Please enter both email and wallet address', 'error');
            return;
        }
        
        $btn.prop('disabled', true);
        $status.html('<span class="spinner is-active"></span>');
        
        $.ajax({
            url: webchainData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'webchain_test_connection',
                email: email,
                wallet: wallet,
                security: webchainData.nonce
            },
            success: function(response) {
                if (response.success) {
                    showAlert(response.data.message, 'success');
                    $status.html('✓ ' + response.data.message);
                    if (response.data.balance) {
                        $status.append('<br>Balance: ' + response.data.balance + ' ETK');
                    }
                } else {
                    showAlert(response.data, 'error');
                    $status.html('✗ Connection failed: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr) {
                const error = xhr.responseJSON?.data || 'Connection error';
                showAlert(error, 'error');
                $status.html('✗ Error: ' + error);
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });

    // Order action handler
    $(document).on('click', '.webchain-broadcast', function(e) {
        e.preventDefault();
        const $button = $(this);
        const orderId = $button.data('order_id');
        const nonce = $button.data('nonce');
        
        console.log('WebChain: Broadcast button clicked for Order ID: ' + orderId);
        
        if (!orderId || !nonce) {
            showAlert('Invalid order or security token', 'error');
            return;
        }
        
        if (!confirm('Broadcast this order to WebChain?')) {
            return;
        }
        
        $button.prop('disabled', true).text('Broadcasting...');
        
        $.ajax({
            url: webchainData.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'webchain_broadcast_order',
                order_id: orderId,
                security: nonce
            },
            success: function(response) {
                if (response && response.success) {
                    showAlert(response.data, 'success');
                    $button.replaceWith(
                        `<a href="https://e-talk.xyz/explorer/tx/${response.data.split('Transaction ')[1]}" 
                           class="button webchain-order-action view" 
                           target="_blank">
                           <span class="dashicons dashicons-external"></span>
                           View Transaction
                        </a>`
                    );
                } else {
                    const errorMsg = (response && response.data) ? response.data : 'Unknown error occurred';
                    showAlert('✗ ' + errorMsg, 'error');
                    $button.prop('disabled', false).text('Broadcast to WebChain');
                }
            },
            error: function(xhr) {
                let error = 'Broadcast failed';
                try {
                    if (xhr.responseJSON && xhr.responseJSON.data) {
                        error = xhr.responseJSON.data;
                    } else if (xhr.responseText) {
                        error = xhr.responseText;
                    }
                } catch (e) {
                    console.error('WebChain: Error parsing error response:', e);
                }
                showAlert('✗ ' + error, 'error');
                $button.prop('disabled', false).text('Broadcast to WebChain');
            }
        });
    });

    // Test broadcast handler
    $('#test-broadcast').on('click', function() {
        const $button = $(this);
        const $result = $('#test-broadcast-result');
        const orderId = $('#test_order_id').val().trim();

        console.log('WebChain: Test broadcast clicked for Order ID: ' + orderId);

        if (!orderId || isNaN(orderId)) {
            showAlert('Please enter a valid order ID', 'error');
            return;
        }

        $button.prop('disabled', true).text('Testing...');
        $result.html('<span class="spinner is-active"></span>');

        $.ajax({
            url: webchainData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'webchain_test_broadcast',
                order_id: orderId,
                security: webchainData.nonce
            },
            success: function(response) {
                if (response.success) {
                    showAlert(response.data, 'success');
                    $result.html('✓ ' + response.data);
                } else {
                    showAlert(response.data, 'error');
                    $result.html('✗ ' + response.data);
                }
            },
            error: function(xhr) {
                const error = xhr.responseJSON?.data || 'Test broadcast error';
                showAlert(error, 'error');
                $result.html('✗ Error: ' + error);
            },
            complete: function() {
                $button.prop('disabled', false).text('Test Broadcast');
            }
        });
    });

    // Clear error logs handler
    $('#clear-error-logs').on('click', function() {
        const $button = $(this);
        const $errorSection = $('.webchain-card:contains("Recent Errors")');
        
        console.log('WebChain: Clear error logs clicked');
        
        if (!confirm('Are you sure you want to clear all error logs?')) {
            return;
        }
        
        $button.prop('disabled', true).text('Clearing...');
        
        $.ajax({
            url: webchainData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'webchain_clear_error_logs',
                security: webchainData.nonce
            },
            success: function(response) {
                if (response.success) {
                    showAlert(response.data, 'success');
                    $errorSection.find('ul').remove();
                    $errorSection.append('<p>No errors logged.</p>');
                    $button.remove();
                } else {
                    showAlert(response.data || 'Failed to clear error logs', 'error');
                }
            },
            error: function(xhr) {
                const error = xhr.responseJSON?.data || 'Error clearing logs';
                showAlert(error, 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Clear Error Logs');
            }
        });
    });

    function showAlert(message, type = 'success') {
        const icon = type === 'success' ? 'yes' : 'no';
        const alert = `
            <div class="webchain-alert ${type}">
                <span class="dashicons dashicons-${icon}"></span>
                <div>${message}</div>
            </div>
        `;
        
        const $alerts = $('#webchain-alerts');
        $alerts.append(alert);
        
        setTimeout(() => {
            $alerts.find('.webchain-alert').first().fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
    }
});