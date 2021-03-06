<?php

/**
 * @file autoattach.class.php
 * @author Kijin Sung <kijin@kijinsung.com>
 * @license GPLv2 or Later <https://www.gnu.org/licenses/gpl-2.0.html>
 * 
 * This addon automatically finds unattached images in documents and comments
 * and converts them into real attachments. This can be useful because
 * many users cannot distinguish between external images and real attachments,
 * but the website administrator must be careful because self-hosting all
 * images may result in copyright infringement.
 */
class XEAutoAttachAddon
{
	/**
	 * Addon configuration is cached here.
	 */
	protected static $config;
	
	/**
	 * Set the timeout for remote requests.
	 */
	protected static $image_timeout = 4;
	protected static $total_timeout = 20;
	
	/**
	 * Cache to prevent duplicate downloads.
	 */
	protected static $url_cache = array();
	
	/**
	 * Set addon configuration.
	 * 
	 * @param object $config
	 * @return void
	 */
	public static function setConfig($config)
	{
		self::$config = $config;
	}
	
	/**
	 * Process a document.
	 * 
	 * @param int $document_srl
	 * @return bool
	 */
	public static function procDocument($document_srl = 0, $get_fresh_object = false)
	{
		// Does the document exist?
		if (!$document_srl) return false;
		
		// Get the document.
		if ($get_fresh_object)
		{
			$output = executeQuery('addons.autoattach.getDocument', (object)array('document_srl' => $document_srl));
			if (!$output || !$output->data) return false;
			$oDocument = $output->data;
		}
		else
		{
			$oCachedDocument = getModel('document')->getDocument($document_srl);
			if (!$oCachedDocument || !$oCachedDocument->document_srl) return false;
			$oDocument = (object)array(
				'document_srl' => $oCachedDocument->get('document_srl'),
				'module_srl' => $oCachedDocument->get('module_srl'),
				'member_srl' => $oCachedDocument->get('member_srl'),
				'content' => $oCachedDocument->get('content'),
				'uploaded_count' => $oCachedDocument->get('uploaded_count'),
			);
		}
		
		// Check if the content has unattached images.
		$content = $oDocument->content;
		$images = self::getImages($content);
		if (!count($images)) return false;
		
		// Begin a transaction.
		$oDB = DB::getInstance();
		$oDB->begin();
		
		// Download and replace images.
		$count = self::replaceImages($content, $images, $oDocument->module_srl, $document_srl, $oDocument->member_srl, $errors);
		if (!$count && !$errors) return false;
		
		// Update the document.
		$output = executeQuery('addons.autoattach.updateDocument', (object)array(
			'content' => $content,
			'uploaded_count' => $oDocument->uploaded_count + $count,
			'document_srl' => $document_srl,
		));
		if (!$output)
		{
			$oDB->rollback();
			return false;
		}
		
		// Update cached entries.
		if (!$get_fresh_object)
		{
			$oCachedDocument->add('content', $content);
			$oCachedDocument->add('uploaded_count', $oDocument->uploaded_count + $count);
		}
		$oCacheHandler = CacheHandler::getInstance('object');
		if($oCacheHandler->isSupport())
		{
			$oCacheHandler->delete('document_item:' . getNumberingPath($document_srl) . $document_srl);
		}
		
		// Commit!
		$oDB->commit();
		return true;
	}
	
