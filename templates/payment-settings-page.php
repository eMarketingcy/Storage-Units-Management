<div class="wrap">
    <h1>Payment Settings</h1>
    
    <form id="payment-settings-form">
        <h2>Stripe Integration</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Enable Stripe Payments</th>
                <td>
                    <label for="stripe-enabled">
                        <input type="checkbox" id="stripe-enabled" name="stripe_enabled" value="1" 
                               <?php checked($this->get_setting('stripe_enabled', '0'), '1'); ?>>
                        Enable Stripe payment processing
                    </label>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Stripe Publishable Key</th>
                <td>
                    <input type="text" id="stripe-publishable-key" name="stripe_publishable_key" class="large-text" 
                           value="<?php echo esc_attr($this->get_setting('stripe_publishable_key', '')); ?>"
                           placeholder="pk_test_...">
                    <p class="description">Your Stripe publishable key (starts with pk_)</p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Stripe Secret Key</th>
                <td>
                    <input type="password" id="stripe-secret-key" name="stripe_secret_key" class="large-text" 
                           value="<?php echo esc_attr($this->get_setting('stripe_secret_key', '')); ?>"
                           placeholder="sk_test_...">
                    <p class="description">Your Stripe secret key (starts with sk_) - Keep this secure!</p>
                </td>
            </tr>
        </table>
        
        <h2>WooCommerce Integration</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Enable WooCommerce Integration</th>
                <td>
                    <label for="woocommerce-integration">
                        <input type="checkbox" id="woocommerce-integration" name="woocommerce_integration" value="1" 
                               <?php checked($this->get_setting('woocommerce_integration', '0'), '1'); ?>>
                        Integrate with WooCommerce for payments
                    </label>
                    <p class="description">
                        <?php if (class_exists('WooCommerce')): ?>
                            ✅ WooCommerce is installed and active
                        <?php else: ?>
                            ❌ WooCommerce is not installed. <a href="<?php echo admin_url('plugin-install.php?s=woocommerce&tab=search&type=term'); ?>">Install WooCommerce</a>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <h2>Payment Settings</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Default Unit Price</th>
                <td>
                    <input type="number" id="default-unit-price" name="default_unit_price" step="0.01" min="0" 
                           value="<?php echo esc_attr($this->get_setting('default_unit_price', '100.00')); ?>">
                    <span>€</span>
                    <p class="description">Default monthly price for storage units</p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Currency</th>
                <td>
                    <select id="currency" name="currency">
                        <option value="EUR" <?php selected($this->get_setting('currency', 'EUR'), 'EUR'); ?>>Euro (€)</option>
                        <option value="USD" <?php selected($this->get_setting('currency', 'EUR'), 'USD'); ?>>US Dollar ($)</option>
                        <option value="GBP" <?php selected($this->get_setting('currency', 'EUR'), 'GBP'); ?>>British Pound (£)</option>
                    </select>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Payment Page</th>
                <td>
                    <?php
                    $payment_page = get_page_by_path('storage-payment');
                    if (!$payment_page) {
                        echo '<p class="description">Payment page not created yet.</p>';
                        echo '<button type="button" id="create-payment-page" class="button button-secondary">Create Payment Page</button>';
                    } else {
                        echo '<p>Payment page: <a href="' . get_permalink($payment_page->ID) . '" target="_blank">' . get_permalink($payment_page->ID) . '</a></p>';
                        echo '<button type="button" id="recreate-payment-page" class="button button-secondary">Recreate Payment Page</button>';
                    }
                    ?>
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <button type="submit" class="button-primary">Save Payment Settings</button>
        </p>
    </form>
    
    <div class="sum-info-box">
        <h3>Setup Instructions</h3>
        <h4>Stripe Setup:</h4>
        <ol>
            <li>Create a Stripe account at <a href="https://stripe.com" target="_blank">stripe.com</a></li>
            <li>Get your API keys from the Stripe Dashboard</li>
            <li>Use test keys (pk_test_... and sk_test_...) for testing</li>
            <li>Switch to live keys when ready for production</li>
        </ol>
        
        <h4>WooCommerce Setup:</h4>
        <ol>
            <li>Install and activate WooCommerce plugin</li>
            <li>Complete WooCommerce setup wizard</li>
            <li>Enable WooCommerce integration above</li>
            <li>Storage units will be created as WooCommerce products</li>
        </ol>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('#payment-settings-form').on('submit', function(e) {
        e.preventDefault();
        
        console.log('Form submitted');
        
        // Get form data as regular object
        var formData = {
            action: 'sum_save_payment_settings',
            nonce: '<?php echo wp_create_nonce('sum_nonce'); ?>',
            stripe_enabled: $('#stripe-enabled').is(':checked') ? '1' : '0',
            stripe_publishable_key: $('#stripe-publishable-key').val(),
            stripe_secret_key: $('#stripe-secret-key').val(),
            woocommerce_integration: $('#woocommerce-integration').is(':checked') ? '1' : '0',
            default_unit_price: $('#default-unit-price').val(),
            currency: $('#currency').val()
        };
        
        console.log('Sending data:', formData);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            success: function(response) {
                console.log('Response:', response);
                if (response.success) {
                    alert('Payment settings saved successfully!');
                } else {
                    alert('Error saving settings: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to save payment settings');
            }
        });
    });
    
    $('#create-payment-page, #recreate-payment-page').on('click', function() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sum_create_payment_page',
                nonce: '<?php echo wp_create_nonce('sum_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    alert('Payment page created successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to create payment page');
            }
        });
    });
});
</script>

<style>
.sum-info-box {
    background: #fff;
    border: 1px solid #ddd;
    border-left: 4px solid #0073aa;
    padding: 15px;
    margin: 20px 0;
    border-radius: 4px;
}

.sum-info-box h3 {
    margin-top: 0;
    color: #333;
}

.sum-info-box ol {
    margin: 10px 0;
    padding-left: 20px;
}
</style>