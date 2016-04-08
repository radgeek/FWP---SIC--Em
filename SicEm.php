<?php
/*
Plugin Name: FWP+: SIC 'Em (Syndicated Image Capture)
Plugin URI: https://github.com/radgeek/FWP---SIC--Em
Description: A FeedWordPress filter that locally caches images in the feeds you syndicate. Images are stored in your WordPress uploads directory.
Author: Charles Johnson
Version: 2012.1128
Author URI: http://projects.radgeek.com
*/

require_once(dirname(__FILE__).'/sicwebimage.class.php');

// Get the path relative to the plugins directory in which FWP is stored
preg_match (
	'|'.preg_quote(WP_PLUGIN_DIR).'/(.+)$|',
	dirname(__FILE__),
	$ref
);

if (isset($ref[1])) :
	$sicem_path = $ref[1];
else : // Something went wrong. Let's just guess.
	$sicem_path = 'fwp-sic-em';
endif;

class SicEm {
	var $name;
	var $upload;
	
	function SicEm () {
		$this->name = strtolower(get_class($this));
		add_filter('syndicated_post', array(&$this, 'process_post'), 10, 2);
		add_filter('feedwordpress_update_complete', array(&$this, 'process_captured_images'), -1000, 1);
		add_action('feedwordpress_admin_page_posts_meta_boxes', array(&$this, 'add_settings_box'));
		add_action('feedwordpress_admin_page_posts_save', array(&$this, 'save_settings'), 10, 2);
		add_filter('feedwordpress_diagnostics', array(&$this, 'diagnostics'), 10, 2);
		
		// If user opts for it, put a gallery at the end of syndicated posts
		add_filter('the_content', array(&$this, 'the_content'), 200000, 2);
		
		global $pagenow;
		global $sicem_path;
		if (WP_ADMIN) : // set up image picker through massive fuckery
			add_action('admin_init', array(&$this, 'fix_async_upload_image'));
			
			if ( FeedWordPressSettingsUI::is_admin() ) :
				wp_enqueue_style( 'thickbox' );
				wp_enqueue_script( 'sic-em-image-picker', WP_PLUGIN_URL.'/'.$sicem_path.'/image-picker.js',array('thickbox'), false, true );
			elseif ( 'media-upload.php' == $pagenow || 'async-upload.php' == $pagenow ) :
				add_filter( 'image_send_to_editor', array( $this,'image_send_to_editor'), 1, 8 );
				add_filter( 'gettext', array( $this, 'replace_text_in_thickbox' ), 1, 3 );
				add_filter( 'media_upload_tabs', array( $this, 'media_upload_tabs' ) );
			endif;
		endif;
	}

	////////////////////////////////////////////////////////////////////////////
	// SETTINGS UI /////////////////////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////////
	
	function diagnostics ($diag, $page) {
		$diag['Syndicated Image Cacher']['sicem:capture'] = 'as syndicated images are captured or rejected for local copies';
		$diag['Syndicated Image Cacher']['sicem:capture:error'] = 'when there is an error encountered when trying to capture a local copy of an image'; 
		$diag['Syndicated Image Cacher']['sicem:capture:http'] = 'as the HTTP GET request is sent to capture a local copy of a syndicated image';
		$diag['Syndicated Image Cacher']['sicem:capture:reject'] = 'when a captured image is rejected instead of being kept as a local copy';
		return $diag;
	}
	
	function add_settings_box ($page) {
		add_meta_box(
			/*id=*/ "feedwordpress_{$this->name}_box",
			/*title=*/ __("Syndicated Images"),
			/*callback=*/ array(&$this, 'display_settings'),
			/*page=*/ $page->meta_box_context(),
			/*context=*/ $page->meta_box_context()
		);
	} /* SicEm::add_settings_box() */