	/**
	 * Process a comment.
	 * 
	 * @param int $comment_srl
	 * @return bool
	 */
	public static function procComment($comment_srl = 0, $get_fresh_object = false)
	{
		// Does the comment exist?
		if (!$comment_srl) return false;
		
		// Get the comment.
		if ($get_fresh_object)
		{
			$output = executeQuery('addons.autoattach.getComment', (object)array('comment_srl' => $comment_srl));
			if (!$output || !$output->data) return false;
			$oComment = $output->data;
		}
		else
		{
			$oCachedComment = getModel('comment')->getComment($comment_srl);
			if (!$oCachedComment || !$oCachedComment->comment_srl) return false;
			$oComment = (object)array(
				'comment_srl' => $oCachedComment->get('comment_srl'),
				'module_srl' => $oCachedComment->get('module_srl'),
				'member_srl' => $oCachedComment->get('member_srl'),
				'content' => $oCachedComment->get('content'),
				'uploaded_count' => $oCachedComment->get('uploaded_count'),
			);
		}
		
		// Check if the content has unattached images.
		$content = $oComment->content;
		$images = self::getImages($content);
		if (!count($images)) return false;
		
		// Begin a transaction.
		$oDB = DB::getInstance();
		$oDB->begin();
		
		// Download and replace images.
		$count = self::replaceImages($content, $images, $oComment->module_srl, $comment_srl, $oComment->member_srl, $errors);
		if (!$count && !$errors) return false;
		
		// Update the comment.
		$output = executeQuery('addons.autoattach.updateComment', (object)array(
			'content' => $content,
			'uploaded_count' => $oComment->uploaded_count + $count,
			'comment_srl' => $comment_srl,
		));
		if (!$output)
		{
			$oDB->rollback();
			return false;
		}
		
		// Commit!
		$oDB->commit();
		return true;
	}
	
	/**
	 * Get images from HTML content.
	 * 
	 * @param string $content
	 * @return array
	 */
	protected static function getImages($content)
	{
		// Compile the list of except domains.
		if ($except_domains = self::$config->except_domains)
		{
			$except_domains = array_map('trim', explode(',', $except_domains));
		}
		else
		{
			$except_domains = array();
		}
		if ($default_url = Context::getDefaultUrl())
		{
			$except_domains[] = parse_url($default_url, PHP_URL_HOST);
		}
		$except_domains[] = $_SERVER['HTTP_HOST'];
		$except_domains = array_unique($except_domains);
		
		// Convert the list of except domains into a regular expression.
		$except_domains_regexp = array();
		foreach ($except_domains as $domain)
		{
			$except_domains_regexp[] = str_replace('\*\.', '[a-z0-9-]+\.', preg_quote($domain, '@'));
		}
		$except_domains_regexp = '@^https?://(' . implode('|', $except_domains_regexp) . ')/@i';
		
		// Find all images.
		if (preg_match_all('@<img\s[^>]*?src=(\'[^\']+\'|"[^"]+"|[^\'"\r\n\t\x20>]+)[^>]*?>@i', $content, $matches, PREG_SET_ORDER))
		{
			$result = array();
			foreach ($matches as $match)
			{
				if (strpos($match[0], 'data-autoattach="') !== false)
				{
					if (self::$config->retry_download !== 'Y' || strpos($match[0], 'data-autoattach="download-failure"') === false)
					{
						continue;
					}
				}
				$image_url = htmlspecialchars_decode(trim($match[1], '\'"'));
				if (!preg_match('@^https?://@i', $image_url) || preg_match($except_domains_regexp, $image_url))
				{
					continue;
				}
				$result[] = array(
					'full_tag' => $match[0],
					'image_url_html' => trim($match[1], '\'"'),
					'image_url' => $image_url,
				);
			}
			return $result;
		}
		else
		{
			return array();
		}
	}
	
