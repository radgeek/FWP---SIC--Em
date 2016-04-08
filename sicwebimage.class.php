<?php
require_once(dirname(__FILE__).'/variablestream.class.php');

define('SICEMRAWIMAGE_BASE_URL', 'http://projects.radgeek.com/fwp-capture-thumbnails/?u=');

class SICWebImage {
	private $_url;
	private $_httpParams;
	private $_httpReq;
	private $_data;
	private $_mimetype;
	
	function __construct ($url, $params, $http) {
		$this->_url = $url;
		$this->_httpParams = $params;
		$this->_httpReq = $http;
		
		if ($this->is_ok()) :

			# Get the MIME type from the Content-Type header
			$mimetype = NULL;
			if (isset($http['headers']['content-type'])) :
				$split = explode(";", $http['headers']['content-type'], 2);
				$mimetype = $split[0];
				$mimeparams = (isset($split[1]) ? $split[1] : null);
			endif;
			$mimetype = apply_filters('sicem_remote_request_mimetype', $mimetype, $url, $params, $http);
			
			# Grab the data from the HTTP body and run it through a filter
			$bits = apply_filters('sicem_remote_request_body', $http['body'], $url, $params, $http);
			
		endif;
		$this->set_image($bits, $mimetype);
	}

	/* Simple getter methods */
	function mimetype () { return $this->_mimetype; }
	function data () { return $this->_data; }
	function url () { return $this->_url; }

	/* Simple tester methods */
	function is_ok () {
		return !(
			is_wp_error($this->_httpReq)
			or !isset($this->_httpReq['response'])
			or ($this->_httpReq['response']['code'] != 200) // 200 OK
		);
	}
	function is_image () { return !is_null($this->data()); }
	static function has_gd () { return function_exists('ImageCreateFromString'); }
	
	/* Simple setter methods */
	function set_image ($bits = NULL, $mimetype = NULL) {
		$this->_data = $bits;
		$this->_mimetype = $mimetype;
	}

	/* Utility methods */

	static function guid ($url = '') {
		return (SICEMRAWIMAGE_BASE_URL . md5($url));
	}

	/**
	 * SICWebImage::post_title() - attempt to extract a workable post title
	 * from the URL. Admittedly this is scant pickings. But we can do the basic
	 * filename, with the extension removed; if people's image URLs don't
	 * horribly suck, this might work out OK. If they do, then at least we have
	 * the consolation that we probably could not have done much better.
	 *
	 * @return string Returns the post_title to use when inserting this image
	 * into the WordPress media gallery.
	 */
	function post_title () {
		return preg_replace('/\.[^.]+$/', '', basename($this->url()));
	}
	
	/**
	 * SICWebImage::upload ()
	 *
	 * @return int The numeric ID of the attachment created in the WordPress
	 * media gallery as a result of uploading this image. -1 if upload failed
	 */
	function upload ($attach_to) {
		$attach_id = NULL;
		
		# Now send the image to the upload directory
		$up = wp_upload_bits(
			$this->local_filename(),
			$this->mimetype(),
			$this->data()
		);

		if ($up and !$up['error']) :
			# Now do the attachment
			$attach = array(
			'post_mime_type' => $this->mimetype(),
			'post_title' => $this->post_title(),
			'post_content' => '',
			'post_status' => 'inherit',
			'guid' => self::guid($this->url()),
			);
			$attach_id = wp_insert_attachment($attach, $up['file'], $attach_to);
			
			require_once(ABSPATH.'wp-admin'.'/includes/image.php');
			$attach_data = wp_generate_attachment_metadata($attach_id, $up['file']);

			wp_update_attachment_metadata($attach_id, $attach_data);
		else :
			if (is_array($up) and isset($up['error'])) :
				$error_message = $up['error'];
			else :
				$error_message = preg_replace('/\s+/', " ", FeedWordPress::val($up));
			endif;

			FeedWordPress::diagnostic('sicem:capture:error',
				"Failed image storage [$url]: $error_message"
			);
		endif;
		return $attach_id;
	}
	
	/**
	 * SICWebImage::local_filename() - create an appropriate local filename
	 * for an uploaded image, identified uniquely by source URI and reported
	 * MIME type.
	 *
	 * @return string Returns filename that can be provided to wp_upload_bits()
	 */
	function local_filename () {
		return md5($this->url()) . '.' . $this->file_extension();
	} /* SICWebImage::local_filename () */
	
	function file_extension () {
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
		
		$mimetype = $this->mimetype();
		if (isset($mimes[strtolower(trim($mimetype))])) :
			$ret = $mimes[strtolower(trim($mimetype))];
		else :
			$ret = 'txt'; // I don't like this, but I'm not sure what else to do.
		endif;
		return $ret;
	} /* SICWebImage::file_extension() */
	
