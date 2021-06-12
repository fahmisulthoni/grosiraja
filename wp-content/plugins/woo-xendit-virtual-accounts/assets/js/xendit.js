/* global wc_xendit_params */
Xendit.setPublishableKey( wc_xendit_params.key );
jQuery( function( $ ) {
	'use strict';

	/**
	 * Object to handle Xendit payment forms.
	 */
	var wc_xendit_form = {

		/**
		 * Initialize event handlers and UI state.
		 */
		init: function() {
			// checkout page
			if ( $( 'form.woocommerce-checkout' ).length ) {
				this.form = $( 'form.woocommerce-checkout' );
			}
			
			$( 'form.woocommerce-checkout' )
				.on(
					'checkout_place_order_xendit_cc',
					this.onSubmit
				);

			// pay order page
			if ( $( 'form#order_review' ).length ) {
				this.form = $( 'form#order_review' );
			}

			$( 'form#order_review' )
				.on(
					'submit',
					this.onSubmit
				);

			// add payment method page
			if ( $( 'form#add_payment_method' ).length ) {
				this.form = $( 'form#add_payment_method' );
			}

			$( 'form#add_payment_method' )
				.on(
					'submit',
					this.onSubmit
				);

			$( document )
				.on(
					'change',
					'#wc-xendit_cc-cc-form :input',
					this.onCCFormChange
				)
				.on(
					'xenditError',
					this.onError
				)
				.on(
					'checkout_error',
					this.clearToken
				)
				.ready(function () {
					$('body').append('<div class="overlay" style="display: none;"></div>' +
		            	'<div id="three-ds-container" style="display: none;">' +
		                	'<iframe height="450" width="550" id="sample-inline-frame" name="sample-inline-frame"> </iframe>' +
		            	'</div>');

					$('.entry-content .woocommerce').prepend('<div id="woocommerce-error-custom-my" class="woocommerce-error" style="display:none"></div>');


					$('.overlay').css({'position': 'absolute','top': '0','left': '0','height': '100%','width': '100%','background-color': 'rgba(0,0,0,0.5)','z-index': '10'});
					$('#three-ds-container').css({'width': '550px','height': '450px','line-height': '200px','position': 'fixed','top': '25%','left': '40%','margin-top': '-100px','margin-left': '-150px','background-color': '#ffffff','border-radius': '5px','text-align': 'center','z-index': '9999'});
				});
		},

		isXenditChosen: function() {
			return $( '#payment_method_xendit_cc' ).is( ':checked' ) && ( ! $( 'input[name="wc-xendit-payment-token"]:checked' ).length || 'new' === $( 'input[name="wc-xendit-payment-token"]:checked' ).val() );
		},

		hasToken: function() {
			return 0 < $( 'input.xendit_token' ).length;
		},

		block: function() {
			wc_xendit_form.form.block({
				message: null,
				overlayCSS: {
					background: '#000',
					opacity: 0.5
				}
			});
		},

		unblock: function() {
			wc_xendit_form.form.unblock();
		},

		onError: function( e, response ) {
			var failure_reason, failure_title;
			if(typeof response.err != 'undefined') {
				failure_title = response.err.title || 'Failed';
				failure_reason = response.err.message || response.err.error_code;
			} else {
				failure_title = 'Failed';
				failure_reason = 'Unknown error has occurred. Please contact administrator for more details.';
			}

			$('#three-ds-container').hide();
			$('.overlay').hide();
			$('#woocommerce-error-custom-my').html("<b>" + failure_title + "</b><br>" + failure_reason);
			$('#woocommerce-error-custom-my').show();
			$('html, body').animate({ scrollTop: 0 }, 2000);

			wc_xendit_form.unblock();
			wc_xendit_form.clearToken();
		},

		onSubmit: function( e ) {
			if ( wc_xendit_form.isXenditChosen() && ! wc_xendit_form.hasToken()) {
				e.preventDefault();
				wc_xendit_form.block();

				// #xendit_cc- prefix comes from wc-gateway-xendit->id field
				var card       = $( '#xendit_cc-card-number' ).val().replace(/\s/g, ''),
					cvn        = $( '#xendit_cc-card-cvc' ).val(),
					expires    = $( '#xendit_cc-card-expiry' ).payment( 'cardExpiryVal' );
				
				// check if all card details are not empty
				if (!card || !cvn || !$( '#xendit_cc-card-expiry' ).val()) {
					var fields = [];
					if (!card) {
						fields.push('card number');
					}
					if (!cvn) {
						fields.push('security number');
					}
					if (!$( '#xendit_cc-card-expiry' ).val()) {
						fields.push('card expiry');
					}
					var err = {
						title: 'Missing Card Information',
						message: wc_xendit_params.missing_card_information.replace('{missing_fields}', fields.join(', '))
					}
					$( document ).trigger( 'xenditError', { err: err } );
					return false;
				}

				// check that card number == 16 digits
				if (card.length != 16) {
					var err = {
						title: 'Invalid Card Number Format',
						message: wc_xendit_params.incorrect_number
					}
					$( document ).trigger( 'xenditError', { err: err } );
					return false;
				}

				// validate card number
				if (!Xendit.card.validateCardNumber(card)) {
					var err = {
						title: 'Invalid Card Number',
						message: wc_xendit_params.invalid_number
					}
					$( document ).trigger( 'xenditError', { err: err } );
					return false;
				}

				// validate expiry format MM / YY
				if ($( '#xendit_cc-card-expiry' ).val().length != 7) {
					var err = {
						title: 'Invalid Card Expiry Format',
						message: wc_xendit_params.invalid_expiry
					}
					$( document ).trigger( 'xenditError', { err: err } );
					return false;
				}

				// validate cvc
				if (cvn.length < 3) {
					var err = {
						title: 'Invalid CVN/CVV Format',
						message: wc_xendit_params.invalid_cvc
					}
					$( document ).trigger( 'xenditError', { err: err } );
					return false;
				}
				
				var data = {
					"card_number"   	: card,
					"card_exp_month"	: String(expires.month).length === 1 ? '0' + String(expires.month) : String(expires.month),
					"card_exp_year" 	: String(expires.year),
					"card_cvn"      	: cvn,
					"is_multiple_use"	: true
				};

				wc_xendit_form.form.append( "<input type='hidden' class='card_cvn' name='card_cvn' value='" + data.card_cvn + "'/>" );
				
				Xendit.card.createToken( data, wc_xendit_form.onTokenizationResponse );

				// Prevent form submitting
				return false;
			}
		},

		onCCFormChange: function() {
			$( '.wc-xendit-error, .xendit_token', '.xendit_3ds_url', '.xendit_should_3ds',  '.xendit_should_3ds_error', '.masked_card_number').remove();

			//format expiry to MM / YY
			$( '#xendit_cc-card-expiry' ).prop( 'maxlength', 7 );

			//change cvc into password field
			$( '#xendit_cc-card-cvc' ).prop( 'type', 'password' );
		},

		onTokenizationResponse: function(err, response) {
			if (err) {
				$( document ).trigger( 'xenditError', { err: err } );
				return;
			}
			var token_id = response.id;

			wc_xendit_form.form.append( "<input type='hidden' class='xendit_token' name='xendit_token' value='" + token_id + "'/>" );
			wc_xendit_form.form.append( "<input type='hidden' class='masked_card_number' name='masked_card_number' value='" + response.masked_card_number + "'/>" );

			var data = {
				"token_id"   : token_id
			};

			if(wc_xendit_params.can_use_dynamic_3ds === "1"){
				Xendit.card.threeDSRecommendation( data, wc_xendit_form.on3DSRecommendationResponse );
			} else {
				wc_xendit_form.form.submit();
			}
			
			// Prevent form submitting
			return false;
		},

		clearToken: function() {
			$( '.xendit_token' ).remove();
			$( '.masked_card_number' ).remove();
			$( '.xendit_3ds_url' ).remove();
			$( '.xendit_should_3ds' ).remove();
			$( '.xendit_should_3ds_error' ).remove();
		},

		on3DSRecommendationResponse: function(err, response) {
			if (err) {
				wc_xendit_form.form.submit();
				return;
			}

			wc_xendit_form.form.append( "<input type='hidden' class='xendit_should_3ds' name='xendit_should_3ds' value='" + response.should_3ds + "'/>" );
			wc_xendit_form.form.submit();
			
			return;
		}
	};

	wc_xendit_form.init();
} );