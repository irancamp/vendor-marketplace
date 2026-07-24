/**
 * پرکردن خودکار مقدار فعلی «فروشنده محصول» هنگام باز شدن Quick Edit
 * (تکنیک استاندارد وردپرس برای افزودن فیلد سفارشی به Quick Edit)
 */
jQuery( function ( $ ) {
	'use strict';

	if ( typeof inlineEditPost === 'undefined' ) return;

	var vm_wp_inline_edit = inlineEditPost.edit;

	inlineEditPost.edit = function ( id ) {
		vm_wp_inline_edit.apply( this, arguments );

		var post_id = 0;
		if ( typeof id === 'object' ) {
			post_id = parseInt( this.getId( id ), 10 );
		}

		if ( post_id > 0 ) {
			var $editRow = $( '#edit-' + post_id );
			var vendorId = $( '#vm_vendor_inline_' + post_id ).text().trim();
			$editRow.find( 'select.vm-quick-edit-vendor' ).val( vendorId );
		}
	};
} );
