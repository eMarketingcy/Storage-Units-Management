/**
 * SUM Customers Module - Frontend Logic (CSSC)
 * Handles AJAX loading, search, filtering, and view toggling.
 */
(function($) {
    // Utility function to safely escape HTML content
    const escapeHtml = (str) => {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', 
            '"': '&quot;', "'": '&#039;'
        }[m]));
    };

    // --- DOM Element Variables ---
    const $container = $('#sum-customers-frontend');
    const $customerList = $('#frontend-customers');
    const $loading = $('#frontend-loading');
    const $emptyState = $('#frontend-empty');
    const $filterBtns = $container.find('.sum-frontend-filters .filter-btn');
    const $searchField = $('#frontend-search');
    const $viewToggle = $container.find('.sum-view-toggle-btn');
    
    // FIX: Define the error container element. Assuming it's near the header.
    const $errorContainer = $container.find('.sum-status-message'); 
    
    // --- State Variables ---
    let allCustomers = [];
    let currentFilter = 'all';
    let currentView = localStorage.getItem('sum_customers_view') || 'grid'; 

    // --- Renderer Functions ---
    
    function renderCustomerCard(customer) {
        // ... (renderCustomerCard logic remains the same)
        const statusText = customer.status === 'active' ? 'Active Customer' : 'Past Customer';
        const currentRentals = (customer.current_units || []).concat(customer.current_pallets || []);
        const rentalsHtml = currentRentals.map(item => {
            const typeClass = item.toUpperCase().startsWith('U-') ? 'sum-rental-unit' : 'sum-rental-pallet';
            return `<span class="sum-rental-item ${typeClass}">${escapeHtml(item)}</span>`;
        }).join('');

        return `
            <div class="sum-customer-card sum-grid-item sum-card" data-customer-id="${customer.id}" data-filter-status="${customer.status}">
                <div class="sum-card-header sum-customer-header">
                    <h3 class="sum-customer-name">${escapeHtml(customer.name || 'N/A')}</h3>
                    <span class="sum-status sum-status-${customer.status}">${statusText}</span>
                </div>
                <div class="sum-card-body sum-customer-details">
                    <p class="sum-contact">📧 ${escapeHtml(customer.email_display || 'N/A')}</p>
                    <p class="sum-contact">📞 ${escapeHtml(customer.phone_display || 'N/A')}</p>
                    
                    <div class="sum-rentals-section">
                        <h4>Active Assets:</h4>
                        <div class="sum-rental-list">
                            ${rentalsHtml || '<span class="sum-no-rentals">None</span>'}
                        </div>
                    </div>
                </div>
                <div class="sum-card-footer">
                    <a href="#" class="sum-card-action-btn" data-customer-id="${customer.id}">View Details</a>
                </div>
            </div>
        `;
    }

    function renderCustomerListItem(customer) {
        // ... (renderCustomerListItem logic remains the same)
        const statusText = customer.status === 'active' ? 'Active Customer' : 'Past Customer';
        const currentRentals = (customer.current_units || []).concat(customer.current_pallets || []);
        const rentalsText = currentRentals.length > 0 ? currentRentals.join(', ') : 'None';

        return `
            <div class="sum-list-item" data-customer-id="${customer.id}" data-filter-status="${customer.status}">
                <div class="sum-list-column sum-column-name">
                    <strong>${escapeHtml(customer.name || 'N/A')}</strong>
                </div>
                <div class="sum-list-column sum-column-contact">
                    <p class="sum-contact">${escapeHtml(customer.email_display || 'N/A')}</p>
                    <p class="sum-contact">${escapeHtml(customer.phone_display || 'N/A')}</p>
                </div>
                <div class="sum-list-column sum-column-rentals">
                    ${escapeHtml(rentalsText)}
                </div>
                <div class="sum-list-column sum-column-status">
                    <span class="sum-status sum-status-${customer.status}">${statusText}</span>
                </div>
                <div class="sum-list-column sum-column-actions">
                    <a href="#" class="sum-card-action-btn" data-customer-id="${customer.id}">View</a>
                </div>
            </div>
        `;
    }
    
    // --- Core Functions ---

    function updateViewMode(view) {
        currentView = view;
        localStorage.setItem('sum_customers_view', view);
        $customerList.removeClass('sum-customers-grid sum-customers-list').addClass(`sum-customers-${view}`);
        $viewToggle.removeClass('active');
        $viewToggle.filter(`[data-view="${view}"]`).addClass('active');
        filterAndRenderCustomers();
    }
    
    // --- 1. Update filterCustomers function ---

    function filterCustomers(customers, filter, search) {
        let filtered = customers;

        if (filter !== 'all') {
            filtered = filtered.filter(c => {
                if (filter === 'active' || filter === 'past') {
                    return c.status === filter;
                } else if (filter === 'unpaid') {
                    return c.unpaid_invoices && c.unpaid_invoices.length > 0;
                }
                return true; 
            });
        }
        
        if (search) {
            const searchTerm = search.toLowerCase();
            filtered = filtered.filter(c => 
                (c.name && c.name.toLowerCase().includes(searchTerm)) ||
                (c.email_display && c.email_display.toLowerCase().includes(searchTerm)) ||
                (c.phone_display && c.phone_display.toLowerCase().includes(searchTerm))
            );
        }
        return filtered;
    }

  

    function filterAndRenderCustomers() {
        // ... (filterAndRenderCustomers logic remains the same)
        const search = $searchField.val().trim();
        const filtered = filterCustomers(allCustomers, currentFilter, search);

        $customerList.empty();
        
        if (filtered.length === 0) {
            $customerList.hide();
            $emptyState.show();
            return;
        }

        $emptyState.hide();
        $customerList.show();
        
        const renderFunc = currentView === 'list' ? renderCustomerListItem : renderCustomerCard;
        const html = filtered.map(renderFunc).join('');
        
        $customerList.html(html);
        
        if (currentView === 'list') {
             $customerList.prepend(`
                 <div class="sum-list-header">
                     <div class="sum-list-column sum-column-name">Customer Name</div>
                     <div class="sum-list-column sum-column-contact">Contact Info</div>
                     <div class="sum-list-column sum-column-rentals">Active Assets</div>
                     <div class="sum-list-column sum-column-status">Status</div>
                     <div class="sum-list-column sum-column-actions">Actions</div>
                 </div>
             `);
        }
    }

    function loadCustomers(search = '') {
        // FIX: The error occurred because $error.hide() was called. Now using $errorContainer.
        $errorContainer.hide().text(''); 
        $emptyState.hide();
        $customerList.hide().empty();
        $loading.show();
        $filterBtns.prop('disabled', true); 
        $viewToggle.prop('disabled', true);

        // Use the localized AJAX URL and nonce
        const ajaxUrl = sum_customers_ajax.ajax_url;
        const nonce = sum_customers_ajax.nonce;

        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            dataType: 'json',
            timeout: 15000,
            // Action to fetch all customers
            data: { action: 'sum_customers_frontend_get_cssc', nonce: nonce, search: search }
        })
        .done(function(resp) {
            if (!resp || !resp.success || !Array.isArray(resp.data)) {
                // Handle non-successful API response
                const msg = (resp && resp.data && resp.data.message) || 'Failed to load customers.';
                $errorContainer.text(msg).show();
                $emptyState.show();
                return;
            }
            
            allCustomers = resp.data;
            if (!allCustomers.length) { 
                $emptyState.show(); 
            } else {
                filterAndRenderCustomers(); 
            }
        })
        .fail(function(xhr) {
            // Handle network/server errors
            let msg = 'Server error loading customers';
            if (xhr && xhr.status) {
                msg += ' (' + xhr.status + ')';
            }
            $errorContainer.text(msg + '.').show();
            $emptyState.show();
        })
        .always(function(){
            $loading.hide();
            $filterBtns.prop('disabled', false);
            $viewToggle.prop('disabled', false);
        });
    }
    
   // --- Modal Logic ---

    const $modalOverlay = $('#sum-customer-modal-overlay');
    const $modalContent = $('#sum-customer-details-modal');
    const $modalName = $('#modal-customer-name');
    const $modalBody = $('#modal-details-body');

    /**
     * Loads detailed customer data via AJAX and opens the modal.
     */
    function openCustomerDetailsModal(customerId) {
        // 1. Reset and show loading state
        $modalName.text('Loading Customer...');
        $modalBody.html('<div class="sum-loading-modal"><span class="spinner is-active"></span> Loading data...</div>');
        $modalOverlay.addClass('active');
        
        // 2. AJAX call to fetch detailed data
        $.post(sum_customers_ajax.ajax_url, {
            action: 'sum_customers_frontend_get_single_cssc', 
            nonce: sum_customers_ajax.nonce,
            customer_id: customerId
        }, function(resp) {
            if (resp && resp.success && resp.data) {
                const customer = resp.data;
                displayCustomerDetails(customer);
            } else {
                $modalName.text('Error');
                $modalBody.html('<p class="sum-error-message">Failed to load customer details.</p>');
            }
        }).fail(() => {
            $modalName.text('Error');
            $modalBody.html('<p class="sum-error-message">Connection error. Could not load details.</p>');
        });
    }


    /**
     * Renders the full unit and pallet history in a list format.
     */
    function renderRentalHistory(customer) {
        const allRentals = (customer.current_units || [])
            .map(u => ({ name: u, type: 'Unit', status: 'Active' }))
            .concat((customer.current_pallets || [])
                .map(p => ({ name: p, type: 'Pallet', status: 'Active' }))
            )
            .concat((customer.past_units || [])
                .map(u => ({ name: u, type: 'Unit', status: 'Past' }))
            )
            .concat((customer.past_pallets || [])
                .map(p => ({ name: p, type: 'Pallet', status: 'Past' }))
            );

        if (allRentals.length === 0) {
            return '<p>No rental history recorded.</p>';
        }

        const listItems = allRentals.map(r => `
            <li>
                <span class="sum-rental-item sum-rental-${r.type.toLowerCase()}">${escapeHtml(r.name)}</span>
                (${r.type}) - 
                <span class="sum-status sum-status-${r.status.toLowerCase()}">${r.status}</span>
            </li>
        `).join('');

        return `<ul class="sum-rental-history-list">${listItems}</ul>`;
    }



