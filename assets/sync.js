jQuery( function ( $ ) {

	let rf_sync = {
		window: $( window ),
		button: $( '#page-sync button' ),
		progress: $( '#page-sync progress' ),
		output: $( '#page-sync output' ),
		list: [ ],
		index: 0,
		add: function ( text, ...params ) {
			for ( let key in params ) {
				text = text.replace( "{" + key + "}", params[key] );
			}

			rf_sync.output.prepend( $( '<p>', { text: text } ) );
		},
		end: function ( text ) {
			rf_sync.button.toggleClass('hidden');
			rf_sync.progress.toggleClass('hidden');
			rf_sync.add( text );
			rf_sync.window.remove( 'beforeunload' );
		},
		request: function ( id, success ) {
			$.ajax( {
				type:     'POST',
				url:      woocommerce_payment_rf_sync.url,
				data:     {
					nonce:    woocommerce_payment_rf_sync.nonce,
					action:   'woocommerce_payment_rf_sync',
					order_id: id,
				},
				dataType: 'json',
				success:  function (data) {
					if (data.nonce) {
						woocommerce_payment_rf_sync.nonce = data.nonce;
					}

					if (data.message) {
						if (data.message === 'success') {
							rf_sync.output.first().text(
								rf_sync.output.first().text() + woocommerce_payment_rf_sync.success
							);
						} else {
							rf_sync.add(data.message);
						}
					}

					if (data.list) {
						rf_sync.list = data.list;
						rf_sync.index = 0;
					}

					if (success) {
						success();
					}
				},
				error:    function () {
					rf_sync.end(woocommerce_payment_rf_sync.error);
				}
			} );
		},
		each: function ( ) {
			if (rf_sync.index === rf_sync.list.length) {
				rf_sync.end( woocommerce_payment_rf_sync.end );

				return;
			}

			rf_sync.progress.attr( 'value', rf_sync.index / rf_sync.list.length * 100 );
			rf_sync.add( woocommerce_payment_rf_sync.single, rf_sync.index + 1, rf_sync.list.length, rf_sync.list[rf_sync.index] );
			rf_sync.request(rf_sync.list[rf_sync.index], rf_sync.each);
			rf_sync.index++;
		},
		beforeunload: function ( ) {
			return woocommerce_payment_rf_sync.beforeunload;
		},
		sync: function ( event ) {
			event.preventDefault( );
			rf_sync.button.toggleClass( 'hidden' );
			rf_sync.progress.toggleClass( 'hidden' );
			rf_sync.progress.attr( 'value', null );
			rf_sync.output.empty();
			rf_sync.window.on( 'beforeunload', rf_sync.beforeunload );
			rf_sync.request( null, rf_sync.each );
		},
		init: function ( ) {
			rf_sync.button.on( 'click', rf_sync.sync );
		},
	};

	rf_sync.init( );

} );
