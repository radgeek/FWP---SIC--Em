<?php
/*
 * VariableStream class taken from examples in PHP documentation
 * http://www.php.net/manual/en/stream.streamwrapper.example-1.php
 */
class VariableStream {
	var $position;
	var $varname;
	var $index;

	function stream_open($path, $mode, $options, &$opened_path)
	{
		$url = parse_url($path);
		$this->varname = $url["host"];
		$this->position = 0;

		if (preg_match('|^/(.*)$|', $url['path'], $ref)) :
			$this->index = $ref[1];
		else :
			$this->index = NULL;
		endif;

		return true;
	}

	function &globalVar () {
		if (!is_null($this->index)) :
			return $GLOBALS[$this->varname][$this->index];
		else :
			return $GLOBALS[$this->varname];
		endif;
	} /* VariableStream::globalVar () */

	function stream_read ($count) {
		$ret = substr($this->globalVar(), $this->position, $count);
        	$this->position += strlen($ret);
		return $ret;
	}

	function stream_write ($data) {
		$g = $this->globalVar();
		$left = substr($g, 0, $this->position);
		$right = substr($g, $this->position + strlen($data));
		$g = $left . $data . $right;
		$this->position += strlen($data);
		return strlen($data);
	}

	function stream_tell () {
		return $this->position;
	}

	function stream_eof () {
		return $this->position >= strlen($this->globalVar());
	}

	function stream_seek ($offset, $whence) {
		switch ($whence) {
		case SEEK_SET:
			if ($offset < strlen($this->globalVar()) && $offset >= 0) {
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

	function stream_stat () {
		return array(
		"dev" => 0,
		"ino" => 0,
		"mode" => 0,
		"nlink" => 1,
		"uid" => 0,
		"gid" => 0,
		"rdev" => 0,
		"size" => strlen($this->globalVar()),
		"atime" => 0,
		"mtime" => 0,
		"ctime" => 0,
		"blksize" => -1,
		"blocks" => -1,
		);
	}
} /* class VariableStream */