// --- 2. Update displayCustomerDetails function (Replace existing function) ---

/**
 * Populates the modal with the received customer data.
 * @param {object} customer The detailed customer data object.
 */
function displayCustomerDetails(customer) {
    // Escape HTML utility (must be available in the file scope)
    const escapeHtml = (str) => {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', 
            '"': '&quot;', "'": '&#039;'
        }[m]));
    };
    
    $modalName.text(escapeHtml(customer.name) || 'Customer Details');
    
    // Check if there are any unpaid invoices
    const hasUnpaid = customer.unpaid_invoices && customer.unpaid_invoices.length > 0;
    
    // --- Primary Contact HTML Block (From previous step) ---
    let contactHtml = '';
    
    if (customer.full_email) {
        contactHtml += `<p><strong>Email:</strong> <a href="mailto:${escapeHtml(customer.full_email)}">${escapeHtml(customer.full_email)}</a></p>`;
    }
    if (customer.full_phone) {
        contactHtml += `<p><strong>Phone:</strong> <a href="tel:${escapeHtml(customer.full_phone)}">${escapeHtml(customer.full_phone)}</a></p>`;
    }
    if (customer.whatsapp) {
        const whatsappLink = `https://wa.me/${escapeHtml(customer.whatsapp).replace(/[^0-9+]/g, '')}`;
        contactHtml += `<p><strong>WhatsApp:</strong> <a href="${whatsappLink}" target="_blank">${escapeHtml(customer.whatsapp)}</a></p>`;
    }

    // --- Secondary Contact HTML Block (From previous step) ---
    let secondaryContactHtml = '';
    if (customer.secondary_name) {
         let secondaryDetails = '';
         // ... (Logic to build secondaryDetails remains the same as in previous response)
         if (customer.secondary_email) {
             secondaryDetails += `<p><strong>Email:</strong> <a href="mailto:${escapeHtml(customer.secondary_email)}">${escapeHtml(customer.secondary_email)}</a></p>`;
         }
         if (customer.secondary_phone) {
             secondaryDetails += `<p><strong>Phone:</strong> <a href="tel:${escapeHtml(customer.secondary_phone)}">${escapeHtml(customer.secondary_phone)}</a></p>`;
         }
         if (customer.secondary_whatsapp) {
             const whatsappLink = `https://wa.me/${escapeHtml(customer.secondary_whatsapp).replace(/[^0-9+]/g, '')}`;
             secondaryDetails += `<p><strong>WhatsApp:</strong> <a href="${whatsappLink}" target="_blank">${escapeHtml(customer.secondary_whatsapp)}</a></p>`;
         }

         secondaryContactHtml += `
             <div class="sum-detail-section sum-secondary-contact">
                 <h4>Secondary Contact: ${escapeHtml(customer.secondary_name)}</h4>
                 ${secondaryDetails || '<p>No specific contact details available for secondary person.</p>'}
             </div>`;
    }
    
    // --- Account Status & Billing Section (UPDATED) ---
    
    let statusContent = `
        <p><strong>Status:</strong> 
            <span class="sum-status sum-status-${customer.status}">
                ${customer.status === 'active' ? 'Active' : 'Past Customer'}
            </span>
        </p>
        <p><strong>Last Payment Date:</strong> ${customer.last_payment_date || 'N/A'}</p>
        <p><strong>Total Paid:</strong> ${customer.total_payments_amount ? `€${customer.total_payments_amount}` : '€0.00'}</p>
    `;

    if (hasUnpaid) {
        // List unpaid items
        const unpaidList = customer.unpaid_invoices.map(item => `
            <li class="sum-unpaid-item">
                <span class="sum-rental-item sum-rental-${item.type.toLowerCase()}">${escapeHtml(item.name)}</span>
                <span class="sum-unpaid-amount">€${item.amount} (${escapeHtml(item.status)})</span>
            </li>
        `).join('');
        
        statusContent += `
            <div class="sum-unpaid-invoices-section">
                <h4>⚠️ Unpaid Invoices (${customer.unpaid_invoices.length} Items)</h4>
                <ul class="sum-unpaid-list">${unpaidList}</ul>
                <button class="button button-primary" id="modal-generate-invoice" data-customer-id="${customer.id}">
                    <span class="dashicons dashicons-media-document"></span> Generate Full Invoice
                </button>
                <div id="invoice-status-${customer.id}" class="sum-invoice-status-message"></div>
            </div>
        `;
    } else {
        statusContent += '<p class="sum-status-success">✅ All active invoices are currently paid.</p>';
    }

    // Re-assembly of the main detailsHtml content
    const detailsHtml = `
        <div class="sum-detail-section">
            <h4>Primary Contact Information</h4>
            ${contactHtml || '<p>No primary contact information available.</p>'}
        </div>
        
        ${secondaryContactHtml}
        
        <div class="sum-detail-section">
            <h4>Account Status & Billing</h4>
            ${statusContent}
        </div>
        
        <div class="sum-detail-section">
            <h4>Full Rental History</h4>
            ${renderRentalHistory(customer)}
        </div>
    `;

    $modalBody.html(detailsHtml);
}


