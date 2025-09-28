<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1>Customers</h1>

  <form method="post" action="options.php" style="margin: 12px 0 24px;">
    <?php settings_fields('sum_customers_settings'); ?>
    <label>
      <input type="checkbox" name="sum_customers_enabled" value="1"
        <?php checked( get_option('sum_customers_enabled','1'), '1'); ?>>
      Enable Customers module
    </label>
    <?php submit_button('Save', 'secondary', 'submit', false); ?>
  </form>

  <div style="margin:12px 0;">
    <input type="text" id="sumc-search" placeholder="Search name/email/phone" class="regular-text" />
    <button class="button" id="sumc-refresh">Search</button>
    <button class="button button-primary" id="sumc-sync">Sync from Units & Pallets</button>
    <span id="sumc-status" style="margin-left:10px;"></span>
  </div>

  <table class="widefat striped" id="sumc-table">
    <thead>
      <tr>
        <th>Name</th><th>Email</th><th>Phone</th><th>WhatsApp</th><th>Source</th><th>Last Seen</th><th></th>
      </tr>
    </thead>
    <tbody></tbody>
  </table>
</div>

<script>
(function($){
  const nonce = '<?php echo esc_js( wp_create_nonce('sum_customers_nonce') ); ?>';

  function loadCustomers(q='') {
    $('#sumc-status').text('Loading...');
    $.post(ajaxurl, {action:'sum_customers_get', nonce:nonce, search:q}, function(res){
      $('#sumc-status').text('');
      const $tb = $('#sumc-table tbody').empty();
      if (!res || !res.success || !Array.isArray(res.data) || res.data.length===0) {
        $tb.append('<tr><td colspan="7">No customers found.</td></tr>');
        return;
      }
      res.data.forEach(function(c){
        $tb.append(
          '<tr data-id="'+(c.id||'')+'">'+
          '<td><input class="sumc-name" type="text" value="'+(c.full_name||'')+'"></td>'+
          '<td><input class="sumc-email" type="email" value="'+(c.email||'')+'"></td>'+
          '<td><input class="sumc-phone" type="text" value="'+(c.phone||'')+'"></td>'+
          '<td><input class="sumc-wa" type="text" value="'+(c.whatsapp||'')+'"></td>'+
          '<td>'+(c.source||'')+'</td>'+
          '<td>'+(c.last_seen||'')+'</td>'+
          '<td>'+
            '<button class="button button-primary sumc-save">Save</button> '+
            '<button class="button sumc-del">Delete</button>'+
          '</td>'+
          '</tr>'
        );
      });
    });
  }

  $('#sumc-refresh').on('click', function(e){
    e.preventDefault();
    loadCustomers($('#sumc-search').val());
  });

  $('#sumc-sync').on('click', function(e){
    e.preventDefault();
    $('#sumc-status').text('Syncing...');
    $.post(ajaxurl, {action:'sum_customers_sync', nonce:nonce}, function(res){
      if (res && res.success) {
        $('#sumc-status').text('Synced: +'+(res.data.inserted||0)+' / updated '+(res.data.updated||0));
        loadCustomers($('#sumc-search').val());
      } else {
        $('#sumc-status').text('Sync failed');
      }
    });
  });

  $('#sumc-table').on('click', '.sumc-save', function(){
    const $tr = $(this).closest('tr');
    const id  = $tr.data('id')||'';
    const payload = {
      action: 'sum_customers_save', nonce: nonce,
      id: id,
      full_name: $tr.find('.sumc-name').val(),
      email:     $tr.find('.sumc-email').val(),
      phone:     $tr.find('.sumc-phone').val(),
      whatsapp:  $tr.find('.sumc-wa').val(),
      source:    'manual'
    };
    $('#sumc-status').text('Saving...');
    $.post(ajaxurl, payload, function(res){
      $('#sumc-status').text(res && res.success ? 'Saved' : 'Save failed');
      loadCustomers($('#sumc-search').val());
    });
  });

  $('#sumc-table').on('click', '.sumc-del', function(){
    if (!confirm('Delete this customer?')) return;
    const id = $(this).closest('tr').data('id')||'';
    $('#sumc-status').text('Deleting...');
    $.post(ajaxurl, {action:'sum_customers_del', nonce:nonce, id:id}, function(res){
      $('#sumc-status').text(res && res.success ? 'Deleted' : 'Delete failed');
      loadCustomers($('#sumc-search').val());
    });
  });

  // Initial
  loadCustomers();
})(jQuery);
</script>
