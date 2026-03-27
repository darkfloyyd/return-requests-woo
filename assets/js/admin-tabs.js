/**
 * Return Requests for WooCommerce — admin panel tabs
 */
jQuery( document ).ready( function ( $ ) {
	function activateTabFromHash() {
		if ( window.location.hash ) {
			var hash = window.location.hash.replace( '#', '' );
			var $targetTab = $( '.woo-return-tab[data-tab="' + hash + '"]' );
			if ( $targetTab.length ) {
				$( '.woo-return-tab' ).removeClass( 'active' );
				$targetTab.addClass( 'active' );
				$( '.woo-return-tab-content' ).removeClass( 'active' );
				$( '#' + hash ).addClass( 'active' );
			}
		}
	}

	// If hash is provided in URL (e.g. #tab-pages) on load, activate this tab
	activateTabFromHash();

	// Listen for hash changes (e.g. clicking an anchor in a notice on the same page)
	$( window ).on( 'hashchange', function() {
		activateTabFromHash();
	} );

	// Handle tab clicks
	$( '.woo-return-tab' ).on( 'click', function () {
		$( '.woo-return-tab' ).removeClass( 'active' );
		$( this ).addClass( 'active' );

		var tabId = $( this ).data( 'tab' );
		$( '.woo-return-tab-content' ).removeClass( 'active' );
		$( '#' + tabId ).addClass( 'active' );

		// Update hash in URL without reloading the page, 
		// so page refresh keeps the current tab
		if ( history.replaceState ) {
			history.replaceState( null, null, '#' + tabId );
		} else {
			window.location.hash = '#' + tabId;
		}
	} );

    // Handle AJAX System Toggle Status
    $('#woo_return_system_enabled').on('change', function() {
        var isChecked = $(this).is(':checked') ? '1' : '0';
        var nonce = $('#woo_return_system_status_nonce_field').val();
        var $statusText = $('#woo-return-status-text');
        
        $statusText.css('opacity', '0.5');

        $.ajax({
            url: wooReturnAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'woo_return_toggle_system_status',
                status: isChecked,
                nonce: nonce
            },
            success: function(response) {
                if(response.success) {
                    $statusText.text(response.data.text);
                    $statusText.css('color', response.data.color);
                    $statusText.css('opacity', '1');
                } else {
                    alert(response.data || 'Error updating status.');
                    $statusText.css('opacity', '1');
                }
            },
            error: function() {
                alert('Connection error while updating system status.');
                $statusText.css('opacity', '1');
            }
        });
    });
} );