	function display_settings ($page, $box = NULL) {
			$upload = wp_upload_dir( /*now=*/ NULL );
			$uploadUrl = $upload['url'] . '/' . md5('http://example.com/example.jpg') . '.jpg';

			$cacheImagesSelector = array(
			"no" => "Hotlink the image at its original URL<div class=\"setting-description\">Ex.: <code>&lt;img src=&quot;%s&quot;&gt;</code></div>",
			"yes" => "Capture a copy of the image on this website and use the local copy<div class=\"setting-description\">Ex.: <code>&lt;img src=&quot;%s&quot;&gt;</code></div>",
			);

			$urls = array(
			"no" => 'http://example.com/example.jpg',
			"yes" => $uploadUrl
			);

			$labels = array();
			foreach ($cacheImagesSelector as $index => $value) :
				$cacheImagesSelector[$index] = sprintf(__($value), $urls[$index]);
				$labels[$index] = __(preg_replace('/<div.*$/', '', $value));
			endforeach;

			$params = array(
			'input-name' => 'sicem_cache_images',
			'setting-default' => NULL,
			'global-setting-default' => 'no',
			'labels' => $labels,
			'default-input-value' => 'default',
			);
			
			$featureImagesSelector = array(
			"no" => __("Just display the image"),
			"yes" => __("Use the image as the Featured Image for the syndicated post"),
			);
			
			$fisParams = array(
			'input-name' => 'sicem_feature_images',
			'setting-default' => NULL,
			'global-setting-default' => 'no',
			'default-input-value' => 'default',
			);
			
			$insertGallerySelector = array(
			"no" => __("<em>Leave it alone.</em> Just show the post as it appeared on the feed"),
			"before" => __("<em>Gallery above post.</em> Insert a gallery of attached images at the top of syndicated posts."),
			"after" => __("<em>Gallery below post.</em> Insert a gallery of attached images at the bottom of syndicated posts."),
			);

			$igsParams = array(
			'input-name' => 'sicem_insert_gallery',
			'setting-default' => NULL,
			'global-setting-default' => 'no',
			'default-input-value' => 'default',
			);
			
			$globalDefaultFeaturedImage = get_option('feedwordpress_featured_image_default', NULL);
			if ($page->for_feed_settings()) :
				$defaultFeaturedImage = $page->link->setting('featured image default', 'featured_image_default', NULL);
			else :
				$defaultFeaturedImage = $globalDefaultFeaturedImage;
			endif;
			
			$customFieldName = $page->setting('sicem custom field', NULL);
			
			$imageTypes = array('image/jpeg' => 'JPEG', 'image/gif' => 'GIF', 'image/png' => 'PNG', 'image/vnd.microsoft.icon' => 'ICO', 'image/tiff' => 'TIFF',  );
			
			$sicem_min_width = $page->setting('sicem min width', 0);
			$sicem_min_height = $page->setting('sicem min height', 0);
			$sicem_mime_whitelist = $page->setting('sicem mime whitelist', NULL);
			$sicem_mime_whitelist = ((strlen($sicem_mime_whitelist)>0) ? explode("|", $sicem_mime_whitelist) : NULL); 
			$sicem_mime_blacklist = $page->setting('sicem mime blacklist', NULL);
			$sicem_mime_blacklist = ((strlen($sicem_mime_blacklist)>0) ? explode("|", $sicem_mime_blacklist) : NULL);
 
			$sicem_crop_ratio = $page->setting('sicem crop ratio', NULL);
			$sicem_resize = $page->setting('sicem resize', NULL);

			$stripUncacheableImagesSelector = array(
			'no' => 'Leave the image in the post with a hotlink to the original image location',
			'yes' => 'Strip the image out of the syndicated post content',
			);
			
			$suisParams = array(
			'input-name' => 'sicem_strip_uncacheable_images',
			'setting-default' => NULL,
			'global-setting-default' => 'no',
			'default-input-value' => 'default',
			);
		?>
		<style type="text/css">
		ul.options ul.suboptions { margin: 10px 20px; }
		ul.options ul.suboptions li { display: inline; margin-right: 1.5em; }
		</style>

		<table class="edit-form narrow">
		<tr><th scope="row"><?php _e('Capture images:'); ?></th>
		<td><p>If a syndicated post includes an image located at <code>http://example.com/example.jpg</code>, FeedWordPress should...</p>
		<?php
			$page->setting_radio_control(
				'cache images', 'cache_images',
				$cacheImagesSelector, $params
			);
		?></td></tr>
		
		<tr><th scope="row"><?php _e('Feature images:'); ?></th>
		<td><p>When FeedWordPress captures a local copy of a syndicated image...</p>
		<?php
			$page->setting_radio_control(
				'feature captured images', 'feature_captured_images',
				$featureImagesSelector, $fisParams);
		?></td></tr>
		
		<tr><th scope="row"><?php _e('Default featured image:'); ?></th>
		<td><div id="sicem-default-featured-image-display" style="float: left; margin-right: 10px;">
		<?php if ($defaultFeaturedImage) : $url = wp_get_attachment_url($defaultFeaturedImage); ?>
		<img src="<?php print esc_attr($url); ?>" style="height: 60px; width: 60px" />
		<?php endif; ?>
		</div>
		<input type="number" id="sicem-default-featured-image" name="sicem_default_featured_image" value="<?php print esc_attr($defaultFeaturedImage); ?>" size="3" />
		</td></tr>
		
		<tr><th scope="row"><?php _e('Custom Fields:'); ?></th>
		<td><p style="margin-top:0px">When FeedWordPress captures a local copy of a syndicated image, store the local URL in a Custom Field named...</p>
		<div><label>Name: <input type="text" name="sicem_custom_field_name" value="<?php print esc_attr($customFieldName); ?>" size="15" placeholder="custom field name" /></label>
		<div class="setting-description">Leave blank if you don't need to store the URL.</div></div></td></tr>
		
		<tr><th scope="row"><?php _e('Display Image Gallery with Post:'); ?></th>
		<td><p style="margin-top:0px">When WordPress displays a syndicated post with captured images attached to it...</p>
		<?php
			$page->setting_radio_control(
				'sicem insert gallery', 'sicem_insert_gallery',
				$insertGallerySelector, $igsParams);
		?></td></tr>

		<tr><th scope="row"><?php _e('Image Size: '); ?></th>
		<td>
<?php
		// Only display these settings if PHP has the capacity to make use of em
		if (SICWebImage::has_gd()) :
?>
		<p style="margin-top:0px"><label>Crop to aspect ratio: <input type="text" name="sicem_crop_ratio" value="<?php print esc_attr($sicem_crop_ratio); ?>" placeholder="ex.: 1:1, 2:3, 16:9" /></label></p>
		<p style="margin-top:0px"><label>Rescale to dimensions: <input type="text" name="sicem_resize" value="<?php print esc_attr($sicem_resize); ?>" placeholder="ex.: 400x300, 400x, x300" /></label></p>
<?php
		else :
?>
		<p style="margin-top: 0px; font-style: italic; color: #606060">Image cropping and rescaling are not available unless the <a href="http://us2.php.net/manual/en/book.image.php">PHP GD image functions</a> are installed for your hosting environment. Check with your web hosting provider about how to install these modules if you need them.</p>
<?php
		endif;
?>
		</td></tr>
		<tr><th scope="row"><?php _e('Image Filter: '); ?></th>
		<td><ul class="options">
		<li><label><input type="checkbox" name="sicem_min_width_use" value="Yes" <?php if (is_numeric($sicem_min_width) and $sicem_min_width > 0) : ?> checked="checked"<?php endif; ?> /> <strong>Width:</strong>  Only cache images</label> that are at least <input type="number" name="sicem_min_width" value="<?php print (int) $sicem_min_width; ?>" min="0" step="10" size="4" /> pixels wide</li>
		<li><label><input type="checkbox" name="sicem_min_height_use" <?php if (is_numeric($sicem_min_height) and $sicem_min_height > 0) : ?> checked="checked"<?php endif; ?> value="Yes" /> <strong>Height:</strong> Only cache images</label> that are at least <input type="number" name="sicem_min_height" value="<?php print (int) $sicem_min_height; ?>" min="0" step="10" size="4" /> pixels high</li>
		<li><label><input type="checkbox" <?php if (!is_null($sicem_mime_whitelist)) : ?> checked="checked"<?php endif; ?> name="sicem_mime_whitelist_use" value="Yes" /> <strong>Permitted Image Types:</strong> <em>Only</em> cache images of certain types:</label>
			<ul class="suboptions">
			<?php foreach ($imageTypes as $type => $label) : ?>
			<li><label style="white-space: nowrap"><input type="checkbox" name="sicem_mime_whitelist[]" <?php if (in_array($type, $sicem_mime_whitelist)) : ?> checked="checked"<?php endif; ?> value="<?php print esc_attr($type); ?>" /><?php print $label; ?> (<code><?php print $type; ?></code>)</label></li>
			<?php endforeach; ?>
			</ul></li>
		<li><label><input type="checkbox" name="sicem_mime_blacklist_use" <?php if (!is_null($sicem_mime_blacklist)) : ?> checked="checked"<?php endif; ?> value="Yes" /> <strong>Forbidden Image Types:</strong> <em>Don&#8217;t</em> capture images of certain types:</label>
			<ul class="suboptions">
			<?php foreach ($imageTypes as $type => $label) : ?>
			<li><label style="white-space: nowrap"><input type="checkbox" name="sicem_mime_blacklist[]" <?php if (in_array($type, $sicem_mime_blacklist)) : ?> checked="checked"<?php endif; ?> value="<?php print esc_attr($type); ?>" /><?php print $label; ?> (<code><?php print $type; ?></code>)</label></li>
			<?php endforeach; ?>
			</ul></li>
		</ul></td></tr>
		
		<tr><th scope="row"><?php _e('Uncacheable Images:'); ?></th>
		<td><p style="margin-top: 0px">When a filter prevents FeedWordPress from
		capturing a local copy of a syndicated image...</p>
		<?php
			$page->setting_radio_control(
				'sicem strip uncacheable images', 'sicem_strip_uncacheable_images',
				$stripUncacheableImagesSelector, $suisParams
			);
		?>
		</td></tr>
		</table>
		<?php
	}
	function save_settings ($params, $page) {
		if (isset($params['sicem_cache_images'])) :
			$page->update_setting('cache images', $params['sicem_cache_images']);
			$page->update_setting('feature captured images', $params['sicem_feature_images']);
			$page->update_setting('featured image default', $params['sicem_default_featured_image']);
			$page->update_setting('sicem custom field', $params['sicem_custom_field_name']);
			$page->update_setting('sicem insert gallery', $params['sicem_insert_gallery']);
			
			// empty strings mean a null value
			foreach (array('crop ratio', 'resize') as $key) :
				$idx = "sicem_" . str_replace(" ", "_", $key);
				$setting = 'sicem ' . $key;

				if (strlen(trim($params[$idx])) > 0) :
					$page->update_setting($setting, $params[$idx]);
				else :
					$page->update_setting($setting, NULL);
				endif;
			endforeach;

			// collapse arrays to strings
			foreach (array('mime_whitelist', 'mime_blacklist') as $key) :
				if (is_array($params["sicem_$key"])) :
					$params["sicem_$key"] = implode("|", $params["sicem_$key"]);
				endif;
			endforeach;
			
			// check for enabling checkmark
			foreach (array('min_width', 'min_height', 'mime_whitelist', 'mime_blacklist') as $key) :
				if (isset($params["sicem_${key}_use"]) and "yes"==strtolower($params["sicem_${key}_use"])) :
					$update_to = $params["sicem_${key}"];
				else :
					$update_to = NULL;
				endif;
				
				$spacedKey = str_replace("_", " ", $key);
				$page->update_setting("sicem ${spacedKey}", $update_to);
			endforeach;
			
			$page->update_setting('sicem strip uncacheable images', $params["sicem_strip_uncacheable_images"]);
		endif;
	}

