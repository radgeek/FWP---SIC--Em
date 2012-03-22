<?php
/*
Plugin Name: FWP+: Sic 'Em (Syndicated Image Capture)
Plugin URI: http://projects.radgeek.com/feedwordpress/
Description: A FeedWordPress filter that locally caches images in the feeds you syndicate. Images are stored in your WordPress uploads directory.
Author: Charles Johnson
Version: 2012.0322
Author URI: http://projects.radgeek.com
*/

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
			"no" => "The original URL<div class=\"setting-description\">Ex.: <code>%s</code></div>",
			"yes" => "A local copy of the image on this website<div class=\"setting-description\">Ex.: <code>%s</code></div>",
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
			"no" => __("Just display the image normally"),
			"yes" => __("Use the image as the Featured Image for the syndicated post"),
			);
			
			$fisParams = array(
			'input-name' => 'sicem_feature_images',
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
		<td><p>If a syndicated post includes an image located at <code>http://example.com/example.jpg</code>, the image tag in the syndicated post should refer to...</p>
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
		'posts_per_page' => 15,
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
					$imgGuid = $this->guid($img);
					$guid = $wpdb->escape($imgGuid);
					$result = $wpdb->get_row("
					SELECT ID FROM $wpdb->posts
					WHERE guid='$guid' AND post_type='attachment'
					");
					
					if (!$result) : // Attachment not yet created
						$img_id = $this->attach_image($img, $post->ID, array(
						"min width" => $source->setting('sicem min width', 'sicem_min_width', 0),
						"min height" => $source->setting('sicem min height', 'sicem_min_height', 0),
						"blacklist" => explode("|", $source->setting('sicem mime blacklist', 'sicem_mime_blacklist', NULL)),
						"whitelist" => explode("|", $source->setting('sicem mime whitelist', 'sicem_mime_whitelist', NULL)),
						));
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
			// We need to put the cats in post_category to prevent them from
			// being wiped. Le sigh.
			$cats = wp_get_post_categories($post->ID);
			$post->post_category = $cats;
	
			// This is a ridiculous fucking kludge necessitated by WordPress
			// munging authorship meta-data
			add_action('_wp_put_post_revision', array($this, 'fix_revision_meta'));
	
			$this->post = $post;
			wp_insert_post($post);
			
			// Turn off ridiculous fucking kludge
			remove_action('_wp_put_post_revision', array($this, 'fix_revision_meta'));
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
	
	function SicEmMimeToExtension ($mimetype) {
		# This is the inverse, slightly modified, of the array from extensions
		# to mimetypes found in WordPress 2.1's functions.php. We need this to
		# select an appropriate extension for cached images.
		$mimes = array (
			'image/jpeg' => 'jpg',
			'image/gif' => 'gif',
			'image/png' => 'png',
			'image/bmp' => 'bmp',
			'image/tiff' => 'tif',
			'image/x-icon' => 'ico',
			'video/asf' => 'asf',
			'video/avi' => 'avi',
			'video/quicktime' => 'mov',
			'video/mpeg' => 'mpeg',
			'text/plain' => 'txt',
			'text/richtext' => 'rtx',
			'text/css' => 'css',
			'text/html' => 'html',
			'audio/mpeg' => 'mp3',
			'audio/x-realaudio' => 'ra',
			'audio/wav' => 'wav',
			'audio/ogg' => 'ogg',
			'audio/midi' => 'mid',
			'audio/wma' => 'wma',
			'application/rtf' => 'rtf',
			'application/javascript' => 'js',
			'application/pdf' => 'pdf',
			'application/msword' => 'doc',
			'application/vnd.ms-powerpoint' => 'ppt',
			'application/vnd.ms-write' => 'wri',
			'application/vnd.ms-excel' => 'xls',
			'application/vnd.ms-access' => 'mdb',
			'application/vnd.ms-project' => 'mpp',
			'application/x-shockwave-flash' => 'swf',
			'application/java' => 'class',
			'application/x-tar' => 'tar',
			'application/zip' => 'zip',
			'application/x-gzip' => 'gz',
		);

		if (isset($mimes[strtolower(trim($mimetype))])) :
			$ret = $mimes[strtolower(trim($mimetype))];
		else :
			$ret = 'txt'; // I don't like this, but I'm not sure what else to do.
		endif;
		return $ret;
	}

	function guid ($url) {
		return 'http://projects.radgeek.com/fwp-capture-thumbnails/?u='.md5($url);
	}

	function attach_image ($url, $to, $args = array()) {
		$attach_id = NULL;

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
		$http = wp_remote_request($url, array(
			'headers' => $headers,
			'timeout' => $timeout,
		));

		if (
			!is_wp_error($http)
			and isset($http['response'])
			and ($http['response']['code'] == 200) // OK
		) :

			# Get the MIME type from the Content-Type header
			$mimetype = NULL;
			if (isset($http['headers']['content-type'])) :
				$split = explode(";", $http['headers']['content-type'], 2);
				$mimetype = $split[0];
				$params = (isset($split[1]) ? $split[1] : null);
			endif;
			
			$data = $http['body'];
			$imagesize = $this->getimagesize(/*data=*/ $data);
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
					$data = NULL;
				endif;
			endif;
			
			if (!is_null($data)) :
				# Create an appropriate filename
				$filebase = md5($url) . '.' . $this->SicEmMimeToExtension($mimetype);
	
				# Now send the image to the upload directory
				$up = wp_upload_bits($filebase, $mimetype, $data);
	
				if ($up and !$up['error']) :
					# Now do the attachment
					$attach = array(
					'post_mime_type' => $mimetype,
					'post_tile' => preg_replace('/\.[^.]+$/', '', basename($url)),
					'post_content' => '',
					'post_status' => 'inherit',
					'guid' => $this->guid($url),
					);
					$attach_id = wp_insert_attachment($attach, $up['file'], $to);
	
					require_once(ABSPATH.'wp-admin'.'/includes/image.php');
					$attach_data = wp_generate_attachment_metadata($attach_id, $up['file']);
	
					wp_update_attachment_metadata($attach_id, $attach_data);
				endif;
			else :
				$attach_id = -1; // Filtered
			endif;
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
	
	function getimagesize ($value) {
		global $sicEmRemoteImage;

		$ret = NULL;
		if (function_exists('getimagesize')) :
			$existed = in_array("sicemvariable", stream_get_wrappers());
			if (!$existed) :
				stream_wrapper_register("sicemvariable", "VariableStream");
			endif;
			$sicEmRemoteImage = $value;
				
			$ret = getimagesize("sicemvariable://sicEmRemoteImage");
				
		endif;
		return $ret;
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

/*
 * VariableStream class taken from examples in PHP documentation
 * http://www.php.net/manual/en/stream.streamwrapper.example-1.php
 */
class VariableStream {
    var $position;
    var $varname;

    function stream_open($path, $mode, $options, &$opened_path)
    {
        $url = parse_url($path);
        $this->varname = $url["host"];
        $this->position = 0;

        return true;
    }

    function stream_read($count)
    {
        $ret = substr($GLOBALS[$this->varname], $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }

    function stream_write($data)
    {
        $left = substr($GLOBALS[$this->varname], 0, $this->position);
        $right = substr($GLOBALS[$this->varname], $this->position + strlen($data));
        $GLOBALS[$this->varname] = $left . $data . $right;
        $this->position += strlen($data);
        return strlen($data);
    }

    function stream_tell()
    {
        return $this->position;
    }

    function stream_eof()
    {
        return $this->position >= strlen($GLOBALS[$this->varname]);
    }

    function stream_seek($offset, $whence)
    {
        switch ($whence) {
            case SEEK_SET:
                if ($offset < strlen($GLOBALS[$this->varname]) && $offset >= 0) {
                     $this->position = $offset;
                     return true;
                } else {
                     return false;
                }
                break;

            case SEEK_CUR:
                if ($offset >= 0) {
                     $this->position += $offset;
                     return true;
                } else {
                     return false;
                }
                break;

            case SEEK_END:
                if (strlen($GLOBALS[$this->varname]) + $offset >= 0) {
                     $this->position = strlen($GLOBALS[$this->varname]) + $offset;
                     return true;
                } else {
                     return false;
                }
                break;

            default:
                return false;
        }
    }
} /* class VariableStream */

$sicEmAddOn = new SicEm;

