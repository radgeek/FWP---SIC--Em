<?php
/*
Plugin Name: FWP+: GAFFer (Grab All Fulltext & Feature images)
Plugin URI: https://github.com/radgeek/FWP---SIC--Em
Description: A FeedWordPress add-on that allows you to grab full-text contents and make a best guess at setting featured images for syndicated content.
Author: Charles Johnson
Version: 2017.0831
Author URI: http://projects.radgeek.com
*/

require_once(dirname(__FILE__).'/sicwebimage.class.php');

define('FWPGFI_CACHE_IMAGES_DEFAULT', 'no');
define('FWPGFI_GRAB_FULL_HTML_DEFAULT', 'no');
define('FWPGFI_PROCESS_POSTS_MAX', 70);
define('FWPGFI_FULL_HTML_PRE', '<div class="feedwordpress-gaffer-full-text">');
define('FWPGFI_FULL_HTML_POST', '</div>');
define('FWPGFI_QUEUE_LABEL', 'GAFFer Post Processing Queue');
define('FWPGFI_FULL_HTML_ROOT_ELEMENTS', "section[contains(@class,'postcontent')]\narticle[contains(@class, 'post-content')]\narticle//*[contains(@class,'entry-content')]\narticle//*[contains(@class,'entry')]\narticle\n*[contains(@class,'entry-content')]\n*[contains(@class, 'article-body') and .//p]\n*[contains(@class, 'article') and .//p]\n*[contains(@class, 'node-body') and .//p]\n*[contains(@class, 'content') and .//p]\n*[contains(@class, 'post') and .//p]\n*[contains(@class, 'text') and .//p]\n*[contains(@class, 'entry') and .//p]\n*[contains(@class, 'node') and .//p]\nmain\nbody\n*");
define('FWPGFI_FULL_HTML_CONTENT_FILTER_OUT', "script\nstyle\nheader\nfooter\nform\n*[contains(@class, 'shareBar')]\n*[contains(@class, 'comments')]\n*[contains(@class, 'random-posts')]\n*[contains(@class, 'social-links')]\nnav\n*[contains(@class, 'menu')]\n*[./li[contains(@class, 'facebook')]]");
define('FWPGFI_FULL_HTML_CONTENT_FILTER_IN', "h1\nh2\nh3\nh4\nh5\nh6\np\nhr\nbr\nul\nol\ndl\nblockquote\naddress\npre\ntable\nfigure\nfigcaption\nimg\naudio\nvideo\nembed\nobject\niframe\ncanvas");

// Get the path relative to the plugins directory in which FWP is stored
preg_match (
	'|'.preg_quote(WP_PLUGIN_DIR).'/(.+)$|',
	dirname(__FILE__),
	$ref
);

if (isset($ref[1])) :
	$gfi_path = $ref[1];
else : // Something went wrong. Let's just guess.
	$gfi_path = 'fwp-gaffer';
endif;

// to do this well, we need to use cron-based updates, and to check in frequently
function fwpgfi_add_every6mins ($schedules) {
	// add 'every6mins' to the existing set
	$schedules['every6mins'] = array(
		'interval' => 360,
		'display' => 'Every 6 Minutes',
	);
	return $schedules;
}
add_filter('cron_schedules', 'fwpgfi_add_every6mins');

register_deactivation_hook(__FILE__, 'fwpgfi_deactivation');

function fwpgfi_deactivation() {
	wp_clear_scheduled_hook('fwpgfi_scheduled_update');
}

class GrabFeaturedImages {
	var $name;
	var $upload;
	private $post;

	public function __construct () {
		$this->name = strtolower(get_class($this));
		add_filter('syndicated_post', array($this, 'process_post'), 10, 2);
		add_filter('feedwordpress_update_complete', array($this, 'process_full_html'), -2000, 1);
		add_filter('feedwordpress_update_complete', array($this, 'process_captured_images'), -1000, 1);
		add_action('feedwordpress_admin_page_syndication_meta_boxes', array($this, 'add_queue_box'));
		add_action('feedwordpress_admin_page_posts_meta_boxes', array($this, 'add_settings_box'));
		add_action('feedwordpress_admin_page_posts_save', array($this, 'save_settings'), 10, 2);
		add_filter('feedwordpress_diagnostics', array($this, 'diagnostics'), 10, 2);
		
		add_action('feedwordpress_feedfinder_form', array($this, 'feedwordpress_feedfinder_form'), 10, 4);
		add_action('feedwordpress_admin_switchfeed', array($this, 'feedwordpress_admin_switchfeed'), 10, 2);

		// If user opts for it, put a gallery at the end of syndicated posts
		add_filter('the_content', array(&$this, 'the_content'), 200000, 2);
		
		// Set up image picker through massive fuckery.
		if (defined('WP_ADMIN') or is_admin()) : // set up image picker through massive fuckery
			add_action('admin_init', array($this, 'admin_init'), -10);
			add_action('admin_init', array($this, 'fix_async_upload_image'), 10);
		endif;

		add_action('init', array($this, 'init'), 10);
		add_action('fwpgfi_scheduled_update', array($this, 'scheduled_update'), 10);
	} /* GrabFeaturedImages::__construct() */

	public function init () {
		if (! wp_next_scheduled('fwpgfi_scheduled_update') ) :
			wp_schedule_event(time()+360, 'every6mins', 'fwpgfi_scheduled_update');
		endif;
	} /* GrabFeaturedImages::init() */

	public function scheduled_update () {
		// Construct our magic update URL
		$url = site_url();
		if (strpos($url, '?') == false) :
			$sep = '?';
		else :
			$sep = '&';
		endif;
		$url = $url . $sep . 'update_feedwordpress=1';

		// Set up headers and timeout
		$headers = array();
		$headers['Connection'] = 'close';
		
		$timeout = 300;

		// Now send an HTTP request to ping the magic update URL
		$http = wp_remote_request($url, array(
			'headers' => $headers,
			'timeout' => $timeout,
		));
		
	} /* GrabFeaturedImages::scheduled_update() */

	////////////////////////////////////////////////////////////////////////////
	// SETTINGS UI /////////////////////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////////