// --- 3. Add Invoice Generation Handler (New Event Handler) ---

// --- Invoice Generation Handler ---
$modalOverlay.on('click', '#modal-generate-invoice', function(e) {
    e.preventDefault();
    
    const $btn = $(this);
    const customerId = $btn.data('customer-id');
    const $statusDiv = $('#invoice-status-' + customerId);

    // Get REST parameters localized in module.php
    const restUrl   = sum_customers_ajax.rest_url;
const restNonce = sum_customers_ajax.wp_rest_nonce;

if (!restUrl) {
  // Fallback to admin-ajax for INVOICE generation
  $.post(getAdminAjaxUrl(), {
  action: 'sum_customers_frontend_generate_invoice',
  nonce:  (window.sum_customers_ajax && sum_customers_ajax.nonce) || '',
  customer_id: customerId
})
  .done(function(resp){
    if (typeof resp === 'string' && resp.trim() === '0') {
      alert('Please log in.');
      return;
    }
    if (resp && resp.success && resp.data && resp.data.pdf_url) {
      window.open(resp.data.pdf_url, '_blank');
    } else {
      alert((resp && resp.data && resp.data.message) || 'Failed to generate invoice.');
    }
  })
  .fail(function(xhr){
    alert('Server error generating invoice (' + (xhr.status || '??') + ').');
  });
  return;
}

    $btn.prop('disabled', true).find('.dashicons').addClass('spin');
    $statusDiv.removeClass('sum-status-error sum-status-success').html('<span class="dashicons dashicons-update"></span> Generating invoice...');

    // Use AJAX method configured for the REST endpoint
    $.ajax({
        url: restUrl,
        method: 'POST',
        dataType: 'json',
        // Send customer ID in the request body for the REST endpoint
        data: { customer_id: customerId }, 
        headers: {
            // Send the REST nonce via header for security
            'X-WP-Nonce': restNonce
        }
    }).done(function(resp) {
        $btn.prop('disabled', false).find('.dashicons').removeClass('spin');

        if (resp && resp.pdf_url) {
            $statusDiv.addClass('sum-status-success').html('✅ Invoice generated. Opening PDF...');
            // Open the PDF in a new tab
            window.open(resp.pdf_url, '_blank');
        } else {
            // Handle success responses that contain a message (e.g., "No unpaid items to invoice.")
            const message = (resp && resp.message) || 'Invoice generation failed.';
            $statusDiv.removeClass('sum-status-success').addClass('sum-status-error').html('❌ ' + message);
        }
    }).fail(function(xhr) {
        $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
        
        // Robust error parsing for the REST API failure response
        let message = 'Connection error. Could not generate invoice.';
        try {
            const json = JSON.parse(xhr.responseText);
            // Extract message from standard WP REST error format (json.message or json.data.message)
            message = (json.message || (json.data && json.data.message)) || message;
        } catch(e) {
            // If the response is not parseable (e.g., a 500 fatal error), use a generic message
        }
        
        $statusDiv.addClass('sum-status-error').html('❌ ' + message);
    });
});


