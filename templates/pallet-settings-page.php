<div class="wrap">
    <h1>Pallet Storage Settings</h1>
    
    <form id="pallet-settings-form">
        <h2>EU Pallet Pricing (1.20m × 0.80m)</h2>
        <table class="form-table sum-pricing-table" id="eu-pricing-table">
            <thead>
                <tr>
                    <th>Height (m)</th>
                    <th>Monthly Price (€)</th>
                    <th>Cubic Meters</th>
                </tr>
            </thead>
            <tbody>
                <?php
$settings = $this->get_pallet_settings();
$eu_settings = array_filter($settings, function($s) { return $s['pallet_type'] === 'EU'; });

// Index by normalized 2-decimal string
$eu_prices = array();
foreach ($eu_settings as $setting) {
    $key = number_format((float)$setting['height'], 2, '.', '');
    $eu_prices[$key] = $setting['price'];
}

$eu_heights = [1.00, 1.20, 1.40, 1.60, 1.80, 2.00];
foreach ($eu_heights as $height):
    $k = number_format($height, 2, '.', '');
    $cubic_meters = 1.20 * 0.80 * (float)$k;
    $price = isset($eu_prices[$k]) ? $eu_prices[$k] : '30.00';
    $height_key = str_replace('.', '_', $k);
?>
<tr>
    <td><?php echo number_format((float)$k, 2); ?>m</td>
    <td>
        <input type="number" name="eu_price_<?php echo $height_key; ?>"
               value="<?php echo esc_attr($price); ?>"
               step="0.01" min="0" class="small-text"
               data-height="<?php echo $k; ?>"
               data-type="EU">
    </td>
    <td><?php echo number_format($cubic_meters, 3); ?> m³</td>
</tr>
<?php endforeach; ?>
            </tbody>
        </table>
        
        <h2>US Pallet Pricing (1.22m × 1.02m)</h2>
        <table class="form-table sum-pricing-table" id="us-pricing-table">
            <thead>
                <tr>
                    <th>Height (m)</th>
                    <th>Monthly Price (€)</th>
                    <th>Cubic Meters</th>
                </tr>
            </thead>
            <tbody>
                <?php
$us_settings = array_filter($settings, function($s) { return $s['pallet_type'] === 'US'; });

$us_prices = array();
foreach ($us_settings as $setting) {
    $key = number_format((float)$setting['height'], 2, '.', '');
    $us_prices[$key] = $setting['price'];
}

$us_heights = [1.00, 1.20, 1.40, 1.60, 1.80, 2.00];
foreach ($us_heights as $height):
    $k = number_format($height, 2, '.', '');
    $cubic_meters = 1.22 * 1.02 * (float)$k;
    $price = isset($us_prices[$k]) ? $us_prices[$k] : '35.00';
    $height_key = str_replace('.', '_', $k);
?>
<tr>
    <td><?php echo number_format((float)$k, 2); ?>m</td>
    <td>
        <input type="number" name="us_price_<?php echo $height_key; ?>"
               value="<?php echo esc_attr($price); ?>"
               step="0.01" min="0" class="small-text"
               data-height="<?php echo $k; ?>"
               data-type="US">
    </td>
    <td><?php echo number_format($cubic_meters, 3); ?> m³</td>