	////////////////////////////////////////////////////////////////////////////
	// FUNCTIONALITY ///////////////////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////////
	var $post;

	function the_content ($content) {
		global $post;
		
		if (function_exists('is_syndicated')) :
			if (is_syndicated()) :
				$source = get_syndication_feed_object($post->ID);
				$ig = $source->setting('sicem insert gallery', 'sicem_insert_gallery', 'no');
				switch ($ig) :
				case 'before' :
					$content = do_shortcode('[gallery]')."\n\n".$content;
					break;
				case 'after' :
					$content = $content."\n\n".do_shortcode('[gallery]');
					break;
					
				// Leave it.
				case 'no' :
				default :
					// NOOP
				endswitch;
			endif;
		endif;
		
		return $content;
	}

	function process_post ($data, $post) {
		$img_src = FeedWordPressHTML::attributeRegex('img', 'src');
		
		# Match any image elements in the syndicated item
		preg_match_all($img_src, $data['post_content'], $refs, PREG_SET_ORDER);
		foreach ($refs as $matches) :
			$src = FeedWordPressHTML::attributeMatch($matches);
			if (!isset($data['meta']['_syndicated_image_capture'])) :
				$data['meta']['_syndicated_image_capture'] = array();
			endif;
			$data['meta']['_syndicated_image_capture'][] = $src['value'];
		endforeach;
		
		$thumb_links = $post->entry->get_links(/*rel=*/ "http://github.com/radgeek/FWPPitchMediaGallery/wiki/thumbnail");
		if (is_array($thumb_links) and count($thumb_links) > 0) :
			foreach ($thumb_links as $href) :
				if (!isset($data['meta']['_syndicated_image_capture'])) :
					$data['meta']['_syndicated_image_capture'] = array();
				endif;
				$data['meta']['_syndicated_image_capture'][] = $href;
				
				if (!isset($data['meta']['_syndicated_image_featured'])) :
					$data['meta']['_syndicated_image_featured'] = array();
				endif;
				$data['meta']['_syndicated_image_featured'][] = $href;
			endforeach;
		endif;
		
		$link_elements = $post->entry->get_links(/*rel=*/ "enclosure");
		if (is_array($link_elements) and count($link_elements) > 0) :
			foreach ($link_elements as $href) :
				if (!isset($data['meta']['_syndicated_image_capture'])) :
					$data['meta']['_syndicated_image_capture'] = array();
				endif;
				$data['meta']['_syndicated_image_capture'][] = $href;
			endforeach;
		endif;

		$enclosures = $post->entry->get_enclosures();
		if (is_array($enclosures) and count($enclosures) > 0) :
			foreach ($enclosures as $enclosure) :
				$data['meta']['_syndicated_image_capture'][] = $enclosure->get_link();
			endforeach;
		endif;
		
		$thumb_id = $post->link->setting('featured image default', 'featured_image_default', NULL);
		if ($thumb_id) :
			$data['meta']['_thumbnail_id'] = $thumb_id;
		endif;
		
		return $data;
	} /* function SicEm::process_post () */

