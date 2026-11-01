// Handle order actions
document.addEventListener('click', async function(e) {
    // Handle Confirm Order button
    if (e.target.closest('.confirm-order')) {
        const button = e.target.closest('.confirm-order');
        const orderId = button.dataset.id;
        
        if (confirm('Are you sure you want to confirm this order? This will notify the seller to proceed with shipping.')) {
            try {
                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Confirming...';
                
                const response = await fetch('confirm_order.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `order_id=${encodeURIComponent(orderId)}`,
                    credentials: 'same-origin'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('Order confirmed! The seller has been notified to proceed with shipping.', 'success');
                    // Reload the page to update the UI
                    setTimeout(() => location.reload(), 1500);
                } else {
                    throw new Error(result.error || 'Failed to confirm order');
                }
            } catch (error) {
                console.error('Error:', error);
                showAlert(error.message, 'danger');
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-check"></i> Confirm Order';
            }
        }
    }
    
    // Handle Reject Order button
    if (e.target.closest('.reject-order')) {
        const button = e.target.closest('.reject-order');
        const orderId = button.dataset.id;
        
        const reason = prompt('Please enter the reason for rejecting this order:');
        if (reason === null) return; // User cancelled
        
        if (confirm('Are you sure you want to reject this order? This action cannot be undone.')) {
            try {
                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
                
                const response = await fetch('reject_order.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `order_id=${encodeURIComponent(orderId)}&reason=${encodeURIComponent(reason)}`,
                    credentials: 'same-origin'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('Order has been rejected. Any payment will be refunded to your account.', 'success');
                    // Reload the page to update the UI
                    setTimeout(() => location.reload(), 1500);
                } else {
                    throw new Error(result.error || 'Failed to reject order');
                }
            } catch (error) {
                console.error('Error:', error);
                showAlert(error.message, 'danger');
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-times"></i> Reject Order';
            }
        }
    }
    
    // Handle Confirm Receipt button (for shipped orders)
    if (e.target.closest('.confirm-payment')) {
        const button = e.target.closest('.confirm-payment');
        const orderId = button.dataset.id;
        
        if (confirm('Have you received the order in good condition? This will release payment to the seller.')) {
            try {
                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Confirming...';
                
                const response = await fetch('confirm_delivery.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `order_id=${encodeURIComponent(orderId)}`,
                    credentials: 'same-origin'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('Delivery confirmed! Thank you for your purchase.', 'success');
                    // Reload the page to update the UI
                    setTimeout(() => location.reload(), 1500);
                } else {
                    throw new Error(result.error || 'Failed to confirm delivery');
                }
            } catch (error) {
                console.error('Error:', error);
                showAlert(error.message, 'danger');
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Receipt & Release Payment';
            }
        }
    }
});

// Show alert message
function showAlert(message, type) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed top-0 end-0 m-3`;
    alertDiv.role = 'alert';
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    document.body.appendChild(alertDiv);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        alertDiv.remove();
    }, 5000);
}
