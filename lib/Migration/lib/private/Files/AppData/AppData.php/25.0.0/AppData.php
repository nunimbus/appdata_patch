<?php

declare(strict_types=1);

/**
 * @copyright 2016 Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Robin Appelman <robin@icewind.nl>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OC\Files\AppData;

use OCP\Cache\CappedMemoryCache;
use OC\Files\SimpleFS\SimpleFolder;
use OC\SystemConfig;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Files\Storage\IStorageFactory;

class AppData implements IAppData {
	private IRootFolder $rootFolder;
	private SystemConfig $config;
	private string $appId;
	private ?Folder $folder = null;
	/** @var CappedMemoryCache<ISimpleFolder|NotFoundException> */
	private CappedMemoryCache $folders;
	private $customRoot = false;

	/**
	 * AppData constructor.
	 *
	 * @param IRootFolder $rootFolder
	 * @param SystemConfig $systemConfig
	 * @param string $appId
	 */
	public function __construct(IRootFolder $rootFolder,
								SystemConfig $systemConfig,
								string $appId) {
		$this->rootFolder = $rootFolder;
		$this->config = $systemConfig;
		$this->appId = $appId;
		$this->folders = new CappedMemoryCache();

		if (in_array('appdataroot',\OC::$server->getSystemConfig()->getKeys())) {	
			// This is the actual filesystem location where the `appdata_` folder will be stored	
			$arguments = [
				'datadir' => \OC::$server->getSystemConfig()->getValue('appdataroot'),
			];

			// Create a custom mount point to be used for the appdata folder root. This mount point is relative to the
			// internal NC root folder `/`
			$storage = new \OC\Files\Storage\LocalRootStorage($arguments);
			$loader = \OC::$server->query(IStorageFactory::class);
			$mount = new \OC\Files\Mount\MountPoint($storage, "/appdataroot/", $arguments, $loader);
			\OC::$server->getMountManager()->addMount($mount);

			// It would be nice to do `$this->rootFolder = $rootFolder->get('/appdataroot/')`, but this this would lead
			// to a recursive segfault - this class' constructor is called as part of constructing the `IRootFolder`
			// itself. Thus, a boolean is set, and the root folder is loaded appropriately in each function below.
			$this->customRoot = true;
		}
	}

	private function getAppDataFolderName() {
		$instanceId = $this->config->getValue('instanceid', null);
		if ($instanceId === null) {
			throw new \RuntimeException('no instance id!');
		}

		return 'appdata_' . $instanceId;
	}

	protected function getAppDataRootFolder(): Folder {
		$rootFolder = $this->rootFolder;
		
		if ($this->customRoot) {
			foreach ($this->rootFolder->getDirectoryListing('/') as $dir) {
				if ($dir->getName() == 'appdataroot') {
					$rootFolder = $dir;
					break;
				}
			}
		}

		$name = $this->getAppDataFolderName();

		try {
			/** @var Folder $node */
			$node = $rootFolder->get($name);
			return $node;
		} catch (NotFoundException $e) {
			try {
				return $rootFolder->newFolder($name);
			} catch (NotPermittedException $e) {
				throw new \RuntimeException('Could not get appdata folder');
			}
		}
	}

	/**
	 * @return Folder
	 * @throws \RuntimeException
	 */
	private function getAppDataFolder(): Folder {
		$rootFolder = $this->rootFolder;
		
		if ($this->customRoot) {
			foreach ($this->rootFolder->getDirectoryListing('/') as $dir) {
				if ($dir->getName() == 'appdataroot') {
					$rootFolder = $dir;
					break;
				}
			}
		}

		if ($this->folder === null) {
			$name = $this->getAppDataFolderName();

			try {
				$this->folder = $rootFolder->get($name . '/' . $this->appId);
			} catch (NotFoundException $e) {
				$appDataRootFolder = $this->getAppDataRootFolder();

				try {
					$this->folder = $appDataRootFolder->get($this->appId);
				} catch (NotFoundException $e) {
					try {
						$this->folder = $appDataRootFolder->newFolder($this->appId);
					} catch (NotPermittedException $e) {
						throw new \RuntimeException('Could not get appdata folder for ' . $this->appId);
					}
				}
			}
		}

		return $this->folder;
	}

	public function getFolder(string $name): ISimpleFolder {
		$rootFolder = $this->rootFolder;
		
		if ($this->customRoot) {
			foreach ($this->rootFolder->getDirectoryListing('/') as $dir) {
				if ($dir->getName() == 'appdataroot') {
					$rootFolder = $dir;
					break;
				}
			}
		}

		$key = $this->appId . '/' . $name;
		if ($cachedFolder = $this->folders->get($key)) {
			if ($cachedFolder instanceof \Exception) {
				throw $cachedFolder;
			} else {
				return $cachedFolder;
			}
		}
		try {
			// Hardening if somebody wants to retrieve '/'
			if ($name === '/') {
				$node = $this->getAppDataFolder();
			} else {
				$path = $this->getAppDataFolderName() . '/' . $this->appId . '/' . $name;
				$node = $rootFolder->get($path);
			}
		} catch (NotFoundException $e) {
			$this->folders->set($key, $e);
			throw $e;
		}

		/** @var Folder $node */
		$folder = new SimpleFolder($node);
		$this->folders->set($key, $folder);
		return $folder;
	}

	public function newFolder(string $name): ISimpleFolder {
		$key = $this->appId . '/' . $name;
		$folder = $this->getAppDataFolder()->newFolder($name);

		$simpleFolder = new SimpleFolder($folder);
		$this->folders->set($key, $simpleFolder);
		return $simpleFolder;
	}

	public function getDirectoryListing(): array {
		$listing = $this->getAppDataFolder()->getDirectoryListing();

		$fileListing = array_map(function (Node $folder) {
			if ($folder instanceof Folder) {
				return new SimpleFolder($folder);
			}
			return null;
		}, $listing);

		$fileListing = array_filter($fileListing);

		return array_values($fileListing);
	}

	public function getId(): int {
		return $this->getAppDataFolder()->getId();
	}
}