	/**
	 * Replace images in HTML content.
	 * 
	 * @param string $content
	 * @param array $images
	 * @param int $module_srl
	 * @param int $target_srl
	 * @return bool
	 */
	protected static function replaceImages(&$content, $images, $module_srl, $target_srl, $member_srl, &$errors)
	{
		// Count the time and the number of successful replacements.
		$start_time = microtime(true);
		$total_limited = false;
		$count = 0;
		$errors = array();
		
		// Ensure that we have enough time.
		$total_timeout = intval(self::$config->total_timeout ? self::$config->total_timeout : self::$total_timeout);
		@set_time_limit($total_timeout + 20);
		
		// Get information about the current module and the author.
		if (self::$config->apply_module_limit === 'Y')
		{
			$logged_info = Context::get('logged_info');
			$member_info = getModel('member')->getMemberInfoByMemberSrl($member_srl);
			$module_config = getModel('file')->getFileConfig($module_srl);
		}
		else
		{
			$logged_info = new stdClass;
			$member_info = new stdClass;
			$module_config = new stdClass;
		}

		// Loop over all images.
		foreach ($images as $image_info)
		{
			// If the same image has already been downloaded, reuse the cached version.
			if (isset(self::$url_cache[$image_info['image_url']]))
			{
				$uploaded_filename = self::$url_cache[$image_info['image_url']];
				$new_tag = str_replace($image_info['image_url_html'], htmlspecialchars($uploaded_filename), $image_info['full_tag']);
				$content = str_replace($image_info['full_tag'], self::addStatusAttribute($new_tag, 'success'), $content);
				$errors[] = 'Reusing Cached Image: ' . $image_info['image_url'] . ' (target: ' . $target_srl . ')';
				continue;
			}
			
			// If the total attachment size limit has already been exceeded, do not try to download more inages.
			if ($total_limited)
			{
				continue;
			}
			
			// Attempt to download the image.
			$temp_path = _XE_PATH_ . 'files/cache/autoattach/' . md5($image_info['image_url'] . microtime() . mt_rand());
			$download_start_time = microtime(true);
			$image_timeout = intval(self::$config->image_timeout ? self::$config->image_timeout : self::$image_timeout);
			$redirect_settings = array('follow_redirects' => true, 'max_redirects' => 2);
			$status = FileHandler::getRemoteFile($image_info['image_url'], $temp_path, null, $image_timeout, 'GET', null, array(), array(), array(), $redirect_settings);
			clearstatcache($temp_path);
			if (!$status || !file_exists($temp_path) || !filesize($temp_path))
			{
				if (microtime(true) - $download_start_time >= $image_timeout)
				{
					$content = str_replace($image_info['full_tag'], self::addStatusAttribute($image_info['full_tag'], 'download-timeout'), $content);
					$errors[] = 'Download Timeout: ' . $image_info['image_url'] . ' (target: ' . $target_srl . ')';
				}
				else
				{
					$content = str_replace($image_info['full_tag'], self::addStatusAttribute($image_info['full_tag'], 'download-failure'), $content);
					$errors[] = 'Download Failure: ' . $image_info['image_url'] . ' (target: ' . $target_srl . ')';
				}
				FileHandler::removeFile($temp_path);
				continue;
			}
			
			// Check the current module's attachment size limit.
			if (self::$config->apply_module_limit === 'Y')
			{
				if ($module_config->allowed_filesize && $member_info->is_admin !== 'Y' && ($_SERVER['REQUEST_METHOD'] === 'GET' || $logged_info->is_admin !== 'Y'))
				{
					if (filesize($temp_path) > $module_config->allowed_filesize * 1024 * 1024)
					{
						$content = str_replace($image_info['full_tag'], self::addStatusAttribute($image_info['full_tag'], 'size-limit-single'), $content);
						$errors[] = 'Single Attachment Size Limit Exceeded: ' . $image_info['image_url'] . ' (target: ' . $target_srl . ')';
						FileHandler::removeFile($temp_path);
						continue;
					}
				}
				if ($module_config->allowed_attach_size && $member_info->is_admin !== 'Y' && ($_SERVER['REQUEST_METHOD'] === 'GET' || $logged_info->is_admin !== 'Y'))
				{
					$total_size = executeQuery('file.getAttachedFileSize', (object)array('upload_target_srl' => $target_srl));
					if($total_size->data->attached_size + filesize($temp_path) > $module_config->allowed_attach_size * 1024 * 1024)
					{
						$content = str_replace($image_info['full_tag'], self::addStatusAttribute($image_info['full_tag'], 'size-limit-total'), $content);
						$errors[] = 'Total Attachment Size Limit Exceeded: ' . $image_info['image_url'] . ' (target: ' . $target_srl . ')';
						FileHandler::removeFile($temp_path);
						$total_limited = true;
						continue;
					}
				}
			}
			
			// Check if the current image is an animated GIF.
			if (self::$config->allow_animated_gif === 'N')
			{
				if (self::isAnimatedGIF($temp_path))
				{
					$content = str_replace($image_info['full_tag'], self::addStatusAttribute($image_info['full_tag'], 'animated-gif'), $content);
					$errors[] = 'Animated GIF not allowed: ' . $image_info['image_url'] . ' (target: ' . $target_srl . ')';
					FileHandler::removeFile($temp_path);
					continue;
				}
			}
			
			// Guess the correct filename and extension.
			$temp_name = self::cleanFilename($image_info['image_url']);
			if (preg_match('/^[0-9a-f]{32}$/', $temp_name))
			{
				$temp_name .= '.' . self::guessExtension($temp_path);
			}
			
			// Register as attachment.
			$oFile = getController('file')->insertFile(array(
				'name' => $temp_name,
				'tmp_name' => $temp_path,
			), $module_srl, $target_srl, 0, true);
			FileHandler::removeFile($temp_path);
			if (!$oFile)
			{
				$content = str_replace($image_info['full_tag'], self::addStatusAttribute($image_info['full_tag'], 'insert-error'), $content);
				$errors[] = 'Insert Error: ' . $image_info['image_url'] . ' (target: ' . $target_srl . ')';
				continue;
			}
			
			// Update the content.
			self::$url_cache[$image_info['image_url']] = $uploaded_filename = $oFile->get('uploaded_filename');
			$new_tag = str_replace($image_info['image_url_html'], htmlspecialchars($uploaded_filename), $image_info['full_tag']);
			$content = str_replace($image_info['full_tag'], self::addStatusAttribute($new_tag, 'success'), $content);
			$count++;
			
			// If this is taking too long, stop now and try again later.
			if (microtime(true) - $start_time > $total_timeout)
			{
				break;
			}
		}
		
		// Update all files to be valid.
		getController('file')->setFilesValid($target_srl);
		
		// Return the count.
		return $count;
	}
	
