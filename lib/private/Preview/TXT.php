<?php
/**
 * @author Georg Ehrke <georg@owncloud.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Nmz <nemesiz@nmz.lt>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OC\Preview;

use OCP\Files\File;
use OCP\Preview\IProvider2;

class TXT implements IProvider2 {
	/**
	 * {@inheritDoc}
	 */
	public function getMimeType() {
		return '/text\/plain/';
	}

	/**
	 * {@inheritDoc}
	 */
	public function getThumbnail(File $file, $maxX, $maxY, $scalingUp) {
		$content = $file->fopen('r');
		$content = stream_get_contents($content,3000);

		//don't create previews of empty text files
		if(trim($content) === '') {
			return false;
		}

		$lines = preg_split("/\r\n|\n|\r/", $content);

		$fontSize = ($maxX) ? (int) ((5 / 32) * $maxX) : 5; //5px
		$lineSize = ceil($fontSize * 1.25);

		$image = imagecreate($maxX, $maxY);
		imagecolorallocate($image, 255, 255, 255);
		$textColor = imagecolorallocate($image, 0, 0, 0);

		$fontFile  = __DIR__;
		$fontFile .= '/../../../core';
		$fontFile .= '/fonts/OpenSans-Regular.ttf';

		$canUseTTF = function_exists('imagettftext');

		foreach($lines as $index => $line) {
			$index = $index + 1;

			$x = (int) 1;
			$y = (int) ($index * $lineSize);

			if ($canUseTTF === true) {
				imagettftext($image, $fontSize, 0, $x, $y, $textColor, $fontFile, $line);
			} else {
				$y -= $fontSize;
				imagestring($image, 1, $x, $y, $line, $textColor);
			}

			if(($index * $lineSize) >= $maxY) {
				break;
			}
		}

		$image = new \OC_Image($image);

		return $image->valid() ? $image : false;
	}

	/**
	 * Check if a preview can be generated for $path
	 *
	 * @param File $file
	 * @return bool
	 * @since 10.1.0
	 */
	public function isAvailable(File $file) {
		return $file->getSize() > 0;
	}
}
