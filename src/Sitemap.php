<?php
/**
 * indexed
 *
 * Copyright (c) 2013-2014 David Persson. All rights reserved.
 *
 * Use of this source code is governed by a BSD-style
 * license that can be found in the LICENSE file.
 */

namespace indexed;

use Exception;
use DomDocument;

class Sitemap extends Site {

	/**
	 * Maximum number of images per page.
	 */
	const MAX_IMAGES_PER_PAGE = 1000;

	protected static $_namespaces = [
		'core' => [
			'prefix' => null,
			'version' => '0.9',
			'uri' => 'http://www.sitemaps.org/schemas/sitemap/{:version}',
			'schema' => 'http://www.sitemaps.org/schemas/sitemap/{:version}/sitemap.xsd'
		],
		'image' => [
			'prefix' => 'image',
			'version' => '1.1',
			'uri' => 'http://www.google.com/schemas/sitemap-{:prefix}/{:version}',
			'schema' => 'http://www.google.com/schemas/sitemap-{:prefix}/{:version}/sitemap-{:prefix}.xsd'
		]
	];

	/**
	 * Adds a page to the sitemap.
	 *
	 * @param string $url An absolute or fully qualified URL for the item to be added.
	 * @param array $options Additional options for the item:
	 *                       - modified
	 *                       - changes
	 *                         How often does the item change? Possible values
	 *                         are 'always', 'hourly', 'daily', 'weekly', 'monthly',
	 *                        'yearly', 'never'.
	 *                       - priority
	 *                         Possible values are 0.0 - 1.0 (most important).
	 *                         0.5 is considered the default.
	 *                       - title
	 *                         Used as a comment.
	 */
	public function page($url, $options = []) {
		$defaults = [
			'modified' => null,
			'changes' => null,
			'priority' => null,
			'title' => null,
			'images' => []
		];
		if (strpos($url, '://') === false) {
			$url = $this->_base . $url;
		}
		if (isset($this->_data[$url])) {
			throw new Exception("Will not overwrite page with URL `{$url}`; already added.");
		}
		$this->_data[$url] = compact('url') + $options + $defaults;
	}

	/**
	 * Adds an image to the sitemap.
	 *
	 * @link http://www.google.com/support/webmasters/bin/answer.py?answer=178636
	 * @param string $url An absolute or fully qualified URL for the image.
	 * @param string $page An absolute or fully qualified URL for the page which contains the image.
	 * $param array $options Available options are:
	 *                       - title
	 *                         The title of the image.
	 *                       - license
	 *                         A fully qualified URL to the license of the image.
	 *                       - caption
	 *                         The caption of the image.
	 *                       - location
	 *                         The geographic location of the image (i.e. Limerick, Ireland).
	 */
	public function image($url, $page, array $options = []) {
		if (strpos($url, '://') === false) {
			$url = $this->_base . $url;
		}
		if (strpos($page, '://') === false) {
			$page = $this->_base . $page;
		}
		$defaults = [
			'title' => null,
			'license' => null,
			'caption' => null,
			'location' => null
		];
		if (isset($this->_data[$page]['images'][$url])) {
			throw new Exception("Will not overwrite image with URL `{$url}`; already added.");
		}
		if (!isset($this->_data[$page])) {
			throw new Exception("No page with URL `{$url}` found to add image to.");
		}
		$this->_data[$page]['images'][$url] = compact('url') + $options + $defaults;
	}

	public function generate() {
		if (count($this->_data) > static::MAX_ITEMS) {
			throw new Exception('Too many items.');
		}
		$args = func_get_args();

		if (!$args || $args[0] == 'xml') {
			$result = $this->_generate();
		} elseif ($args[0] == 'indexXml') {
			$message  = 'Support for `indexXml` format has been deprecated.';
			$message .= ' Please use the dedicated Siteindex class instead.';
			trigger_error($message, E_USER_DEPRECATED);

			$index = new Siteindex($this->_base);
			$index->debug = $this->debug;

			foreach ($this->_data as $item) {
				$index->sitemap($item['url']);
			}
			return $index->generate();
		} elseif ($args[0] == 'txt') {
			$message = 'Format `txt` has been deprecated and will be removed soon.';
			trigger_error($message, E_USER_DEPRECATED);

			$result = $this->_generateTxt();
		} else {
			throw new Exception('Invalid format given.');
		}

		if (strlen($result) > static::MAX_SIZE) {
			throw new Exception('Result document exceeds allowed size.');
		}
		return $result;
	}

	protected function _generate() {
		$document = new DomDocument('1.0', 'UTF-8');

		$namespaces = static::$_namespaces;
		$extensions = $this->_uses($this->_data);

		$set = $document->createElementNs($namespaces['core']['uri'], 'urlset');
		$schemaLocation = "{$namespaces['core']['uri']} {$namespaces['core']['schema']}";

		foreach ($extensions as $ext) {
			$set->setAttribute("xmlns:{$namespaces[$ext]['prefix']}", $namespaces[$ext]['uri']);
			$schemaLocation .= " {$namespaces[$ext]['uri']} {$namespaces[$ext]['schema']}";
		}

		$set->setAttributeNs(
			'http://www.w3.org/2001/XMLSchema-instance',
			'xsi:schemaLocation',
			$schemaLocation
		);

		foreach ($this->_data as $item) {
			$page = $document->createElement('url');

			if ($item['title']) {
				$page->appendChild($document->createComment($item['title']));
			}
			$page->appendChild($this->_safeLocElement($item['url'], $document));

			if ($item['modified']) {
				$page->appendChild($document->createElement('lastmod', date('c', strtotime($item['modified']))));
			}
			if ($item['changes']) {
				$page->appendChild($document->createElement('changefreq', $item['changes']));
			}
			if ($item['priority']) {
				$page->appendChild($document->createElement('priority', $item['priority']));
			}
			if ($item['images']) {
				if (count($item['images']) > static::MAX_IMAGES_PER_PAGE) {
					throw new Exception('Too many images for page');
				}

				foreach ($item['images'] as $imageItem) {
					$image = $document->createElement('image:image');

					$image->appendChild($this->_safeLocElement($imageItem['url'], $document, 'image'));

					if ($imageItem['caption']) {
						$image->appendChild($document->createElement('image:caption', $imageItem['caption']));
					}
					if ($imageItem['location']) {
						$image->appendChild($document->createElement('image:geo_location', $imageItem['location']));
					}
					if ($imageItem['title']) {
						$image->appendChild($document->createElement('image:title', $imageItem['title']));
					}
					if ($imageItem['license']) {
						$image->appendChild($document->createElement('image:license', $imageItem['license']));
					}

					$page->appendChild($image);
				}
			}
			$set->appendChild($page);
		}
		$document->appendChild($set);

		$document->formatOutput = $this->debug;
		return $document->saveXml();
	}

	// @link http://www.sitemaps.org/protocol.php#otherformats
	// @deprecated
	protected function _generateTxt() {
		$result = null;

		foreach ($this->_data as $item) {
			$result .= $item['url'] . "\n";
		}
		return $result;
	}
}

?>