// Utility functions for UI interactions.
// Assumes this file is loaded before sum-main.js or that sumState/sumActions are managed correctly.
// For this example, we'll make functions global or use a shared namespace.

function showSuccess(message) {
    // Create a modern toast notification
    const toast = jQuery(`
        <div class="sum-frontend-toast sum-frontend-toast-success">
            <div class="sum-frontend-toast-icon">✅</div>
            <div class="sum-frontend-toast-message">${message}</div>
        </div>
    `);
    
    jQuery('body').append(toast);
    
    setTimeout(() => { toast.addClass('sum-frontend-toast-show'); }, 100);
    setTimeout(() => { toast.removeClass('sum-frontend-toast-show'); setTimeout(() => toast.remove(), 300); }, 3000);
}

function showError(message) {
    // Create a modern toast notification
    const toast = jQuery(`
        <div class="sum-frontend-toast sum-frontend-toast-error">
            <div class="sum-frontend-toast-icon">❌</div>
            <div class="sum-frontend-toast-message">${message}</div>
        </div>
    `);
    
    jQuery('body').append(toast);
    
    setTimeout(() => { toast.addClass('sum-frontend-toast-show'); }, 100);
    setTimeout(() => { toast.removeClass('sum-frontend-toast-show'); setTimeout(() => toast.remove(), 300); }, 5000);
}

// Stats Update
function updateStats(units) {
    const total = units.length;
    const occupied = units.filter(unit => parseInt(unit.is_occupied)).length;
    const available = total - occupied;
    const unpaid = units.filter(unit => parseInt(unit.is_occupied) && unit.payment_status !== 'paid').length;
    
    jQuery('#frontend-total-units').text(total);
    jQuery('#frontend-occupied-units').text(occupied);
    jQuery('#frontend-available-units').text(available);
    jQuery('#frontend-unpaid-units').text(unpaid);
}

// Modal Functions
function openModal(unit = null) {
    window.sumState.editingUnit = unit;
    
    if (unit) {
        jQuery('#frontend-modal-title').text('Edit Storage Unit');
        populateForm(unit);
    } else {
        jQuery('#frontend-modal-title').text('Add New Storage Unit');
        resetForm();
    }
    
    jQuery('#frontend-unit-modal').show();
    jQuery('body').css('overflow', 'hidden');
}

function closeModal() {
    jQuery('#frontend-unit-modal').hide();
    jQuery('body').css('overflow', 'auto');
    window.sumState.editingUnit = null;
    resetForm();
}

function openBulkModal() {
    jQuery('#frontend-bulk-add-modal').show();
    jQuery('body').css('overflow', 'hidden');
    updateBulkPreview();
}

function closeBulkModal() {
    jQuery('#frontend-bulk-add-modal').hide();
    jQuery('body').css('overflow', 'auto');
    jQuery('#frontend-bulk-add-form')[0].reset();
}

// Form Functions
function populateForm(unit) {
    jQuery('#frontend-unit-id').val(unit.id);
    jQuery('#frontend-unit-name').val(unit.unit_name);
    jQuery('#frontend-size').val(unit.size || '');
    jQuery('#frontend-sqm').val(unit.sqm || '');
    jQuery('#frontend-monthly-price').val(unit.monthly_price || '');
    jQuery('#frontend-website-name').val(unit.website_name || '');
    jQuery('#frontend-is-occupied').prop('checked', parseInt(unit.is_occupied));
    jQuery('#frontend-period-from').val(unit.period_from || '');
    jQuery('#frontend-period-until').val(unit.period_until || '');
    jQuery('#frontend-payment-status').val(unit.payment_status || 'paid');
    jQuery('#frontend-primary-name').val(unit.primary_contact_name || '');
    jQuery('#frontend-primary-phone').val(unit.primary_contact_phone || '');
    jQuery('#frontend-primary-whatsapp').val(unit.primary_contact_whatsapp || '');
    jQuery('#frontend-primary-email').val(unit.primary_contact_email || '');
    jQuery('#frontend-secondary-name').val(unit.secondary_contact_name || '');
    jQuery('#frontend-secondary-phone').val(unit.secondary_contact_phone || '');
    jQuery('#frontend-secondary-whatsapp').val(unit.secondary_contact_whatsapp || '');
    jQuery('#frontend-secondary-email').val(unit.secondary_contact_email || '');
    
    const hasSecondary = unit.secondary_contact_name && unit.secondary_contact_name.trim() !== '';
    jQuery('#frontend-has-secondary-contact').prop('checked', hasSecondary);
    
    toggleOccupancyDetails();
    toggleSecondaryContact();
}

function resetForm() {
    jQuery('#frontend-unit-form')[0].reset();
    jQuery('#frontend-unit-id').val('');
    jQuery('#frontend-has-secondary-contact').prop('checked', false);
    jQuery('#frontend-is-occupied').prop('checked', false); // Ensure this is unchecked for new units
    jQuery('#frontend-payment-status').val('paid');
    toggleOccupancyDetails();
    toggleSecondaryContact();
}

function toggleOccupancyDetails() {
    const isOccupied = jQuery('#frontend-is-occupied').is(':checked');
    jQuery('#frontend-occupancy-details').toggle(isOccupied);
}

function toggleSecondaryContact() {
    const hasSecondary = jQuery('#frontend-has-secondary-contact').is(':checked');
    jQuery('#frontend-secondary-contact-section').toggle(hasSecondary);
}

function updateBulkPreview() {
    const prefix = jQuery('#frontend-bulk-prefix').val() || 'A';
    const start = parseInt(jQuery('#frontend-bulk-start').val()) || 1;
    const end = parseInt(jQuery('#frontend-bulk-end').val()) || 10;
    
    const count = Math.max(0, end - start + 1);
    jQuery('#frontend-bulk-preview-text').text(`Units ${prefix}${start} to ${prefix}${end} will be created (${count} units)`);
}

// Expose to global scope for use in sum-main.js
window.showSuccess = showSuccess;
window.showError = showError;
window.updateStats = updateStats;
window.openModal = openModal;
window.closeModal = closeModal;
window.openBulkModal = openBulkModal;
window.closeBulkModal = closeBulkModal;
window.toggleOccupancyDetails = toggleOccupancyDetails;
window.toggleSecondaryContact = toggleSecondaryContact;
window.updateBulkPreview = updateBulkPreview;