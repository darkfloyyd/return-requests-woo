/**
 * Return Form JS — bank account formatter and validation.
 *
 * Loaded on frontend pages via wp_enqueue_script( 'woo-return-form' ).
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var bankAccountInput = document.getElementById( 'bank_account' );
		if ( ! bankAccountInput ) {
			return;
		}

		// Check the selected format from the input data attribute
		var format = bankAccountInput.getAttribute( 'data-format' ) || 'polish';

		// Format input as user types: "12 3456 7890 1234 5678 9012 3456" for Polish format
		bankAccountInput.addEventListener( 'input', function () {
			if ( format !== 'polish' ) {
				// For IBAN or Custom, do not aggressively strip letters or spaces
				// Let WooCommerce/HTML5 validation handle it based on the backend rules.
				return;
			}

			var value = this.value.replace( /[^\d]/g, '' );

			if ( value.length > 26 ) {
				value = value.substring( 0, 26 );
			}

			if ( value.length > 0 ) {
				var formatted = '';

				// First 2 digits
				if ( value.length >= 2 ) {
					formatted = value.substring( 0, 2 ) + ' ';
				} else {
					formatted = value;
				}

				// Remaining digits in groups of 4
				for ( var i = 2; i < value.length; i += 4 ) {
					formatted += value.substring( i, Math.min( i + 4, value.length ) );
					if ( i + 4 < value.length ) {
						formatted += ' ';
					}
				}

				this.value = formatted;
			}
		} );

		// Validate before submit — show inline error instead of alert()
		var form = bankAccountInput.closest( 'form' );
		if ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				var value = bankAccountInput.value.replace( /\s/g, '' );
				var errorId = 'bank-account-error';
				var existing = document.getElementById( errorId );

				var hasError = false;

				if ( format === 'polish' ) {
					if ( value.length !== 26 || ! /^\d+$/.test( value ) ) {
						hasError = true;
					}
				} else if ( format === 'iban' ) {
					// Basic IBAN check (up to 34 alphanumeric characters)
					if ( value.length < 5 || value.length > 34 || ! /^[A-Z0-9]+$/i.test( value ) ) {
						hasError = true;
					}
				}

				if ( hasError ) {
					e.preventDefault();
					if ( ! existing ) {
						var err = document.createElement( 'p' );
						err.id = errorId;
						err.className = 'woocommerce-error woo-return-field-error';
						err.setAttribute( 'role', 'alert' );

						var errMsg = wooReturnFormData && wooReturnFormData.bankErrorMsg ? wooReturnFormData.bankErrorMsg : 'Account number format is invalid!';
						
						if ( format === 'iban' ) {
							errMsg = 'Please enter a valid IBAN containing up to 34 alphanumeric characters.';
						}

						err.textContent = errMsg;
						bankAccountInput.parentNode.insertBefore( err, bankAccountInput.nextSibling );
					}
					bankAccountInput.focus();
				} else if ( existing ) {
					existing.remove();
				}
			} );
		}
	} );
} )();