	function process_captured_images ($delta) {
		global $post, $wpdb;

		// Let's do this.
		$q = new WP_Query(array(
		'post_type' => 'any',
		'meta_key' => '_syndicated_image_capture',
		'posts_per_page' => 10,
		));
		
		while ($q->have_posts()) : $q->the_post();
			$this->post = $post;
			
			$imgs = get_post_custom_values('_syndicated_image_capture');
			$featureUrls = get_post_custom_values('_syndicated_image_featured');
			
			$source = get_syndication_feed_object($post->ID);
			$replacements = array();

			if ((count($imgs) > 0) and !!$imgs[0] and ('yes'==$source->setting('cache images', 'cache_images', 'no'))) :
				$seekingFeature = ('yes' == $source->setting('feature captured images', 'feature_captured_images', NULL));
				$customFieldName = trim($source->setting('sicem custom field', 'sicem_custom_field', ''));
				foreach ($imgs as $img) :
					
					$imgGuid = SICWebImage::guid($img);
					$guid = $wpdb->escape($imgGuid);
					
					$result = $wpdb->get_row("
					SELECT ID FROM $wpdb->posts
					WHERE guid='$guid' AND post_type='attachment'
					");
					
					if (!$result) : // Attachment not yet created
						$params = array(
						"min width" => $source->setting('sicem min width', 'sicem_min_width', 0),
						"min height" => $source->setting('sicem min height', 'sicem_min_height', 0),
						"blacklist" => explode("|", $source->setting('sicem mime blacklist', 'sicem_mime_blacklist', NULL)),
						"whitelist" => explode("|", $source->setting('sicem mime whitelist', 'sicem_mime_whitelist', NULL)),
						"crop" => $source->setting('sicem crop ratio', 'sicem_crop_ratio', NULL),
						"resize" => $source->setting('sicem resize', 'sicem_resize', NULL),
						);
						$img_id = $this->attach_image($img, $post->ID, $params);
					else :
						$img_id = $result->ID;
					endif;
					
					if ($img_id and !is_wp_error($img_id)) :
						// Mark for replacing all occurrences in img/@src
						$replacements[$img] = $img_id;

						// Set as featured image, if applicable.
						if (($img_id > 0) and $seekingFeature) :
							if (
								count($featureUrls) == 0 // No featured specified
								or in_array($img, $featureUrls) // Spec featured
							) :
								update_post_meta($post->ID, '_thumbnail_id', $img_id);
								$seekingFeature = false;
							endif;
						endif;
						
						$zapit = true;
						
					endif;
				endforeach;

				foreach ($replacements as $url => $attach_id) :
					$replacement = NULL;
					if ($attach_id < 0) :
						$new_url = NULL;
						if ($source->setting('sicem strip uncacheable images', 'sicem_strip_uncacheable_images', 'no')=='yes') :
							FeedWordPress::diagnostic('sicem:capture', 'Image  ['.$url.'] not cached; stripping image.');
							$replacement = '';
						else :
							FeedWordPress::diagnostic('sicem:capture', 'Image  ['.$url.'] not cached; leaving hotlinked image.');							$replacement = NULL;
						endif;
					else :
						FeedWordPress::diagnostic('sicem:capture', 'Captured image ['.$url.'] to local URL ['.$new_url.']');
						$new_url = wp_get_attachment_url($attach_id);
						if ($new_url) :
							$replacement = '$1'.$new_url.'$2';
						else :
							$replacement = NULL;
						endif;
					endif;
					
					if (!is_null($replacement)) :
						$find = preg_quote($url);
						$post->post_content = preg_replace(
							':(<img \s+ [^>]*src=[^>]*)'.$find.'([^>]*>):ix',
							$replacement,
							$post->post_content
						);
						
						if ($new_url and (strlen($customFieldName) > 0)) :
							add_post_meta($post->ID, $customFieldName, $new_url);					
						endif;

					endif;
				endforeach;
				
				// Save as a revision of the existing post.
				$this->insert_revision($post);

				if ($zapit) :
					delete_post_meta($post->ID, '_syndicated_image_capture');
				endif;
			endif;
		endwhile;

	}

	////////////////////////////////////////////////////////////////////////////
	// UTILITY FUNCTIONS ///////////////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////////
	
	function insert_revision ($post) {

		$success = true; // Innocent until proven guilty
		
		if (strlen(trim($post->post_content)) > 0) :
			// We need to put the cats in post_category to prevent them
			// from being wiped. Le sigh.
			$cats = wp_get_post_categories($post->ID);
			$post->post_category = $cats;
	
			// This is a ridiculous fucking kludge necessitated by
			// WordPress munging authorship meta-data
			add_action('_wp_put_post_revision', array($this, 'fix_revision_meta'));

			// Kludge to prevent kses filters from stripping the
			// content of posts when updating without a logged in
			// user who has `unfiltered_html` capability.
			$mungers = array('wp_filter_kses', 'wp_filter_post_kses');
			$removed = array();
			foreach ($mungers as $munger) :
				if (has_filter('content_save_pre', $munger)) :
					remove_filter('content_save_pre', $munger);
					$removed[] = $munger;
				endif;
			endforeach;
	
			$this->post = $post;
			wp_insert_post($post);
			
			// Turn off ridiculous fucking kludges #1 and #2
			remove_action('_wp_put_post_revision', array($this, 'fix_revision_meta'));
			foreach ($removed as $filter) :
				add_filter('content_save_pre', $filter);
			endforeach;
		else :
			$success = false;
		endif;
		return $success;

	} /* SicEm::insert_revision () */
	
	function fix_revision_meta ($revision_id) {
		global $wpdb;
		
		$post_author = (int) $this->post->post_author;
		
		$revision_id = (int) $revision_id;
		$wpdb->query("
		UPDATE $wpdb->posts
		SET post_author={$this->post->post_author}
		WHERE post_type = 'revision' AND ID='$revision_id'
		");
	} /* SicEm::fix_revision_meta () */
	
	function attach_image ($url, $to, $args = array()) {
		$attach_id = NULL;

		$p = wp_parse_args($args, array(
		"crop" => NULL,
		"resize" => NULL,
		));

		# Fetch the URI
		$headers['Connection'] = 'close';
		$headers['Referer'] = get_permalink($to);
		
		if (is_callable(array('FeedWordPress', 'fetch_timeout'))) :
			$timeout = FeedWordPress::fetch_timeout();
		elseif (defined('FEEDWORDPRESS_FETCH_TIME_OUT')) :
			$timeout = FEEDWORDPRESS_FETCH_TIME_OUT;
		elseif (defined('FEEDWORDPRESS_FETCH_TIMEOUT_DEFAULT')) :
			$timeout = FEEDWORDPRESS_FETCH_TIMEOUT_DEFAULT;
		else :
			$timeout = 60;
		endif;
		
		FeedWordPress::diagnostic('sicem:capture:http', "HTTP &raquo;&raquo; GET [$url]");

		$params = apply_filters('sicem_remote_request_params', array(
			'headers' => $headers,
			'timeout' => $timeout,
		), $url);
	
		$http = apply_filters('sicem_remote_request', NULL, $url, $params);
		if (is_null($http)) :
			$http = wp_remote_request($url, $params);
		endif;

		$imgBits = new SicWebImage($url, $params, $http);
		
		if ($imgBits->is_ok() and $imgBits->is_image()) :
			// Check whether our size filters or MIME constraints filter it out
			$imagesize = $imgBits->size();
			if (!is_null($imagesize)) :
				$minWidth = (isset($args['min width']) ? $args['min width'] : 0);
				$minHeight = (isset($args['min height']) ? $args['min height'] : 0);
				if (
					$imagesize[0] < $minWidth
					or $imagesize[1] < $minHeight
					or !$this->allowedtype($imagesize['mime'], $args)
				) :
					FeedWordPress::diagnostic('sicem:capture:reject',
						"Image [$url] rejected. " 
						.(($imagesize[0] < $minWidth) ? 'width: '.$imagesize[0].' &lt; '.$minWidth.'. ' : '')
						.(($imagesize[1] < $minHeight) ? 'height: '.$imagesize[1].' &lt; '.$minHeight.'. ':'')
						.(!$this->allowedtype($imagesize['mime'], $args) ? 'type ['.$imagesize['mime'].']: whitelist ['.implode('|',$args['whitelist']).'] blacklist ['.implode('|',$args['blacklist']).'].' : '')
					);			
					$imgBits->set_image(NULL, NULL);
				endif;
			endif;
			
			// Apply (if applicable) crop and resize settings
			$imgBits->constrain($p['crop'], $p['resize']);

			if ($imgBits->is_image()) :
				$attach_id = $imgBits->upload(/*attach_to=*/ $to);
			else :
				$attach_id = -1; // Filtered
			endif;
		else :
			// Got a WP_Error object back instead of a HTTP GET reply
			if (is_wp_error($http)) :
				$error_message = preg_replace('/\s+/', " ", "WP_Error: ".implode(" / ", $http->get_error_messages()));
			
			// Got a HTTP GET reply other than 200 OK. 
			elseif (is_array($http) and isset($http['response'])) :
				$code = $http['response']['code'];
				$mesg = $http['response']['message'];
				$pcode = preg_replace('/\s+/', '\s+', preg_quote($code)); $pmesg = preg_replace('/\s+/', '\s+', preg_quote($mesg));
				$pattern = ":<([^>]+)> \s* ($pcode\s*)? $pmesg \s*</\\1>:ix";
				$stripped_body = strip_tags(preg_replace($pattern, '', $http['body']));
				$len = 66;
				$error_message = preg_replace('/\s+/', " ", "${code} ${mesg}: ".substr(
					$stripped_body, 0, $len
				) . ((strlen($stripped_body) > $len) ? "&hellip;" : ''));
			
			// Well, who knows what the hell is going on, really?
			else :
				$error_message = preg_replace('/\s+/', " ", FeedWordPress::val($http));
			endif;

			// Send it to the diagnostix module.
			FeedWordPress::diagnostic('sicem:capture:error', "Failed GET [$url] &laquo;&laquo; ".$error_message);
		endif;

		return $attach_id;
	}
	
	function allowedtype ($type, $args) {
		if (!isset($args['blacklist'])) : $args['blacklist'] = NULL; endif;
		if (!isset($args['whitelist'])) : $args['whitelist'] = NULL; endif;
		
		if (is_array($args['blacklist'])) :
			$args['blacklist'] = array_filter($args['blacklist']);
		endif;
		if (is_array($args['whitelist'])) :
			$args['whitelist'] = array_filter($args['whitelist']);
		endif;
		
		if (!isset($args['blacklist']) or count($args['blacklist'])==0) :
			$args['blacklist'] = array();
		endif;
		if (!isset($args['whitelist']) or count($args['whitelist'])==0) :
			$args['whitelist'] = array('.*');
		endif;
		
		foreach ($args['blacklist'] as $pattern) :
			if ((strlen($pattern) > 0) and (preg_match('|'.$pattern.'|i', $type))) :
				return false;
			endif;
		endforeach;

		foreach ($args['whitelist'] as $pattern) :
			if ((strlen($pattern) > 0) and (preg_match('|'.$pattern.'|i', $type))) :
				return true;
			endif;
		endforeach;
		return false;
	}

	function fix_async_upload_image() {
		if (isset($_REQUEST['attachment_id'])) {
			$GLOBALS['post'] = get_post($_REQUEST['attachment_id']);
		}
	}

	/**
	 * Test context to see if the uploader is being used for the image widget or for other regular uploads
	 *
	 * @return void
	 * @author Shane & Peter, Inc. (Peter Chester)
	 */
	function is_sic_pick_context() {
		global $fwp_path;
			
		if ( isset($_SERVER['HTTP_REFERER']) and strpos($_SERVER['HTTP_REFERER'], 'sic_pick_feed_id') !== false ) :
			return true;
		elseif ( isset($_REQUEST['_wp_http_referer']) and strpos($_REQUEST['_wp_http_referer'], 'sic_pick_feed_id') !== false ) :
			return true;
		elseif (isset($_REQUEST['sic_pick_feed_id'])) :
			return true;
		endif;
		return false;
	}
	
	/**
	 * Somewhat hacky way of replacing "Insert into Post" with "Insert into Widget"
	 *
	 * @param string $translated_text text that has already been translated (normally passed straight through)
	 * @param string $source_text text as it is in the code
	 * @param string $domain domain of the text
	 * @return void
	 * @author Shane & Peter, Inc. (Peter Chester)
	 */
	function replace_text_in_thickbox($translated_text, $source_text, $domain) {
		if ( $this->is_sic_pick_context() ) {
			if ('Insert into Post' == $source_text) {
				return __('Use as Featured Image', $domain );
			}
		}
		return $translated_text;
	}
	
	/**
	 * Filter image_end_to_editor results
	 *
	 * @param string $html 
	 * @param int $id 
	 * @param string $alt 
	 * @param string $title 
	 * @param string $align 
	 * @param string $url 
	 * @param array $size 
	 * @return string javascript array of attachment url and id or just the url
	 * @author Shane & Peter, Inc. (Peter Chester)
	 */
	function image_send_to_editor( $html, $id, $caption, $title, $align, $url, $size, $alt = '' ) {
		// Normally, media uploader return an HTML string (in this case, typically a complete image tag surrounded by a caption).
		// Don't change that; instead, send custom javascript variables back to opener.
		// Check that this is for the widget. Shouldn't hurt anything if it runs, but let's do it needlessly.
		if ( $this->is_sic_pick_context() ) :
			$html = addslashes($html);
			$id = addslashes($id);
			$caption = addslashes($caption);
			$title = addslashes($title);
			$align = addslashes($align);
			$url = addslashes($url);
			$size = addslashes($size);
			$alt = addslashes($alt);
			$sample = addslashes(
					'<img src="'.$url.'" style="width: 60px; height: 60px;" />'
			);
			$ret = <<<EOJSON
			{
				"html": "$html",
				"id": "$id",
				"caption": "$caption",
				"title": "$title",
				"align": "$align",
				"url": "$url",
				"size": "$size",
				"alt": "$alt",
				"sample": "$sample" 
			}
EOJSON;
			$ret = trim(preg_replace('/\s+/', ' ', $ret));
		else :
			$ret = $html;
		endif;
		return $ret;
	}

	/**
	 * Remove from url tab until that functionality is added to widgets.
	 *
	 * @param array $tabs 
	 * @return void
	 * @author Shane & Peter, Inc. (Peter Chester)
	 */
	function media_upload_tabs($tabs) {
		if ( $this->is_sic_pick_context() ) {
			unset($tabs['type_url']);
		}
		return $tabs;
	}

} /* class SicEm */

$sicEmAddOn = new SicEm;

