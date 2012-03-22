function set_active_widget(instance_id) {
	self.IW_instance = instance_id;
}

function send_to_editor(h) {
	// ignore content returned from media uploader and use variables passed to window instead
	var picked = jQuery.parseJSON(h);

	// store attachment id in hidden field
	jQuery( '#sicem-default-featured-image' ).val( picked.id );
	jQuery( '#sicem-default-featured-image-display' ).html( picked.sample );
	
	// close thickbox
	tb_remove();

	// change button text
	//jQuery('#add_image-widget-'+self.IW_instance+'-image').html(jQuery('#add_image-widget-'+self.IW_instance+'-image').html().replace(/Add Image/g, 'Change Image'));
}

jQuery(document).ready(function() {
	//jQuery("#sicem-default-featured-image").hide();
	jQuery('<div style="margin-top: 5px; margin-bottom: 5px"><a href="media-upload.php?type=image&amp;sic_pick_feed_id=*&amp;TB_iframe=1" class="button thickbox-image-widget">Pick an image...</a> <a class="button thickbox-image-widget-remove" href="#">X Remove</a></div>').insertBefore(jQuery('#sicem-default-featured-image'));
	
	jQuery("body").click(function(event) {
		if (jQuery(event.target).is('a.thickbox-image-widget')) {
			tb_show("Add an Image", event.target.href, false);
			return false;
		} else if (jQuery(event.target).is('a.thickbox-image-widget-remove')) {
			jQuery('#sicem-default-featured-image').val( '' );
			jQuery('#sicem-default-featured-image-display').html( '' );
			return false;
		}
	});
	// Modify thickbox link to fit window. Adapted from wp-admin\js\media-upload.dev.js.
	jQuery('a.thickbox-image-widget').each( function() {
		var href = jQuery(this).attr('href'), width = jQuery(window).width(), H = jQuery(window).height(), W = ( 720 < width ) ? 720 : width;
		if ( ! href ) return;
		href = href.replace(/&width=[0-9]+/g, '');
		href = href.replace(/&height=[0-9]+/g, '');
		jQuery(this).attr( 'href', href + '&width=' + ( W - 80 ) + '&height=' + ( H - 85 ) );
	});
});