	/**
	 * Add a status attribute to an image tag.
	 * 
	 * @param string $tag
	 * @param string $status
	 * @return string
	 */
	protected static function addStatusAttribute($tag, $status)
	{
		$status = htmlspecialchars($status, ENT_QUOTES, 'UTF-8');
		$tag = preg_replace('/\sdata-autoattach="[^"]+?"/', '', $tag);
		return preg_replace('/^<img\s+/i', '<img data-autoattach="' . $status . '" ', $tag);
	}
	
	/**
	 * Clean a filename.
	 * 
	 * @param string $filename
	 * @return string
	 */
	protected static function cleanFilename($filename)
	{
		if (preg_match('@[^\\\\/\\?=]+\.(gif|jpe?g|png|bmp|svg)\b@i', urldecode($filename), $matches))
		{
			return $matches[0];
		}
		else
		{
			return md5($image_info['image_url'] . microtime() . mt_rand());
		}
	}
	
	/**
	 * Check the file type and return an appropriate extension.
	 * 
	 * @param string $filename
	 * @param string $default
	 * @return string
	 */
	protected static function guessExtension($filename, $default = 'jpg')
	{
		$image_info = @getimagesize($filename);
		if (!$image_info) return $default;
		
		switch ($image_info['mime'])
		{
			case 'image/gif': return 'gif';
			case 'image/jpeg': return 'jpg';
			case 'image/png': return 'png';
			case 'image/x-ms-bmp': return 'bmp';
			default: return $default;
		}
	}
	
	/**
	 * Check if a file is an animated GIF.
	 * 
	 * @param string $filename
	 * @return bool
	 */
	protected static function isAnimatedGIF($filename)
	{
		$image_info = @getimagesize($filename);
		if (!$image_info || $image_info['mime'] !== 'image/gif')
		{
			return false;
		}
		
		$count = 0;
		if ($fp = @fopen($filename, 'rb'))
		{
			while (!feof($fp) && $count < 2)
			{
				$count += preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', fread($fp, 1024 * 16) ?: '');
				fseek($fp, max(0, ftell($fp) - 16));
			}
			fclose($fp);
		}
		return $count > 1;
	}
}
