/*!
* Javascript to support transcode table on image page
*/
$( function () {
	function errorPopup( event ) {
		const tKey = $( event.target ).attr( 'data-transcodekey' );
		const $message = $( [
			document.createTextNode( mw.msg( 'timedmedia-reset-explanation' ) ),
			document.createElement( 'br' ),
			document.createElement( 'br' ),
			document.createTextNode( mw.msg( 'timedmedia-reset-areyousure' ) )
		] );

		event.preventDefault();

		OO.ui.confirm( $message, {
			title: mw.msg( 'timedmedia-reset' ),
			actions: [
				{
					action: 'accept',
					label: mw.msg( 'timedmedia-reset-button-reset' ),
					flags: [ 'primary', 'destructive' ]
				},
				{
					action: 'cancel',
					label: mw.msg( 'timedmedia-reset-button-cancel' ),
					flags: 'safe'
				}
			]
		} ).done( function ( confirmed ) {
			if ( !confirmed ) {
				return;
			}
			const api = new mw.Api();
			api.postWithEditToken( {
				action: 'transcodereset',
				transcodekey: tKey,
				title: mw.config.get( 'wgPageName' ),
				errorformat: 'html'
			} ).done( function () {
				// Refresh the page
				location.reload();
			} ).fail( function ( code, data ) {
				let errorText;
				if ( data.errors ) {
					errorText = data.errors[ 0 ][ '*' ];
				} else {
					errorText = mw.msg( 'timedmedia-reset-error' );
				}
				OO.ui.alert( errorText, {
					actions: [
						{
							action: 'ok',
							label: mw.msg( 'timedmedia-reset-button-dismiss' ),
							flags: 'safe'
						}
					]
				} );
			} );
		} );
	}

	// eslint-disable-next-line no-jquery/no-global-selector
	$( '.mw-filepage-transcodereset a' ).on( 'click', errorPopup );
} );
