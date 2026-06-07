(function ( $ ) {
	'use strict';

	$(function () {
		var settings = window.bhWpMailboxesSingleEmail || {};

		function remoteAction( action, $btn ) {
			$btn.prop( 'disabled', true );

			$.post(
				settings.ajaxUrl || ajaxurl,
				{
					action:    action,
					post_id:   settings.postId,
					_wpnonce:  settings.nonce,
				},
				function ( response ) {
					if ( response.success && response.data && response.data.status_html !== undefined ) {
						$( '.bh-email-remote-status' ).html( response.data.status_html );
					}
					$btn.prop( 'disabled', false );
				}
			).fail( function () {
				$btn.prop( 'disabled', false );
			} );
		}

		$( '#bh-email-mark-read' ).on( 'click', function ( e ) {
			e.preventDefault();
			remoteAction( 'bh_wp_mailboxes_mark_read', $( this ) );
		} );

		$( '#bh-email-mark-unread' ).on( 'click', function ( e ) {
			e.preventDefault();
			remoteAction( 'bh_wp_mailboxes_mark_unread', $( this ) );
		} );

		$( '#bh-email-delete-on-server' ).on( 'click', function ( e ) {
			e.preventDefault();
			if ( ! window.confirm( 'Delete this email on the remote server?' ) ) {
				return;
			}
			remoteAction( 'bh_wp_mailboxes_delete_on_server', $( this ) );
		} );

		// Auto-resize HTML iframe to its content height.
		$( 'iframe.bh-email-html-body' ).each( function () {
			var iframe = this;
			$( iframe ).on( 'load', function () {
				try {
					iframe.style.height = iframe.contentDocument.documentElement.scrollHeight + 'px';
				} catch ( e ) {
					// Cross-origin content blocked; leave default height.
				}
			} );
		} );
	} );

} )( jQuery );
