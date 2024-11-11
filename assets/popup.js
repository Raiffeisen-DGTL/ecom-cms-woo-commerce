

jQuery( function ( $ ) {

	console.log('tsesettseset');
	let rf_popup = {
		$order_review: $( '#order_review' ),
		$checkout_form: $( 'form.checkout' ),
		paymentOrderNoDefault: true,
		init: function () {

			if ( $( document.body ).hasClass( 'woocommerce-order-pay' ) ) {
				this.$order_review.off( 'submit' );
				this.$order_review.on( 'submit', rf_popup.paymentOrder );
			}
			// change checkout logic for popup
			this.$checkout_form.on( 'checkout_place_order_rf', rf_popup.placeOrder );
		},
		paymentOrder: function ( event ) {
			if (rf_popup.paymentOrderNoDefault) {
				event.preventDefault();

				var $form = $( this );
				$form.addClass( 'processing' );

				rf_popup.blockOnSubmit( $form );

				$.ajaxSetup( { dataFilter: rf_popup.dataFilter } );

				$.ajax( {
					type:     'POST',
					url:      window.location,
					data:     $form.serialize(),
					dataType: 'json',
					success:  rf_popup.submitSuccess,
					error:    function () {
						rf_popup.paymentOrderNoDefault = false;
						$form.trigger( 'submit' );
					}
				} );
			}
		},
		placeOrder: function () {

			var $form = $( this );
			$form.addClass( 'processing' );

			rf_popup.blockOnSubmit( $form );

			$.ajaxSetup( { dataFilter: rf_popup.dataFilter } );

			$.ajax( {
				type:    'POST',
				url:      wc_checkout_params.checkout_url,
				data:     $form.serialize(),
				dataType: 'json',
				success:  rf_popup.submitSuccess,
				error:    rf_popup.submitError
			} );

			return false;
		},
		dataFilter: function( raw_response, dataType ) {
			// We only want to work with JSON
			if ( 'json' !== dataType ) {
				return raw_response;
			}

			if ( rf_popup.is_valid_json( raw_response ) ) {
				return raw_response;
			} else {
				// Attempt to fix the malformed JSON
				let maybe_valid_json = raw_response.match( /{"result.*}/ );

				if ( null === maybe_valid_json ) {
					console.log( 'Unable to fix malformed JSON' );
				} else if ( rf_popup.is_valid_json( maybe_valid_json[0] ) ) {
					console.log( 'Fixed malformed JSON. Original:' );
					console.log( raw_response );
					raw_response = maybe_valid_json[0];
				} else {
					console.log( 'Unable to fix malformed JSON' );
				}
			}

			return raw_response;
		},
		submitError: function( jqXHR, textStatus, errorThrown ) {
			rf_popup.submit_error( '<div class="woocommerce-error">' + errorThrown + '</div>' );
		},
		submitSuccess: function( result ) {
			try {
				if ( 'success' === result.result ) {
					console.log(result);
                    console.log('result',result.popup_type);
                    const paymentPage = new PaymentPageSdk( result.public_id, {
                        url: result.payurl
                    });

					var stylesParsed = {};
					if (result.styles != "") {
						stylesParsed = JSON.parse(`{${result.styles}}`);
					}

                    if (result.receipt) {
                        console.log(result.receipt);
                        if (result.popup_type == 'popup') {
                            paymentPage.openPopup({
                                amount: result.amount,
                                orderId: result.order_id,
                                successUrl: result.success,
                                paymentMethod: result.paymentMethod,
                                receipt: result.receipt,
                                style: stylesParsed,
                            }).then(function() {
                                console.log("Спасибо");
                                window.location = result.success;
                            }).catch(function(err) {
                                console.log("Неудача");
                                rf_popup.submit_error("Неудача");
                                window.location.href = "/my-account/orders/";
                                throw err;
                             });
                        } else {
                            paymentPage.replace({
                                amount: result.amount,
                                orderId: result.order_id,
                                successUrl: result.success,
                                paymentMethod: result.paymentMethod,
                                receipt: result.receipt,
                                style: stylesParsed,
                            }).then(function() {
                                console.log("Спасибо");
                                window.location = result.success;
                            }).catch(function(err) {
                                console.log("Неудача");
                                rf_popup.submit_error("Неудача");
                                window.location.href = "/my-account/orders/";
                                throw err;
                             });
                        }
                    } else {
                        if (result.popup_type == 'popup') {
                            paymentPage.openPopup({
                                amount: result.amount,
                                orderId: result.order_id,
                                successUrl: result.success,
                                paymentMethod: result.paymentMethod,
                                style: stylesParsed,
                            }).then(function() {
                                console.log("Спасибо");
                                window.location = result.success;
                            }).catch(function(err) {
                                console.log("Неудача");
                                rf_popup.submit_error("Неудача");
                                window.location.href = "/my-account/orders/";
                                throw err;
                             });
                        } else {
                            paymentPage.replace({
                                amount: result.amount,
                                orderId: result.order_id,
                                successUrl: result.success,
                                paymentMethod: result.paymentMethod,
                                style: stylesParsed,
                            }).then(function() {
                                console.log("Спасибо");
                                window.location = result.success;
                            }).catch(function(err) {
                                console.log("Неудача");
                                rf_popup.submit_error("Неудача");
                                window.location.href = "/my-account/orders/";
                                throw err;
                         });
                        }
                    }
				} else if ( 'failure' === result.result ) {
					throw 'Result failure';
				} else {
					throw 'Invalid response';
				}
			} catch( err ) {
				console.error( 'Error:', err );

				// Reload page
				if ( true === result.reload ) {
					window.location.reload();
					return;
				}

				// Trigger update in case we need a fresh nonce
				if ( true === result.refresh ) {
					$( document.body ).trigger( 'update_checkout' );
				}

				// Add new errors
				if ( result.messages ) {
					rf_popup.submit_error( result.messages );
				} else {
					rf_popup.submit_error( '<div class="woocommerce-error">' + wc_checkout_params.i18n_checkout_error + '</div>' );
				}
			}
		},
		submit_error: function( error_message ) {
			$( '.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message' ).remove();
			rf_popup.$checkout_form.prepend( '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + error_message + '</div>' );
			rf_popup.$checkout_form.removeClass( 'processing' ).unblock();
			rf_popup.$checkout_form.find( '.input-text, select, input:checkbox' ).trigger( 'validate' ).blur();
			rf_popup.scroll_to_notices();
			$( document.body ).trigger( 'checkout_error' );
		},
		scroll_to_notices: function() {
			let scrollElement = $( '.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout' );

			if ( ! scrollElement.length ) {
				scrollElement = $( '.form.checkout' );
			}
			$.scroll_to_notices( scrollElement );
		},
		blockOnSubmit: function( $form ) {
			let form_data = $form.data();

			if ( 1 !== form_data['blockUI.isBlocked'] ) {
				$form.block({
					message:    null,
					overlayCSS: {
						background: '#fff',
						opacity:    0.6
					}
				});
			}
		},
		is_valid_json: function( raw_json ) {
			try {
				let json = $.parseJSON( raw_json );

				return ( json && 'object' === typeof json );
			} catch ( e ) {
				return false;
			}
		},
	};

	rf_popup.init();

} );