</tr>
<?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Debug Section -->
        <div class="sum-debug-section" style="background: #f0f0f0; padding: 15px; margin: 20px 0; border-radius: 5px;">
            <h3>Debug Information</h3>
            <p><strong>Current Settings Count:</strong> <?php echo count($settings); ?></p>
            <p><strong>EU Settings:</strong> <?php echo count($eu_settings); ?></p>
            <p><strong>US Settings:</strong> <?php echo count($us_settings); ?></p>
            <button type="button" id="debug-form-data" class="button button-secondary">Debug Form Data</button>
        </div>
        
        <p class="submit">
            <button type="submit" class="button-primary">Save Pallet Settings</button>
        </p>
    </form>
    
    <div class="sum-info-box">
        <h3>Pallet Storage Information</h3>
        <h4>Standard Pallet Sizes:</h4>
        <ul>
            <li><strong>EU Pallet:</strong> 1.20m × 0.80m (also known as Euro pallet)</li>
            <li><strong>US Pallet:</strong> 1.22m × 1.02m (also known as American pallet)</li>
        </ul>
        
        <h4>Height Tiers:</h4>
        <p>Pallets are charged based on height tiers. If a pallet is 0.90m high, it will be charged for the 1.00m tier. If it's 1.15m high, it will be charged for the 1.20m tier.</p>
        
        <h4>Automatic Calculations:</h4>
        <ul>
            <li><strong>Charged Height:</strong> Automatically calculated based on actual height</li>
            <li><strong>Cubic Meters:</strong> Calculated as Length × Width × Charged Height</li>
            <li><strong>Monthly Price:</strong> Based on pallet type and charged height tier</li>
        </ul>
        
        <h4>Pallet Name Generation:</h4>
        <p>Pallet names are automatically generated from customer names:</p>
        <ul>
            <li><strong>John Doe</strong> → JD1, JD2, JDE1, JDO1</li>
            <li><strong>Maria Garcia</strong> → MG1, MG2, MGA1, MAR1</li>
        </ul>
        <p>The system uses 2-3 letters from the customer name plus a number, ensuring uniqueness.</p>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Debug button to check form data
    $('#debug-form-data').on('click', function() {
        console.log('=== Debug Form Data ===');
        
        // Check all inputs by data attributes
        console.log('All pricing inputs:');
        $('input[data-type]').each(function() {
            const $input = $(this);
            console.log('Input: type=' + $input.data('type') + ', height=' + $input.data('height') + ', value=' + $input.val() + ', name=' + $input.attr('name'));
        });
        
        console.log('Total inputs found:', $('input[data-type]').length);
    });
    
    $('#pallet-settings-form').on('submit', function(e) {
        e.preventDefault();
        
        console.log('=== Pallet Settings Form Submission ===');
        
        // Collect all pricing data using data attributes for reliability
        const settings = [];
        
        $('input[data-type]').each(function() {
            const $input = $(this);
            const type = $input.data('type');
            const height = parseFloat($input.data('height'));
            const price = $input.val();
            
            console.log('Processing input: type=' + type + ', height=' + height + ', price=' + price);
            
            if (price && price.trim() !== '' && !isNaN(parseFloat(price))) {
                settings.push({
                    type: type,
                    height: height,
                    price: parseFloat(price)
                });
                console.log('Added to settings: ' + type + ' ' + height + 'm = €' + price);
            } else {
                console.log('Skipped: ' + type + ' ' + height + 'm (no valid price)');
            }
        });
        
        console.log('=== Final Settings Array ===');
        console.log('Settings count:', settings.length);
        console.log('Settings data:', settings);
        
        if (settings.length === 0) {
            alert('No pricing data found. Please enter prices for at least one height tier.');
            return;
        }
        
        const settingsJson = JSON.stringify(settings);
        console.log('=== JSON Data ===');
        console.log('JSON string:', settingsJson);
        console.log('JSON length:', settingsJson.length);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sum_save_pallet_settings',
                nonce: '<?php echo wp_create_nonce('sum_nonce'); ?>',
                settings: settingsJson
            },
            beforeSend: function() {
                console.log('=== AJAX Request Starting ===');
                console.log('URL:', ajaxurl);
                console.log('Action: sum_save_pallet_settings');
                console.log('Nonce: <?php echo wp_create_nonce('sum_nonce'); ?>');
                console.log('Settings JSON being sent:', settingsJson);
                
                // Show loading state
                $('button[type="submit"]').prop('disabled', true).text('Saving...');
            },
            success: function(response) {
                console.log('=== AJAX Success Response ===');
                console.log('Full response:', response);
                console.log('Response type:', typeof response);
                console.log('Response.success:', response.success);
                console.log('Response.data:', response.data);
                
                if (response.success) {
                    alert('Pallet settings saved successfully!');
                    location.reload();
                } else {
                    console.log('=== Save Failed ===');
                    console.log('Error message:', response.data);
                    alert('Error saving settings: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                console.log('=== AJAX Error ===');
                console.log('XHR:', xhr);
                console.log('Status:', status);
                console.log('Error:', error);
                console.log('Response text:', xhr.responseText);
                console.log('Response status:', xhr.status);
                
                let errorMessage = 'Failed to save pallet settings';
                if (xhr.responseText) {
                    try {
                        const errorResponse = JSON.parse(xhr.responseText);
                        if (errorResponse.data) {
                            errorMessage += ': ' + errorResponse.data;
                        }
                    } catch (e) {
                        errorMessage += ': ' + xhr.responseText.substring(0, 100);
                    }
                }
                alert(errorMessage);
            },
            complete: function() {
                console.log('=== AJAX Request Complete ===');
                // Reset button state
                $('button[type="submit"]').prop('disabled', false).text('Save Pallet Settings');
            }
        });
    });
});
</script>

<style>
.sum-info-box {
    background: #fff;
    border: 1px solid #ddd;
    border-left: 4px solid #f97316;
    padding: 15px;
    margin: 20px 0;
    border-radius: 4px;
}

.sum-info-box h3 {
    margin-top: 0;
    color: #333;
}

.sum-info-box ul {
    margin: 10px 0;
    padding-left: 20px;
}

.sum-pricing-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 30px;
    border: 1px solid #ddd;
}

.sum-pricing-table th,
.sum-pricing-table td {
    padding: 12px 15px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

.sum-pricing-table th {
    background-color: #f97316;
    color: white;
    font-weight: bold;
    text-transform: uppercase;
    font-size: 0.875rem;
    letter-spacing: 0.5px;
}

.sum-pricing-table input[type="number"] {
    width: 100px;
    padding: 8px 12px;
    border: 2px solid #e2e8f0;
    border-radius: 6px;
    font-size: 14px;
    transition: all 0.2s ease;
}

.sum-pricing-table input[type="number"]:focus {
    outline: none;
    border-color: #f97316;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
}

.sum-debug-section {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #3b82f6;
    padding: 15px;
    margin: 20px 0;
    border-radius: 4px;
}

.sum-debug-section h3 {
    margin-top: 0;
    color: #1e293b;
    font-size: 1rem;
}

.sum-debug-section p {
    margin: 5px 0;
    font-size: 0.875rem;
    color: #64748b;
}
</style>