	public function admin_init () {
		global $pagenow;
		global $gfi_path;

		if (class_exists('FeedWordPressSettingsUI')) :
			if ( FeedWordPressSettingsUI::is_admin() ) :
				wp_enqueue_style( 'thickbox' );
				wp_enqueue_script( 'sic-em-image-picker', WP_PLUGIN_URL.'/'.$gfi_path.'/image-picker.js',array('thickbox'), false, true );
			elseif ( 'media-upload.php' == $pagenow || 'async-upload.php' == $pagenow ) :
				add_filter( 'image_send_to_editor', array( $this,'image_send_to_editor'), 1, 8 );
				add_filter( 'gettext', array( $this, 'replace_text_in_thickbox' ), 1, 3 );
				add_filter( 'media_upload_tabs', array( $this, 'media_upload_tabs' ) );
			endif;
		endif;
	} /* GrabFeaturedImages::admin_init () */

	public function diagnostics ($diag, $page) {
		$sect = 'Grab Featured Images'; $pre = "gfi";
		$diag[$sect]["$pre:capture"] = 'as syndicated images are captured or rejected for local copies';
		$diag[$sect]["$pre:capture:error"] = 'when there is an error encountered when trying to capture a local copy of an image'; 
		$diag[$sect]["$pre:capture:http"] = 'as the HTTP GET request is sent to capture a local copy of a syndicated image or full HTML';
		$diag[$sect]["$pre:capture:html"] = 'as GAFFer attempts to extract elements from recently-retrieved full HTML';
		$diag[$sect]["$pre:capture:reject"] = 'when a captured image is rejected instead of being kept as a local copy';
		return $diag;
	} /* GrabFeaturedImages::diagnostics () */
	
	function add_queue_box ($page) {
		add_meta_box(
			/*id=*/ "feedwordpress_{$this->name}_queue_box",
			/*title=*/ __(FWPGFI_QUEUE_LABEL),
			/*callback=*/ array(&$this, 'display_queue'),
			/*page=*/ $page->meta_box_context(),
			/*context=*/ $page->meta_box_context()
		);
	} /* GrabFeaturedImages::add_queue_box() */

	function display_queue ($page, $box = NULL) {
		$posts = array(); $urls = array();
		$q = new WP_Query(array(
		'meta_key' => '_syndicated_full_html_capture',
		'posts_per_page' => -1,
		'order' => 'ASC',
		));
		while ($q->have_posts()) : $q->the_post();
			$posts[$q->post->ID] = $q->post;
			$urls[$q->post->ID] = get_post_meta($q->post->ID, '_syndicated_full_html_capture');
		endwhile;
?>
<table style="width: 100%">
<thead>
<tr>
<th>Status</th>
<th>Post</th>
<th>Date</th>
<th>URL</th>
</tr>
</thead>
<tbody>
<?php foreach ($posts as $ID => $p) : ?>
<tr>
<td><?php print ucfirst($p->post_status); ?></td>
<td><?php print $p->post_title; ?></td>
<td><?php print $p->post_date; ?></td>
<td><?php foreach ($urls[$ID] as $url) :
	print '<a href="'.$url.'">'.feedwordpress_display_url($url)."</a>";
endforeach; ?></td>
</tr>
<?php endforeach; ?>
</body>
</table>
<?php
	}

	public function add_settings_box ($page) {
		add_meta_box(
			/*id=*/ "feedwordpress_{$this->name}_full_text_settings_box",
			/*title=*/ __("Full HTML of Posts"),
			/*callback=*/ array(&$this, 'display_full_text_settings'),
			/*page=*/ $page->meta_box_context(),
			/*context=*/ $page->meta_box_context()
		);
		add_meta_box(
			/*id=*/ "feedwordpress_{$this->name}_feature_image_settings_box",
			/*title=*/ __("Featured Images"),
			/*callback=*/ array(&$this, 'display_feature_image_settings'),
			/*page=*/ $page->meta_box_context(),
			/*context=*/ $page->meta_box_context()
		);
	} /* GrabFeaturedImages::add_settings_box() */

	public function display_full_text_settings ($page, $box = NULL) {
		$grabFullHTMLSelector = array(
		"no" => __("<strong>Use contents from feed:</strong> Keep the contents or excerpt provided by the feed"),
		"yes" => __("<strong>Retrieve full text from web:</strong> Attempt to retrieve full text from <code>http://example.com/page/1</code>, using the included link"),
		);
		$gfhParams = array(
		'input-name' => "gfi_grab_full_html",
		"setting-default" => NULL,
		"global-setting-default" => FWPGFI_GRAB_FULL_HTML_DEFAULT,
		"default-input-value" => 'default',
		);

		$rootElements = $page->setting('fwpgfi root elements', null, array("fallback" => false));
		$filterOut = $page->setting('fwpgfi filter out', null, array("fallback" => false));
		$filterIn = $page->setting('fwpgfi filter in', null, array("fallback" => false));

?>
		<table class="edit-form narrow">
		<tbody>
		<tr><th scope="row"><?php _e('Retrieve full HTML:'); ?></th>
		<td><p>When a syndicated post includes a short text description and a
		link to the full story at <code>http://example.com/page/1</code>,</p>
		<?php
			$page->setting_radio_control(
				'grab full html', 'grab_full_html',
				$grabFullHTMLSelector, $gfhParams
			);
		?></td></tr>

		<tr class="hide-if-js grab-full-text-advanced"><th scope="row"><?php _e('Parsing and Filtering HTML'); ?><td><h3>Look for full text in these container elements (XPath, one per line, in order):</h3>
<textarea name="fwpgfi_root_elements" placeholder="<?php print esc_attr(FWPGFI_FULL_HTML_ROOT_ELEMENTS); ?>">
<?php print esc_html($rootElements); ?>
</textarea>

<h3>Within a container element, include text and HTML contained within these content  elements:</h3>
<textarea name="fwpgfi_filter_in" placeholder="<?php print esc_attr(FWPGFI_FULL_HTML_CONTENT_FILTER_IN); ?>">
<?php print esc_html($filterIn); ?>
</textarea>

<h3>Exclude any content contained in these elements:</h3>
<textarea name="fwpgfi_filter_out" placeholder="<?php print esc_attr(FWPGFI_FULL_HTML_CONTENT_FILTER_OUT); ?>">
<?php print esc_html($filterOut); ?>
</textarea>

		</td></tr>

		<?php
		if ($page->for_default_settings()) :
			$value = $this->process_posts_max();
		?>
		<tr class="hide-if-js grab-full-text-advanced"><th scope="row"><?php _e('Queued web requests:'); ?></th>
		<td><p>Process <input type="number" min="-1" step="1" size="4" value="<?php print esc_attr($value); ?>" name="fwpgfi_process_posts_max" /> queued requests per update cycle.</p>
		<div class="setting-description">If you start seeing long delays between when posts are syndicated and when their full text is retrieved &#8212; or if posts start piling up in the <?php print FWPGFI_QUEUE_LABEL; ?> &#8212; you may need to adjust this setting higher. If you start noticing that update processes take too long to complete, you may need to adjust this setting lower. Use a value of <code>-1</code> to force FWP+: GAFFer to process <em>all</em> queued requests during <em>every</em> update cycle.</div>
		</td></tr>
		<?php endif; ?>

		<tr id="grab-full-text-advanced-toggle" class="hide-if-no-js"><th></th><td><a href="#" id="grab-full-text-advanced-toggle-link">Advanced Settings</a></td></tr>

		
		</tbody>
		</table>
<?php
	} /* GrabFeaturedImages::display_full_text_settings() */