function closeCustomerDetailsModal() {
    $modalOverlay.removeClass('active');
    // Optional: clear content to ensure next load shows spinner
    $modalBody.empty(); 
}

// --- UPDATE EVENT HANDLERS ---

// Remove the placeholder alert and implement the modal open function
$customerList.off('click', '.sum-card-action-btn').on('click', '.sum-card-action-btn', function(e) {
    e.preventDefault();
    const customerId = $(this).data('customer-id');
    openCustomerDetailsModal(customerId);
});

// Close modal handlers (for X button and footer button)
$modalOverlay.on('click', '.sum-modal-close, .sum-modal-overlay', function(e) {
    // Only close if clicking on the close button, or the overlay itself (not the content)
    if ($(e.target).hasClass('sum-modal-close') || $(e.target).is($modalOverlay)) {
        closeCustomerDetailsModal();
    }
});

// Close modal when ESC key is pressed
$(document).on('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCustomerDetailsModal();
    }
});

    // --- Event Handlers ---

    // Filter button click handler
    $filterBtns.on('click', function() {
        $filterBtns.removeClass('active');
        $(this).addClass('active');
        currentFilter = $(this).data('filter');
        filterAndRenderCustomers();
    });

    // Search input/button handler
    $('#frontend-search-btn').on('click', function(e) {
        e.preventDefault();
        filterAndRenderCustomers();
    });
    $searchField.on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            filterAndRenderCustomers();
        }
    });

    // View toggle handler
    $viewToggle.on('click', function() {
        const view = $(this).data('view');
        if (view) {
            updateViewMode(view);
        }
    });

    // Action button click handler: Opens the details modal
$customerList.off('click', '.sum-card-action-btn').on('click', '.sum-card-action-btn', function(e) {
    e.preventDefault();
    const customerId = $(this).data('customer-id');
    openCustomerDetailsModal(customerId);
});

    // --- Initialization ---

    // Set initial filter active class (based on the default 'all')
    $filterBtns.filter(`[data-filter="${currentFilter}"]`).addClass('active');
    
    // Initial data load
    loadCustomers();

})(jQuery);