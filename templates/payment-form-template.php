<?php
/**
 * Payment Form Template (View)
 * This file handles all visual output for the payment page.
 * Variables passed via extract():
 * $unit, $entity_title, $display_label, $monthly_price, $subtotal, $vat_rate, 
 * $vat_amount, $total_due, $total_due_raw, $is_pallet, $stripe_publishable_key,
 * $company_email, $company_phone, $payment_nonce, $ajax_url
 */

// Define the colors for easy use in inline styles
$primary_color = '#f97316'; // Deep Orange
$accent_color = '#10b981';  // Green (for confirmation/success elements, kept different for contrast)
?>

<div id="sum-payment-wrapper" class="sum-payment-page-container">
    <div class="sum-payment-card" style="box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);">
        
        <header class="sum-header" style="border-bottom: 2px solid <?php echo $primary_color; ?>; text-align: center; padding-bottom: 20px;">
            <div class="sum-logo-container" style="margin-bottom: 10px;">
                <?php echo do_shortcode('[sum_logo]'); ?>
            </div>
            <h1 style="color: <?php echo $primary_color; ?>; font-size: 28px; font-weight: 700; margin: 0;">
                <?php echo $entity_title; ?>
            </h1>
        </header>

        <section class="sum-amount-section" style="border-left: 5px solid <?php echo $primary_color; ?>; background-color: #fff7ed; padding: 20px; border-radius: 6px; margin: 25px 0;">
            <p class="sum-label" style="margin: 0; font-size: 14px; color: #64748b;">TOTAL AMOUNT DUE</p>
            <p class="sum-amount" style="margin: 5px 0 0 0; font-size: 36px; font-weight: 800; line-height: 1.2; color: <?php echo $primary_color; ?>;">
                €<?php echo $total_due; ?>
            </p>
        </section>

        <section class="sum-details-section" style="border-bottom: 1px solid #f0f0f0; padding-bottom: 15px; margin-bottom: 20px;">
            <h2 class="sum-section-title" style="font-size: 18px; font-weight: 600; color: #333; margin-top: 0; margin-bottom: 15px;">Invoice Details</h2>
            <div class="sum-detail-group">
                <div class="sum-detail-row" style="display: flex; justify-content: space-between; padding: 8px 0; font-size: 15px;">
                    <span style="color: #555;"><?php echo $display_label; ?> Name:</span>
                    <strong style="color: #1a1a1a; font-weight: 600;"><?php echo $unit['unit_name']; ?></strong>
                </div>
                <div class="sum-detail-row" style="display: flex; justify-content: space-between; padding: 8px 0; font-size: 15px;">
                    <span style="color: #555;">Customer:</span>
                    <strong style="color: #1a1a1a; font-weight: 600;"><?php echo $unit['primary_contact_name']; ?></strong>
                </div>
                <div class="sum-detail-row" style="display: flex; justify-content: space-between; padding: 8px 0; font-size: 15px;">
                    <span style="color: #555;">Period:</span>
                    <strong style="color: #1a1a1a; font-weight: 600;"><?php echo $unit['period_from']; ?> → <?php echo $unit['period_until']; ?></strong>
                </div>
                <hr style="border: 0; border-top: 1px dashed #eee; margin: 10px 0;">
                <div class="sum-detail-row" style="display: flex; justify-content: space-between; padding: 8px 0; font-size: 15px;">
                    <span style="color: #555;">Subtotal (ex VAT):</span>
                    <strong style="color: #1a1a1a; font-weight: 600;">€<?php echo $subtotal; ?></strong>
                </div>
                <div class="sum-detail-row" style="display: flex; justify-content: space-between; padding: 8px 0; font-size: 15px;">
                    <span style="color: #555;">VAT (<?php echo $vat_rate; ?>%):</span>
                    <strong style="color: #1a1a1a; font-weight: 600;">€<?php echo $vat_amount; ?></strong>
                </div>
            </div>
        </section>

        <div class="sum-invoice-actions" style="margin-bottom: 20px; text-align: center;">
          <button type="button" id="reveal-payment" class="sum-payment-button" style="width: 100%; padding: 15px; border: none; border-radius: 6px; background-color: <?php echo $primary_color; ?>; color: #ffffff; font-size: 18px; font-weight: bold; cursor: pointer; margin-bottom: 10px;">
              CLICK TO PAY NOW
          </button>
          <button type="button" id="download-invoice" class="sum-payment-button" style="width: 100%; padding: 15px; border: 1px solid #ddd; border-radius: 6px; background-color: transparent; color: #555; font-size: 16px; cursor: pointer;">
              Download Invoice (PDF)
          </button>
        </div>

        <form id="payment-form" class="sum-stripe-form" style="display:none; margin-top: 20px;">
          <div class="sum-form-group">
            <label for="card-element" style="display: block; margin-bottom: 8px; font-weight: bold;">Credit or Debit Card</label>
            <div id="card-element" class="sum-card-element" style="padding: 10px; border: 1px solid #ccc; border-radius: 4px; background: #f9f9f9;"></div>
            <div id="card-errors" class="sum-card-errors" role="alert" style="color: #dc3545; margin-top: 5px;"></div>
          </div>

          <button type="submit" id="submit-payment" class="sum-pay-button" style="width: 100%; padding: 15px; margin-top: 20px; border: none; border-radius: 6px; background-color: <?php echo $accent_color; ?>; color: #ffffff; font-size: 18px; font-weight: bold; cursor: pointer;">
            <span id="button-text">Pay €<?php echo $total_due; ?></span>
            <div id="spinner" class="sum-spinner hidden" style="display:none; /* Ensure spinner starts hidden */"></div>
          </button>
          <p class="sum-security-note" style="font-size: 12px; color: #999; text-align: center; margin-top: 15px;">Your payment is secured by SSL encryption.</p>
        </form>

        <div id="payment-result" class="sum-payment-result" style="text-align: center; margin-top: 20px; padding: 10px; border-radius: 4px;"></div>
    </div>

    <footer class="sum-footer" style="text-align: center; padding: 15px 0; font-size: 13px; color: #666;">
        <p>
            Contact Us: <a href="mailto:<?php echo $company_email; ?>" style="color: <?php echo $primary_color; ?>;"><?php echo $company_email; ?></a> 
            <?php if ($company_phone): ?>| Tel: <?php echo $company_phone; ?><?php endif; ?>.
        </p>
    </footer>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {
  // --- DOM handles (guard against nulls) ---
  var revealBtn     = document.getElementById('reveal-payment');
  var downloadBtn   = document.getElementById('download-invoice');
  var paymentForm   = document.getElementById('payment-form');
  var cardErrorsEl  = document.getElementById('card-errors');
  var resultBox     = document.getElementById('payment-result');

  // --- PHP vars (Passed from the shortcode handler) ---
  var isPallet        = <?php echo $is_pallet ? 'true' : 'false'; ?>;
  var entityId        = <?php echo json_encode($unit['id']); ?>;
  var phpPaymentToken = <?php echo json_encode($unit['payment_token']); ?>;
  var phpAmountRaw    = <?php echo json_encode($total_due_raw); ?>; // Use raw float for JS calculation
  var wpNonce         = '<?php echo $payment_nonce; ?>';
  var ajaxUrl         = '<?php echo $ajax_url; ?>';
  var pubKey          = '<?php echo esc_js($stripe_publishable_key); ?>';

  // --- Stripe lazy init ---
  var stripe, elements, cardElement, submitBound = false;
  function initStripeOnce() {
    if (stripe) return; 
    if (typeof Stripe !== 'function') {
      show('error', 'Payment library failed to load. Please refresh the page.');
      return;
    }
    stripe   = Stripe(pubKey);
    elements = stripe.elements();
    cardElement = elements.create('card', { style: { base: { fontSize: '16px' } } });
    var cardMount = document.getElementById('card-element');
    if (cardMount) {
      cardElement.mount('#card-element');
      cardElement.on('change', function(e){
        if (cardErrorsEl) cardErrorsEl.textContent = e.error ? e.error.message : '';
      });
    }

    // Bind submit once
    if (paymentForm && !submitBound) {
      paymentForm.addEventListener('submit', function(e) {
        e.preventDefault();
        var submitButton = document.getElementById('submit-payment');
        var buttonText   = document.getElementById('button-text');
        var spinner      = document.getElementById('spinner');
        
        // Disable button & show spinner
        if (submitButton && buttonText && spinner) {
          submitButton.disabled = true; buttonText.style.display='none'; spinner.style.display='inline-block';
        }

        stripe.createToken(cardElement).then(function(res){
          if (res.error) {
            show('error', res.error.message);
            // Re-enable button
            if (submitButton && buttonText && spinner) {
              submitButton.disabled = false; buttonText.style.display='inline'; spinner.style.display='none';
            }
            return;
          }

          var data = {
            action: 'sum_process_stripe_payment',
            stripe_token: res.token.id,
            payment_token: phpPaymentToken,
            amount: Math.round(phpAmountRaw * 100), // Convert Euros to cents
            nonce: wpNonce
          };
          if (isPallet) data.pallet_id = entityId; else data.unit_id = entityId;

          fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data)
          })
          .then(function(r){ return r.json(); })
          .then(function(j){
            if (j && j.success) {
              show('success', 'Payment successful! Thank you.');
              if (paymentForm) paymentForm.style.display='none';
            } else {
              show('error', 'Payment failed: ' + (j && j.data ? j.data : 'Unknown error'));
            }
          })
          .catch(function(err){ show('error', 'Payment failed: ' + err.message); })
          .finally(function(){
            // Re-enable button
            if (submitButton && buttonText && spinner) {
              submitButton.disabled = false; buttonText.style.display='inline'; spinner.style.display='none';
            }
          });
        });
      });
      submitBound = true;
    }
  }

  // --- Reveal payment (bind BEFORE any Stripe usage) ---
  if (revealBtn) {
    revealBtn.addEventListener('click', function(){
      if (paymentForm) paymentForm.style.display = 'block';
      this.style.display = 'none'; // Hide the reveal button
      document.getElementById('download-invoice').style.marginBottom = '20px';
      initStripeOnce();
    });
  }

  // --- PDF download ---
  if (downloadBtn) {
    downloadBtn.addEventListener('click', function () {
      var data = {
        action: 'sum_generate_invoice_pdf',
        payment_token: phpPaymentToken,
        nonce: wpNonce
      };
      if (isPallet) { data.pallet_id = entityId; } else { data.unit_id = entityId; }

      var old = downloadBtn.textContent;
      downloadBtn.disabled = true; downloadBtn.textContent = 'Generating...';
      resultBox.className = 'sum-payment-result';
      resultBox.textContent = 'Generating PDF...';

      fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(data)
      })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j && j.success && j.data && j.data.download_url) {
          window.open(j.data.download_url, '_blank');
          show('success', 'PDF generated! Download should start shortly.');
        } else {
          show('error', (j && j.data) ? j.data : 'Failed to generate PDF');
        }
      })
      .catch(function(err){
        show('error', 'Failed to generate PDF: ' + err.message);
      })
      .finally(function(){
        downloadBtn.disabled = false; downloadBtn.textContent = old;
      });
    });
  }

  function show(type, msg) {
    if (!resultBox) return;
    resultBox.className = 'sum-payment-result ' + (type === 'success' ? 'success' : 'error');
    resultBox.textContent = msg;

    // Apply inline styles for result box
    if (type === 'success') {
      resultBox.style.backgroundColor = '#d1e7dd';
      resultBox.style.color = '#0f5132';
      resultBox.style.border = '1px solid #badbcc';
    } else {
      resultBox.style.backgroundColor = '#f8d7da';
      resultBox.style.color = '#842029';
      resultBox.style.border = '1px solid #f5c2c7';
    }
  }
});
</script>