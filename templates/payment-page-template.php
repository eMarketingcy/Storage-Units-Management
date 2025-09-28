<?php
/**
 * Payment Page Template for Storage Unit Manager Plugin.
 * Accessed variables: $unit, $company_name, $payment_amount, etc.
 */

// Define the primary color and a contrasting secondary color for better hierarchy
$primary_color = '#f97316'; // Orange
$accent_color = '#10b981';  // Green (for success/confirmation elements)
?>

<div id="sum-payment-wrapper">
    <div class="sum-payment-card">
        
        <header class="sum-header" style="border-bottom: 2px solid <?php echo $primary_color; ?>;">
            <div class="sum-logo-container">
                <?php echo do_shortcode('[sum_logo]'); ?>
            </div>
            <h1 style="color: <?php echo $primary_color; ?>;">Unit Payment</h1>
        </header>

        <section class="sum-details-section">
            <h2 class="sum-section-title">Invoice Summary</h2>
            <div class="sum-detail-group">
                <div class="sum-detail-row">
                    <span>Unit Name:</span>
                    <strong><?php echo $unit['unit_name']; ?></strong>
                </div>
                <div class="sum-detail-row">
                    <span>Customer:</span>
                    <strong><?php echo $unit['primary_contact_name']; ?></strong>
                </div>
                <div class="sum-detail-row">
                    <span>Billing Period:</span>
                    <strong><?php echo $unit['period_from']; ?> - <?php echo $unit['period_until']; ?></strong>
                </div>
            </div>
        </section>

        <section class="sum-amount-section" style="border-left: 5px solid <?php echo $primary_color; ?>;">
            <p class="sum-label">Amount Due</p>
            <p class="sum-amount" style="color: <?php echo $primary_color; ?>;">€<?php echo $payment_amount; ?></p>
        </section>

        <section class="sum-form-section">
            <h2 class="sum-section-title">Select Payment Method</h2>
            
            <form id="sum-payment-form" action="" method="POST">
                
                <input type="hidden" name="unit_id" value="<?php echo $unit['id']; ?>">
                <input type="hidden" name="token" value="<?php echo $unit['payment_token']; ?>">
                <input type="hidden" name="action" value="sum_process_payment">
                
                <div class="sum-method-select">
                    <label>
                        <input type="radio" name="payment_method" value="stripe" checked>
                        Credit Card / Debit Card
                    </label>
                    <label>
                        <input type="radio" name="payment_method" value="paypal">
                        PayPal
                    </label>
                </div>
                
                <button type="submit" class="sum-pay-button" style="background-color: <?php echo $accent_color; ?>;">
                    PAY NOW €<?php echo $payment_amount; ?>
                </button>
                
                <p class="sum-security-note">
                    Your payment is secured by SSL encryption.
                </p>
            </form>
        </section>
    </div>

    <footer class="sum-footer">
        <p>
            Questions? Contact <a href="mailto:<?php echo $company_email; ?>" style="color: <?php echo $primary_color; ?>;"><?php echo $company_email; ?></a> or call <?php echo $company_phone; ?>.
        </p>
    </footer>
</div>

<input type="hidden" id="sum-is-payment-page" value="1">