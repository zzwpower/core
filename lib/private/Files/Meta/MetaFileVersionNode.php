<?php
/**
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


namespace OC\Files\Meta;


use OC\Files\Node\AbstractFile;
use OC\Files\Node\File;
use OCP\Files\IPreviewNode;
use OCP\Files\Storage\IVersionedStorage;
use OCP\Files\Storage;
use OCP\IImage;

/**
 * Class MetaFileVersionNode - this class represents a version of a file in the
 * meta endpoint
 *
 * @package OC\Files\Meta
 */
class MetaFileVersionNode extends AbstractFile implements IPreviewNode {

	/** @var string */
	private $versionId;
	/** @var MetaVersionCollection */
	private $parent;
	/** @var IVersionedStorage */
	private $storage;
	/** @var string */
	private $internalPath;

	/**
	 * MetaFileVersionNode constructor.
	 *
	 * @param MetaVersionCollection $parent
	 * @param string $versionId
	 * @param Storage $storage
	 * @param string $internalPath
	 */
	public function __construct(MetaVersionCollection $parent,
								$versionId, Storage $storage, $internalPath) {
		$this->parent = $parent;
		$this->versionId = $versionId;
		$this->storage = $storage;
		$this->internalPath = $internalPath;
	}

	public function getName() {
		return $this->versionId;
	}

	public function getContent() {
		return $this->storage->getContentOfVersion($this->internalPath, $this->versionId);
	}

	public function fopen($mode) {
		return $this->storage->getContentOfVersionAsStream($this->internalPath, $this->versionId);
	}

	public function copy($targetPath) {
		//TODO: inject
		$target = \OC::$server->getRootFolder()->get($targetPath);
		if ($target instanceof File && $target->getId() === $this->parent->getId()) {
			$this->storage->restoreVersion($this->internalPath, $this->versionId);
			return true;
		}

		// for now we only allow restoring of a version
		return false;
	}

	public function getId() {
		return $this->parent->getId();
	}

	public function getMimetype() {
		return \OC::$server->getRootFolder()->getById($this->getId())[0]->getMimetype();
	}

	public function getPath() {
		return $this->parent->getPath() . '/' . $this->getName();
	}

	/**
	 * @param array $options
	 * @return IImage
	 * @since 10.1.0
	 */
	public function getThumbnail($options) {
		$maxX = array_key_exists('x', $options) ? (int)$_GET['x'] : 32;
		$maxY = array_key_exists('y', $_GET) ? (int)$_GET['y'] : 32;
		$scalingUp = array_key_exists('scalingup', $_GET) ? (bool)$_GET['scalingup'] : true;
		$keepAspect = array_key_exists('a', $_GET) ? true : false;
		$mode = array_key_exists('mode', $_GET) ? $_GET['mode'] : 'fill';

		$preview = new \OC\Preview();
		$preview->setFile($this, $this->versionId);
		$preview->setMaxX($maxX);
		$preview->setMaxY($maxY);
		$preview->setScalingUp($scalingUp);
		$preview->setMode($mode);
		$preview->setKeepAspect($keepAspect);
		return $preview->getPreview();
	}
}