	/**
	 * SICWebImage::size() - get dimensions if our PHP environment allows it
	 * using getimagesize() API call. If getimagesize() is not available, return
	 * NULL.
	 * 
	 * @return mixed An array containing image dimensions if available; NULL if
	 * 		we don't have the API support we need to determine image dimensions.
	 */
	function size () {
		$ret = NULL;
		if (function_exists('getimagesize')) :
			$ret = getimagesize($this->streamify(__CLASS__.".".__METHOD__));
		endif;
		return $ret;
	} /* SICWebImage::size() */
	
	/**
	 * SICWebImage::streamify() - because PHP image APIs basically suck in
	 * every possible way, sometimes we have to pretend that the contents of a
	 * string variable are actually a file. This makes use of a utility class,
	 * VariableStream, to create a truly heinous fucking kludge in order to mock
	 * up an internal URI for the contents of a variable.
	 *
	 * @param mixed $index A unique pathname to identify the instance of
	 * VariableStream that we will be interacting with.
	 * @return string Returns a string containing the internal URI that PHP
	 * file functions can treat as the name of a file to read from or write to.
	 */
	function streamify ($index = 0) {
		$store = 'sicEmRemoteImages';
		$protocol = 'sicemvariable';

		// Check whether or not we've registered our URL handler
		$existed = in_array($protocol, stream_get_wrappers());
		if (!$existed) :
			stream_wrapper_register($protocol, "VariableStream");
		endif;
	
		// Now drop the image data into our global
		if (!isset($GLOBALS[$store])) :
			$GLOBALS[$store] = array();
		endif;
		$GLOBALS[$store][$index] = $this->data();

		// ... And return the URL we need.
		return "${protocol}://${store}/${index}";
	}
	
	/**
	 * SICWebImage::constrain() - a GD library-based crop and resize utility
	 * function
	 *
	 * Props to Alix Axel and monowerker at <http://stackoverflow.com/questions/999250/php-gd-cropping-and-resizing-images>
	 * for some nice packaging of the code to do this with only the GD library
	 * functions.
	 */

	function constrain ($crop = null, $size = null) {
		
		$ret = NULL;
		
		if ($this->is_image() and (!is_null($crop) or !is_null($size))) :
			if (self::has_gd()) :
				$image = ImageCreateFromString($this->data());
				$data = NULL; $mimetype = NULL;
				if (is_resource($image) === true) :
					$x = 0;
					$y = 0;
					$width = imagesx($image);
					$height = imagesy($image);
	
					/*
					CROP (Aspect Ratio) Section
					*/
	
					if (is_null($crop) === true) :
						$crop = array($width, $height);
					else :
						$crop = array_filter(explode(':', $crop));
	
						if (empty($crop) === true) :
							$crop = array($width, $height);
						else :
							if ((empty($crop[0]) === true) || (is_numeric($crop[0]) === false)) :
								$crop[0] = $crop[1];
							elseif ((empty($crop[1]) === true) || (is_numeric($crop[1]) === false)) :
								$crop[1] = $crop[0];
							endif;
						endif;
	
						$ratio = array(0 => $width / $height, 1 => $crop[0] / $crop[1]);
	
						if ($ratio[0] > $ratio[1]) :
							$width = $height * $ratio[1];
							$x = (imagesx($image) - $width) / 2;
						elseif ($ratio[0] < $ratio[1]) :
							$height = $width / $ratio[1];
							$y = (imagesy($image) - $height) / 2;
						endif;
					endif;
	
					/*
					Resize Section
					*/
	
					if (is_null($size) === true) :
						$size = array($width, $height);
					else :
						$size = array_filter(explode('x', $size));
	
						if (empty($size) === true) :
							$size = array(imagesx($image), imagesy($image));
						else :
							if ((empty($size[0]) === true) || (is_numeric($size[0]) === false)) :
								$size[0] = round($size[1] * $width / $height);
							elseif ((empty($size[1]) === true) || (is_numeric($size[1]) === false)) :
								$size[1] = round($size[0] * $height / $width);
							endif;
						endif;
					endif;
	
					$result = ImageCreateTrueColor($size[0], $size[1]);
	
					if (is_resource($result) === true) :
						ImageSaveAlpha($result, true);
						ImageAlphaBlending($result, true);
						ImageFill($result, 0, 0, ImageColorAllocate($result, 255, 255, 255));
						ImageCopyResampled($result, $image, 0, 0, $x, $y, $size[0], $size[1], $width, $height);
	
						ImageInterlace($result, true);
	
						ob_start(); // *sigh*
						ImageJPEG($result, null, 90);
						$mimetype = 'image/jpeg';
						$data = ob_get_clean(); // *sigh*
					endif;
	
				endif; // (is_resource($image) === true)
				
				$ret = array($data, $mimetype);
			endif; // function_exists('ImageCreateFromString')
	
			if (!is_null($ret) and !is_null($ret[0])) :
				$this->set_image($ret[0], $ret[1]);
			endif;
		endif;
		
		return $ret;

	}
}