	public function display_feature_image_settings ($page, $box = NULL) {
			
		$featureImagesSelector = array(
		"no" => __("Just display the image"),
		"yes" => __("Capture a local copy of the first image to use as the Featured Image for the syndicated post"),
		);
			
		$fisParams = array(
		'input-name' => 'gfi_feature_images',
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
			
		$imageTypes = array('image/jpeg' => 'JPEG', 'image/gif' => 'GIF', 'image/png' => 'PNG', 'image/vnd.microsoft.icon' => 'ICO', 'image/tiff' => 'TIFF',  );
			
			$gfi_min_width = $page->setting('gfi min width', 0);
			$gfi_min_height = $page->setting('gfi min height', 0);
			$gfi_mime_whitelist = $page->setting('gfi mime whitelist', NULL);
			$gfi_mime_whitelist = ((strlen($gfi_mime_whitelist)>0) ? explode("|", $gfi_mime_whitelist) : NULL); 
			$gfi_mime_blacklist = $page->setting('gfi mime blacklist', NULL);
			$gfi_mime_blacklist = ((strlen($gfi_mime_blacklist)>0) ? explode("|", $gfi_mime_blacklist) : NULL);
 
			$gfi_crop_ratio = $page->setting('gfi crop ratio', NULL);
			$gfi_resize = $page->setting('gfi resize', NULL);

			$stripFeaturedImageSelector = array(
			'no' => 'Leave references to the image in the post',
			'yes' => 'Strip the image out of the syndicated post content',
			);
			$sfisParams = array(
			'input-name' => 'gfi_strip_featured_image',
			'setting-default' => NULL,
			'global-setting-default' => 'no',
			'default-input-value' => 'default',
			);
			
			
			$stripUncacheableImagesSelector = array(
			'no' => 'Leave the image in the post with a hotlink to the original image location',
			'yes' => 'Strip the image out of the syndicated post content',
			);
			
			$suisParams = array(
			'input-name' => 'gfi_strip_uncacheable_images',
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
		<tbody>

		<tr><th scope="row"><?php _e('Feature images:'); ?></th>
		<td><p>When FeedWordPress finds images in a syndicated post...</p>
		<?php
			$page->setting_radio_control(
				'feature captured images', 'feature_captured_images',
				$featureImagesSelector, $fisParams);
		?></td></tr>
		
		<tr><th scope="row"><?php _e('Default featured image:'); ?></th>
		<td><div id="gfi-default-featured-image-display" style="float: left; margin-right: 10px;">
		<?php if ($defaultFeaturedImage) : $url = wp_get_attachment_url($defaultFeaturedImage); ?>
		<img src="<?php print esc_attr($url); ?>" style="height: 60px; width: 60px" />
		<?php endif; ?>
		</div>
		<input type="number" id="gfi-default-featured-image" name="gfi_default_featured_image" value="<?php print esc_attr($defaultFeaturedImage); ?>" size="3" />
		</td></tr>
		
		<tr><th scope="row"><?php _e('Featured image in post content:'); ?></th>
		<td><p style="margin-top: 0px">When an image is used to set the Featured Image for a syndicated post...</p>
		<?php
			$page->setting_radio_control(
				'gfi strip featured image', 'gfi_strip_featured_image',
				$stripFeaturedImageSelector, $sfisParams
			);
		?>
		</td></tr>
		
		<tr class="hide-if-js grab-feature-images-advanced"><th scope="row"><?php _e('Image Size: '); ?></th>
		<td>
<?php
		// Only display these settings if PHP has the capacity to make use of em
		if (SICWebImage::has_gd()) :
?>
		<p style="margin-top:0px"><label>Crop to aspect ratio: <input type="text" name="gfi_crop_ratio" value="<?php print esc_attr($gfi_crop_ratio); ?>" placeholder="ex.: 1:1, 2:3, 16:9" /></label></p>
		<p style="margin-top:0px"><label>Rescale to dimensions: <input type="text" name="gfi_resize" value="<?php print esc_attr($gfi_resize); ?>" placeholder="ex.: 400x300, 400x, x300" /></label></p>
<?php
		else :
?>
		<p style="margin-top: 0px; font-style: italic; color: #606060">Image cropping and rescaling are not available unless the <a href="http://us2.php.net/manual/en/book.image.php">PHP GD image functions</a> are installed for your hosting environment. Check with your web hosting provider about how to install these modules if you need them.</p>
<?php
		endif;
?>
		</td></tr>
		<tr class="hide-if-js grab-feature-images-advanced"><th scope="row"><?php _e('Image Filters: '); ?></th>
		<td><ul class="options">
		<li><label><input type="checkbox" name="gfi_min_width_use" value="Yes" <?php if (is_numeric($gfi_min_width) and $gfi_min_width > 0) : ?> checked="checked"<?php endif; ?> /> <strong>Width:</strong>  Only cache images</label> that are at least <input type="number" name="gfi_min_width" value="<?php print (int) $gfi_min_width; ?>" min="0" step="10" size="4" /> pixels wide</li>
		<li><label><input type="checkbox" name="gfi_min_height_use" <?php if (is_numeric($gfi_min_height) and $gfi_min_height > 0) : ?> checked="checked"<?php endif; ?> value="Yes" /> <strong>Height:</strong> Only cache images</label> that are at least <input type="number" name="gfi_min_height" value="<?php print (int) $gfi_min_height; ?>" min="0" step="10" size="4" /> pixels high</li>
		<li><label><input type="checkbox" <?php if (!is_null($gfi_mime_whitelist)) : ?> checked="checked"<?php endif; ?> name="gfi_mime_whitelist_use" value="Yes" /> <strong>Permitted Image Types:</strong> <em>Only</em> cache images of certain types:</label>
			<ul class="suboptions">
			<?php foreach ($imageTypes as $type => $label) : ?>
			<li><label style="white-space: nowrap"><input type="checkbox" name="gfi_mime_whitelist[]" <?php if (!is_null($gfi_mime_whitelist) and in_array($type, $gfi_mime_whitelist)) : ?> checked="checked"<?php endif; ?> value="<?php print esc_attr($type); ?>" /><?php print $label; ?> (<code><?php print $type; ?></code>)</label></li>
			<?php endforeach; ?>
			</ul></li>
		<li><label><input type="checkbox" name="gfi_mime_blacklist_use" <?php if (!is_null($gfi_mime_blacklist)) : ?> checked="checked"<?php endif; ?> value="Yes" /> <strong>Forbidden Image Types:</strong> <em>Don&#8217;t</em> capture images of certain types:</label>
			<ul class="suboptions">
			<?php foreach ($imageTypes as $type => $label) : ?>
			<li><label style="white-space: nowrap"><input type="checkbox" name="gfi_mime_blacklist[]" <?php if (!is_null($gfi_mime_blacklist) and in_array($type, $gfi_mime_blacklist)) : ?> checked="checked"<?php endif; ?> value="<?php print esc_attr($type); ?>" /><?php print $label; ?> (<code><?php print $type; ?></code>)</label></li>
			<?php endforeach; ?>
			</ul></li>
		</ul></td></tr>

		<tr class="hide-if-js grab-feature-images-advanced"><th scope="row"><?php _e('Uncacheable Images:'); ?></th>
		<td><p style="margin-top: 0px">When a filter prevents FeedWordPress from
		capturing a local copy of a syndicated image...</p>
		<?php
			$page->setting_radio_control(
				'gfi strip uncacheable images', 'gfi_strip_uncacheable_images',
				$stripUncacheableImagesSelector, $suisParams
			);
		?>
		</td></tr>
		<tr id="grab-feature-images-advanced-toggle" class="hide-if-no-js"><th></th><td><a href="#" id="grab-feature-images-advanced-toggle-link">Advanced Settings</a></td></tr>
		</tbody>
		</table>
		<?php
	} /* GrabFeaturedImages::display_settings() */
	
	public function save_settings ($params, $page) {
		if (isset($params['gfi_feature_images'])) :
			$page->update_setting('feature captured images', $params['gfi_feature_images']);
			$page->update_setting('featured image default', $params['gfi_default_featured_image']);
			
			// empty strings mean a null value
			foreach (array('crop ratio', 'resize') as $key) :
				$idx = "gfi_" . str_replace(" ", "_", $key);
				$setting = 'gfi ' . $key;

				if (strlen(trim($params[$idx])) > 0) :
					$page->update_setting($setting, $params[$idx]);
				else :
					$page->update_setting($setting, NULL);
				endif;
			endforeach;

			// collapse arrays to strings
			foreach (array('mime_whitelist', 'mime_blacklist') as $key) :
				if (is_array($params["gfi_$key"])) :
					$params["gfi_$key"] = implode("|", $params["gfi_$key"]);
				endif;
			endforeach;
			
			// check for enabling checkmark
			foreach (array('min_width', 'min_height', 'mime_whitelist', 'mime_blacklist') as $key) :
				if (isset($params["gfi_${key}_use"]) and "yes"==strtolower($params["gfi_${key}_use"])) :
					$update_to = $params["gfi_${key}"];
				else :
					$update_to = NULL;
				endif;
				
				$spacedKey = str_replace("_", " ", $key);
				$page->update_setting("gfi ${spacedKey}", $update_to);
			endforeach;
			
			$page->update_setting('gfi strip featured image', $params["gfi_strip_featured_image"]);
			$page->update_setting('gfi strip uncacheable images', $params["gfi_strip_uncacheable_images"]);

			$page->update_setting('grab full html', $params['gfi_grab_full_html']);

			$rootElements = preg_replace('/[\r\n]+/', "\n", trim($params['fwpgfi_root_elements']));
			if (strlen($rootElements) == 0) : $rootElements = FWPGFI_FULL_HTML_ROOT_ELEMENTS; endif;
			$page->update_setting('fwpgfi root elements', $rootElements);

			$filterOut = preg_replace('/[\r\n]+/', "\n", trim($params['fwpgfi_filter_out']));
			if (strlen($filterOut) == 0) : $filterOut = FWPGFI_FULL_HTML_CONTENT_FILTER_OUT; endif;
			$page->update_setting('fwpgfi filter out', $filterOut);

			$filterIn = preg_replace('/[\r\n]+/', "\n",trim($params['fwpgfi_filter_in']));
			if (strlen($filterIn) == 0) : $filterIn = FWPGFI_FULL_HTML_CONTENT_FILTER_IN; endif;
			$filterIn = $page->update_setting('fwpgfi filter in', $filterIn);

			if ($page->for_default_settings()) :
				update_option('fwpgfi_process_posts_max', $params['fwpgfi_process_posts_max']);

			endif;

		endif;
	} /* GrabFeaturedImages::save_settings () */


	public function feedwordpress_feedfinder_form ($f, $post, $link, $for_feed_settings) {
		$probablyNotFullHTML = false;

		// Does this post have a distinct excerpt?
		if (is_object($post->entry)) :
			$excerpt = $post->entry->get_description();
			$content = $post->content();

			// If these are the same, and there are no HTML tags, there's a high
			// probability that this is not a full HTML feed.
			if ($excerpt == $content) :
				$probablyNotFullHTML = (strip_tags($excerpt) == $excerpt);
			endif;

			// If the average post is very short (let's say < 1,000 characters
			// without tags) then it probably is not a full HTML feed
			$sumLen = 0.0;
			$aPosts = $post->link->live_posts();
			foreach ($aPosts as $oItem) :
				$sumLen += 1.0*strlen(strip_tags($oItem->get_content()));
			endforeach;
			$avgLen = ($sumLen / count($aPosts));
			if ($avgLen < 1000) :
				$probablyNotFullHTML = true;
			endif;
		endif;

		if ($probablyNotFullHTML) :
?>
			<div style="background-color: #ffd0d0; padding: 0em 1em 1em 1em; width: 50%;">
			<p><strong>Full HTML?</strong> Some features of this feed seem to indicate that this feed may be carrying only excerpts and not the full HTML of syndicated posts.</p>
			<p>When a syndicated post includes a short text description and a 	link to the full story at <code>http://example.com/page/1</code>,</p>
			<ul class="options">
			<li><label style="font-weight: normal"><input name="gfi_grab_full_html" value="no" type="radio">
			<strong>Use contents from feed:</strong> Keep the contents or excerpt provided by the feed</label></li>
			<li><label style="font-weight: normal"><input name="gfi_grab_full_html" value="yes" type="radio">
			<strong>Retrieve full text from web:</strong> Attempt to retrieve full text from <code>http://example.com/page/1</code>, using the included link</label></li>
			<li><label style="font-weight: normal"><input name="gfi_grab_full_html" value="default" type="radio" checked="checked"> Use the site-wide default setting.</li>
			</ul> <!-- class="options" -->
			</div>
<?php
		endif;

	}

	public function feedwordpress_admin_switchfeed ($feed, $link) {
		if (is_object($link)) :
			if (!is_null(MyPHP::post('gfi_grab_full_html'))) :
				$link->update_setting('grab full html', MyPHP::post('gfi_grab_full_html'));
				$link->save_settings();
			endif;
		endif;
	}

	////////////////////////////////////////////////////////////////////////////
	// FUNCTIONALITY ///////////////////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////////

	public function the_content ($content) {
		global $post;
		
		if (function_exists('is_syndicated')) :
			if (is_syndicated()) :				
				$source = get_syndication_feed_object($post->ID);

				$sfi = $source->setting('gfi strip featured image', 'gfi_strip_featured_image', 'no');
				switch ($sfi) :
				case 'yes' :
					$thumbId = get_post_thumbnail_id($post->ID);
					if (!!$thumbId) :
						$feat_img = wp_get_attachment_url($thumbId);
						$find_url = preg_quote($feat_img);
						$content = preg_replace(
							':(<img \s+ [^>]*src=[^>]*)'.$find_url.'([^>]*>):ix',
							/*blank it out*/ '',
							$content
						);
					endif;
					break;
				case 'no' :
				default :
					// NOOP
				endswitch;
				
			endif;
		endif;
		
		return $content;
	} /* GrabFeaturedImages::the_content() */

	public function process_post ($data, $post) {
		# O.K., so we need to be a good citizen here and check whether
		# or not the post has been filtered out by a previous filter.
		# If so, just stop here. Otherwise, it should be safe to proceed
		if ($post->filtered() or !is_array($data)) :
			return $data;
		endif;
		
		# (1) The short post description is used for initial post content and
		# then excerpted for the excerpt. We want to use rss:description
		# verbatim for the excerpt.
		$data['post_excerpt'] = $post->entry->get_description();
		
		# (2) The rss:link element carries a link to the full content in HTML.
		# We may need to save this for future use.
		if ($post->entry->get_link() and ('yes'==$post->link->setting('grab full html', 'grab_full_html', FWPGFI_GRAB_FULL_HTML_DEFAULT))) :
			$data['meta']['_syndicated_full_html_capture'] = array($post->entry->get_link());
			
			if ($post->freshness() < 2) :
				// This is not a new post, so let's not revert back to the
				// brief excerpt. This will get overwritten with new full HTML
				// shortly, anyway.
				$cur = get_post($post->wp_id());
				$data['post_content'] = $cur->post_content;
			endif;
		endif;

		# (3) We may as well check the current post content for images to capture.
		$aaImgs = $this->scan_for_images(array(
		"post_content" => $data['post_content'],
		"thumb_links" => $post->entry->get_links(/*rel=*/ "http://github.com/radgeek/FWPPitchMediaGallery/wiki/thumbnail"),
		"links" => $post->entry->get_links(/*rel=*/ "enclosure"),
		"enclosures" => $post->entry->get_enclosures(),
		));
		foreach ($aaImgs as $key => $aImgs) :
			foreach ($aImgs as $aImg) :
				if (strlen($aImg) > 0) :
					if (!isset($data['meta'][$key])) :
						$data['meta'][$key] = array();
					endif;
					$data['meta'][$key][] = $aImg;
				endif;
			endforeach;
		endforeach;

		# (4) Set default thumbnail, if available and applicable. May be revised later.
		$thumb_id = $post->link->setting('featured image default', 'featured_image_default', NULL);
		if ($thumb_id) :
			$data['meta']['_thumbnail_id'] = $thumb_id;
		endif;
		
		return $data;
	} /* function GrabFeaturedImages::process_post () */

	public function scan_for_images ($args = array()) {
		$args = wp_parse_args($args, array(
		"post_content" => "",
		"thumb_links" => array(),
		"links" => array(),
		"enclosures" => array(),
		));
		$post_content = $args["post_content"];
		$thumb_links = $args['thumb_links'];
		$link_elements = $args["links"];
		$enclosures = $args["enclosures"];

		$ret = array(
			"_syndicated_image_capture" => array(),
			"_syndicated_image_featured" => array(),
		);

		# (1) Match any image elements from HTML in the syndicated item

		$oDoc = new DOMDocument;
		@$oDoc->loadHTML($post_content);
		$imgTags = $oDoc->getElementsByTagName('img');
		foreach ($imgTags as $imgTag) :
			if (!preg_match('/^data:.*$/i', $imgTag->getAttribute('src'))) :
				$ret['_syndicated_image_capture'][] = $imgTag->getAttribute('src');
			endif;
		endforeach;

		# (2) Check for a specially marked-up indicator of a thumbnail/Feature Image link
		if (is_array($thumb_links) and count($thumb_links) > 0) :
			foreach ($thumb_links as $href) :
				$ret['_syndicated_image_capture'][] = $href;
				$ret['_syndicated_image_featured'][] = $href;
			endforeach;
		endif;
		
		# (3) Check for all <link rel="enclosure"/> elements that may indicate an image of interest
		if (is_array($link_elements) and count($link_elements) > 0) :
			foreach ($link_elements as $href) :
				$ret['_syndicated_image_capture'][] = $href;
			endforeach;
		endif;

		# (4) Check enclosures and <media:group/> elements
		if (is_array($enclosures) and count($enclosures) > 0) :
			foreach ($enclosures as $enclosure) :
				$ret['_syndicated_image_capture'][] = $enclosure->get_link();
			endforeach;
		endif;

		return $ret;
	} /* function GrabFeaturedImages::scan_for_images() */

	/**
	 * process_full_html
	 *
	 * @param array $delta Unused
	 *
	 * @uses WP_Query::have_posts
	 * @uses WP_Query::the_post
	 * @uses get_post_custom_values
	 * @uses add_post_meta
	 * @uses delete_post_meta
	 * @uses get_syndication_feed_object
	 * @uses SyndicatedLink::setting
	 * @uses GrabFeaturedImages::process_posts_max
	 * @uses GrabFeaturedImages::grab_text
	 * @uses GrabFeaturedImages::insert_revision
	 * @uses GrabFeaturedImages::scan_for_images
	 * @uses $post
	 */
	public function process_full_html ($delta) {
		global $post, $wpdb;

		// Let's do this.
		$q = new WP_Query(array(
		'meta_key' => '_syndicated_full_html_capture',
		'posts_per_page' => $this->process_posts_max(),
		'order' => 'ASC',
		'orderby' => 'rand',
		));

		while ($q->have_posts()) : $q->the_post();
			$this->post = $post;

			$zapit = false;
			$captured_from = array();
			
			$failed_from = get_post_custom_values('html capture failed');
			$urls = get_post_custom_values('_syndicated_full_html_capture');

			$source = get_syndication_feed_object($post->ID);

			$post_images = array();
			$post_content = $post->post_content;

			if ((count($urls) > 0) and !!$urls[0] and ('yes'==$source->setting('grab full html', 'grab_full_html', FWPGFI_GRAB_FULL_HTML_DEFAULT))) :
				
				foreach ($urls as $url) :
					if ($url) :

						$post_content = $this->grab_text($url, $post->ID);
						
						$ok = false;
						if (is_string($post_content)) :
							$post->post_content = $post_content;
							
							// Save as a revision of the existing post.
							$ok = $this->insert_revision($post); 
						endif;
						
						if ($ok) :
							$zapit = true;
							$captured_from[] = time()." ".$url." ".substr(FeedWordPress::val($post_content),0,128);
						else :
							$failed_from[] = time()." ".$url." ".substr(FeedWordPress::val($post_content),0,128);

							if (count($failed_from) > 3) : // strikes and yr out
								$zapit = true;
							endif;
						endif;
					endif;
				endforeach;
				
			else :
				
				$zapit = true;
				
			endif;

			// Now that we have full HTML to work over, scan for <img/> tags etc.
			$aaImgs = $this->scan_for_images(array(
			"post_content" => $post_content,
			));
			$post_images = $aaImgs['_syndicated_image_capture'];

			// Tack on the URLs of images included in the <img /> tags.
			if (count($post_images) > 0) :
				foreach ($post_images as $img) :
					add_post_meta($post->ID, '_syndicated_image_capture', $img, /*unique=*/ false);
				endforeach;
			endif;

			if ($zapit) :
				delete_post_meta($post->ID, '_syndicated_full_html_capture');
			endif;
			if (count($captured_from) > 0) :
				foreach ($captured_from as $url) :
					add_post_meta($post->ID, 'html captured from', $url,
					/*unique=*/ false);
				endforeach;
			endif;
			if (count($failed_from) > 0) :
				delete_post_meta($post->ID, 'html capture failed');
				foreach ($failed_from as $url) :
					add_post_meta($post->ID, 'html capture failed', $url, /*unique=*/ false);
				endforeach;
			endif;
		endwhile;
	} /* function GrabFeaturedImages::process_full_html() */

	public function process_captured_images ($delta) {
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

			if ((count($imgs) > 0) and !!$imgs[0] and ('yes'==$source->setting('feature captured images', 'feature_captured_images', 'no'))) :
				$seekingFeature = ('yes' == $source->setting('feature captured images', 'feature_captured_images', NULL));

				foreach ($imgs as $img) :
					
					$imgGuid = SICWebImage::guid($img);
					$guid = $wpdb->escape($imgGuid);
					
					$result = $wpdb->get_row("
					SELECT ID FROM $wpdb->posts
					WHERE guid='$guid' AND post_type='attachment'
					");
					
					if (!$result) : // Attachment not yet created
						$params = array(
						"min width" => $source->setting('gfi min width', 'gfi_min_width', 0),
						"min height" => $source->setting('gfi min height', 'gfi_min_height', 0),
						"blacklist" => explode("|", $source->setting('gfi mime blacklist', 'gfi_mime_blacklist', NULL)),
						"whitelist" => explode("|", $source->setting('gfi mime whitelist', 'gfi_mime_whitelist', NULL)),
						"crop" => $source->setting('gfi crop ratio', 'gfi_crop_ratio', NULL),
						"resize" => $source->setting('gfi resize', 'gfi_resize', NULL),
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
								
								/*FIXME: Make this logic configurable*/
								$zapit = true;
								break; // exit foreach
							endif;
						endif;
						
						$zapit = true;
						
					endif;
				endforeach;

				foreach ($replacements as $url => $attach_id) :
					$replacement = NULL;
					if ($attach_id < 0) :
						$new_url = NULL;
						if ($source->setting('gfi strip uncacheable images', 'gfi_strip_uncacheable_images', 'no')=='yes') :
							FeedWordPress::diagnostic('gfi:capture', 'Image  ['.$url.'] not cached; stripping image.');
							$replacement = '';
						else :
							FeedWordPress::diagnostic('gfi:capture', 'Image  ['.$url.'] not cached; leaving hotlinked image.');							$replacement = NULL;
						endif;
					else :
						FeedWordPress::diagnostic('gfi:capture', 'Captured image ['.$url.'] to local URL ['.$new_url.']');
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

					endif;
				endforeach;
				
				// Save as a revision of the existing post.
				$this->insert_revision($post);

				if ($zapit) :
					delete_post_meta($post->ID, '_syndicated_image_capture');
				endif;
			endif;
		endwhile;

	} /* GrabFeaturedImages::process_captured_images () */

	////////////////////////////////////////////////////////////////////////////
	// UTILITY FUNCTIONS ///////////////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////////

	function process_posts_max () {
		$max = get_option('fwpgfi_process_posts_max', FWPGFI_PROCESS_POSTS_MAX);
		if (!is_numeric($max)) :
			$max = FWPGFI_PROCESS_POSTS_MAX;
		endif;
		return $max;
	} /* GrabFeaturedImages::process_posts_max () */
	
	public function insert_revision ($post) {

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

	} /* GrabFeaturedImages::insert_revision () */
	
	public function fix_revision_meta ($revision_id) {
		global $wpdb;
		
		$post_author = (int) $this->post->post_author;
		
		$revision_id = (int) $revision_id;
		$wpdb->query("
		UPDATE $wpdb->posts
		SET post_author={$this->post->post_author}
		WHERE post_type = 'revision' AND ID='$revision_id'
		");
	} /* GrabFeaturedImages::fix_revision_meta () */
	
	public function grab_text ($url, $to, $args = array()) {
		$args = wp_parse_args($args, array( // Default values
		'source' => NULL,
		));
		if (is_null($args['source'])) :
			$args['source'] = get_syndication_feed_object($to);
		endif;
		
		$source = $args['source'];
		
		$text = NULL;

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
		
		FeedWordPress::diagnostic('gfi:capture:http', "HTTP &raquo;&raquo; GET [$url] (".__METHOD__.")");
		$http = wp_remote_request($url, array(
			'headers' => $headers,
			'timeout' => $timeout,
			'authentication' => $source->authentication_method(),
			'username' => $source->username(),
			'password' => $source->password(),
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
			$text = $data;
			if (!is_null($data)) :
				switch ($mimetype) :
				case 'text/html' :
				case 'application/xhtml+xml' :

					$oDoc = new DOMDocument("1.0", get_bloginfo('charset'));

					// "Error suppression is not the proper way of dealing with this issue," StackOverflow Dude tells me, and yet.... 
					// HTML5 content, which is now completely pervasive, triggers errors that libxml still doesn't allow you to bypass
					// so let's just EAT THEM ALL UP. https://stackoverflow.com/questions/6090667/php-domdocument-errors-warnings-on-html5-tags
					libxml_use_internal_errors(true);

					// Let's try to head off potential encoding issues at the pass here.
					$data = mb_convert_encoding($data, 'html-entities', mb_detect_encoding($data));
					$oDoc->loadHTML($data, LIBXML_ERR_NONE);
					$oXPath = new DOMXpath($oDoc);
					libxml_clear_errors();

					$mainElements = $source->setting('fwpgfi root elements', 'fwpgfi_root_elements', FWPGFI_FULL_HTML_ROOT_ELEMENTS);
					$aElements = array_map(function ($item) { return trim($item); }, explode("\n", $mainElements));
					$outFilter = explode("\n", $source->setting('fwpgfi filter out', 'fwpgfi_filter_out', FWPGFI_FULL_HTML_CONTENT_FILTER_OUT)); 
					$inFilter = $source->setting('fwpgfi filter in', 'fwpgfi_filter_in', FWPGFI_FULL_HTML_CONTENT_FILTER_IN);
					if ($inFilter != '*') :
						$inFilter = explode("\n", $inFilter);
					endif;

					foreach ($aElements as $sElement) :

						if ($sElement[0] != '/') :
							$sElement = '//' . $sElement;
						endif;

						$cEls = $oXPath->query($sElement);
						if ($cEls->length > 0) :
							FeedWordPress::diagnostic('gfi:capture:html', "HTML Parsing: Found viable root element [$sElement] (".__METHOD__.")");
							$data = '';
							foreach ($cEls as $oEl) :
								self::scrubHTMLElements($oEl, $outFilter, $inFilter);
								$data .= self::DOMInnerHTML($oEl);
							endforeach;
							break; // exit foreach
						endif;
					endforeach;
					$text = FWPGFI_FULL_HTML_PRE.$data.FWPGFI_FULL_HTML_POST;
				endswitch;
			endif;
		else :
			FeedWordPress::diagnostic('gfi:capture:http', "&laquo;&laquo; ERROR [$url] (".__METHOD__."): ".FeedWordPress::val($http));
			$text = NULL;
		endif;
		return $text;
	}

	public function attach_image ($url, $to, $args = array()) {
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
		
		FeedWordPress::diagnostic('gfi:capture:http', "HTTP &raquo;&raquo; GET [$url]");

		$params = apply_filters('gfi_remote_request_params', array(
			'headers' => $headers,
			'timeout' => $timeout,
		), $url);
	
		$http = apply_filters('gfi_remote_request', NULL, $url, $params);
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
					FeedWordPress::diagnostic('gfi:capture:reject',
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
			FeedWordPress::diagnostic('gfi:capture:error', "Failed GET [$url] &laquo;&laquo; ".$error_message);
		endif;

		return $attach_id;
	} /* GrabFeaturedImages::attach_image () */
	
	public function allowedtype ($type, $args) {
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
	} /* GrabFeaturedImages::allowedtype() */

	public function fix_async_upload_image() {
		if (isset($_REQUEST['attachment_id'])) {
			$GLOBALS['post'] = get_post($_REQUEST['attachment_id']);
		}
	} /* GrabFeaturedImages::fix_async_upload_iamge () */

	/**
	 * Test context to see if the uploader is being used for the image widget or for other regular uploads
	 *
	 * @return bool
	 * @author Shane & Peter, Inc. (Peter Chester)
	 */
	public function is_sic_pick_context() {
		global $fwp_path;
			
		if ( isset($_SERVER['HTTP_REFERER']) and strpos($_SERVER['HTTP_REFERER'], 'sic_pick_feed_id') !== false ) :
			return true;
		elseif ( isset($_REQUEST['_wp_http_referer']) and strpos($_REQUEST['_wp_http_referer'], 'sic_pick_feed_id') !== false ) :
			return true;
		elseif (isset($_REQUEST['sic_pick_feed_id'])) :
			return true;
		endif;
		return false;
	} /* GrabFeaturedImages::is_sic_pick_context () */
	
	/**
	 * Somewhat hacky way of replacing "Insert into Post" with "Insert into Widget"
	 *
	 * @param string $translated_text text that has already been translated (normally passed straight through)
	 * @param string $source_text text as it is in the code
	 * @param string $domain domain of the text
	 * @return bool
	 * @author Shane & Peter, Inc. (Peter Chester)
	 */
	public function replace_text_in_thickbox($translated_text, $source_text, $domain) {
		if ( $this->is_sic_pick_context() ) {
			if ('Insert into Post' == $source_text) {
				return __('Use as Featured Image', $domain );
			}
		}
		return $translated_text;
	} /* GrabFeaturedImages::replace_text_in_thickbox() */
	
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
	public function image_send_to_editor( $html, $id, $caption, $title, $align, $url, $size, $alt = '' ) {
		// Normally, media uploader return an HTML string (in this case, typically a complete image tag surrounded by a caption).
		// Don't change that; instead, send custom javascript variables back to opener.
		// Check that this is for the widget. Shouldn't hurt anything if it runs, but let's do it needlessly.
		if ( $this->is_sic_pick_context() ) :
			if (is_string($url) and strlen($url) == 0) :
				$src = wp_get_attachment_image_src($id, 'thumbnail');
				if (is_array($src)) :
					$url = $src[0];
				endif;
			endif;

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
	} /* GrabFeaturedImages::image_send_to_editor () */

	/**
	 * Remove from url tab until that functionality is added to widgets.
	 *
	 * @param array $tabs 
	 * @return void
	 * @author Shane & Peter, Inc. (Peter Chester)
	 */
	public function media_upload_tabs($tabs) {
		if ( $this->is_sic_pick_context() ) {
			unset($tabs['type_url']);
		}
		return $tabs;
	} /* GrabFeaturedImages::media_upload_tabs () */

	static public function scrubHTMLElements(DOMNode $element, $outFilter, $inFilter, $xpath = null, $level = 0) {

		if (!isset($element->ownerDocument) or is_null($element->ownerDocument)) :
			return;
		endif;

		$children = $element->childNodes;

		if (is_null($xpath)) :
			$xpath = new DOMXpath($element->ownerDocument);
		endif;

		if (!is_null($children)) :
			$toRemove = array();
			foreach ($children as $child) :
				$removed = false; $blackListed = 0;
				foreach ($outFilter as $black) :
					$cEls = $xpath->query('self::'.$black, $child);
					$blackListed += $cEls->length;
				endforeach;
				if ($blackListed > 0):
					// Blacklisted element. Scrub this out and all of its children.
					$removed = true;
					$toRemove[] = $child;
					FeedWordPress::diagnostic('gfi:capture:html', "HTML Parsing: Scrubbing blacklisted element [{$child->nodeName}] (".__METHOD__.")");
				endif;
			endforeach;

			foreach ($toRemove as $child) :
				$element->removeChild($child);
				break;
			endforeach;
		endif;
	
		$children = $element->childNodes;
		if (!is_null($children)) :
			$toDo = array();
			foreach ($element->childNodes as $child) :
				if (
					(is_string($inFilter) and '*' == $inFilter)
					or (is_array($inFilter) and in_array(strtolower($child->nodeName), $inFilter))
				) :

					// Whitelisted element. Allow this and all of its children EXCEPT for blacklisted elements.
					$toDo[] = array("retain", $child);
				else :
					$toDo[] = array("descend", $child);
				endif;
			endforeach;

			foreach ($toDo as $pair) :
				list($action, $child) = $pair;
				switch ($action) :
				case 'retain' :
					self::scrubHTMLElements($child, $outFilter, '*', $xpath, $level+1);
					break;
				case 'descend' :
					self::scrubHTMLElements($child, $outFilter, $inFilter, $xpath, $level+1);
					if (count($child->childNodes) > 0) :
						$toBubble = array();						
						foreach ($child->childNodes as $grandchild) :
							$toBubble[] = $grandchild;
						endforeach;

						foreach ($toBubble as $grandchild) :
							$cGC = $grandchild->cloneNode(/*deep=*/ true);
							$element->insertBefore($cGC, $child);
						endforeach;
					endif;
					$element->removeChild($child);
					break;
				default :
					// NOOP
				endswitch;
			endforeach;
		endif;
	}
	static public function DOMinnerHTML(DOMNode $element) { 
	    $innerHTML = ""; 
	    $children  = $element->childNodes;

	    foreach ($children as $child) :
		$innerHTML .= $element->ownerDocument->saveHTML($child);
	    endforeach;

	    return $innerHTML; 
	} 
} /* class GrabFeaturedImages */


$gfiAddOn = new GrabFeaturedImages;


