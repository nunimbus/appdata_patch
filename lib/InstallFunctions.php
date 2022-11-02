<?php

namespace OCA\ClassOverrides;

use OC;

class InstallFunctions {
	static function install() {
		$depVersions = ['OC' => OC::$server->getConfig()->getSystemValue('version')];
		$appId = OC::$server->getRequest()->getParams()['appIds'][0];
		$appInfo = OC::$server->getAppManager()->getAppInfo($appId);

		// Check to see if the app has any dependencies listed in its info.xml
		if (
			(isset($appInfo['dependencies']) || array_key_exists('dependencies', $appInfo)) &&
			(isset($appInfo['dependencies']['apps']) || array_key_exists('apps', $appInfo['dependencies'])) &&
			(isset($appInfo['dependencies']['apps']['app']) || array_key_exists('app', $appInfo['dependencies']['apps']))
		) {
			self::installDependencies((array) $appInfo['dependencies']['apps']['app']);

			foreach ((array) $appInfo['dependencies']['apps']['app'] as $app) {
				$depVersions[$app] = OC::$server->getAppManager()->getAppVersion($app);
			}
		}

		// Copy over the autoload config file and all override files to the config/overrides directory
		$overrideFiles = array();
		$autoload = "";
		$migrationDir = OC::$server->getAppManager()->getAppPath($appId) . '/lib/Migration';

		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($migrationDir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST);

		foreach ($it as $file) {
			// Based on the directory structure, find all of the *.php files and app/platform version info of the files available
			if ($file->isDir() && substr($file->getPath(), -4) == '.php') {
				if (isset($overrideFiles[$file->getPath()]) || array_key_exists($file->getPath(), $overrideFiles)) {
					array_push($overrideFiles[$file->getPath()], $file->getFilename());
				}
				else {
					$overrideFiles[$file->getPath()] = array($file->getFilename());
				}
			}
			else if ($file->isFile() && $file->getFilename() == 'autoload.config.php') {
				$autoload = $file->getRealPath();
			} 
		}

		foreach ($overrideFiles as $file=>$fileVersions) {
			$srcRoot = substr($file, strlen($migrationDir));
			$srcFile = OC::$SERVERROOT . $srcRoot;
			$destFile = OC::$configDir . 'overrides' . $srcRoot;

			$dest = explode('/', $destFile);
			$fileName = array_pop($dest);
			$dest = implode('/', $dest);

			$patchFile = explode('.', $fileName);
			array_pop($patchFile);
			$patchFile = implode('.', $patchFile) . '.patch';

			$depVersion = $depVersions['OC'];
			$version = null;
			usort($fileVersions, 'version_compare');
			$fileVersions = array_reverse($fileVersions);

			if (substr($srcRoot, 0, 5) == '/apps') {
				$app = explode('/', $srcRoot)[2];
				$depVersion = $depVersions[$app];
			}

			foreach ($fileVersions as $fileVersion) {
				if (version_compare($depVersion, $fileVersion) === 0) {
					$version = $fileVersion;
					break;
				}
				else if (version_compare($depVersion, $fileVersion) === 1) {
					if (is_null($version)) {
						$version = $fileVersion;
					}
					break;
				}
			}

			if (is_null($version)) {
				OC::$server->getLogger()->error("No file available to override version $depVersion of $srcFile");
			}

			@mkdir($dest, 0755, true);
			$success = false;

			if (file_exists($patchFile) && function_exists('xdiff_file_patch')) {
				// sudo apt-get install php-dev
				// sudo pecl install xdiff
				// sudo echo "extension=xdiff.so" > /etc/php/8.1/mods-available/xdiff.ini
				$success = xdiff_file_patch(
					$srcFile,
					"$file/$version/$patchFile",
					$destFile
				);

				if (! $success) {
					OC::$server->getLogger()->warning("Failed to patch $srcFile with $file/$version/$patchFile");
				}
			}
			
			if ($success !== true || ! function_exists('xdiff_file_patch')) {
				copy("$file/$version/$fileName", $destFile);
			}
		}

		copy($autoload, OC::$configDir . 'autoload.config.php');
	}

	static function uninstall() {
		$appId = OC::$server->getRequest()->getParams()['appIds'][0];

		// Clean up the autoload config file and all files copied to the config/overrides directory
		$deleteFiles = array();
		$migrationDir = OC::$server->getAppManager()->getAppPath($appId) . '/lib/Migration';

		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($migrationDir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST);

		foreach ($it as $file) {
			if ($file->isDir() && $file->getExtension() == 'php') {
				array_push($deleteFiles, OC::$configDir . 'overrides' . substr($file->getRealPath(), strlen($migrationDir)));
			}
		}

		foreach ($deleteFiles as $file) {
			if (file_exists($file)) {
				$class = self::getFQCN($file);

				foreach (OC::$composerAutoloader->getRegisteredLoaders() as $path=>$loader) {
					$classMap = $loader->getClassMap();
			
					if (isset($classMap[$class]) || array_key_exists($class, $classMap)) {
						$classMap[$class] = OC::$SERVERROOT . substr($file, strlen(OC::$configDir . 'overrides'));
						$loader->addClassMap($classMap);
					}
				}
			
				unlink($file);
			}
		}

		// Remove all empty directories from config/overrides
		$it = new \RecursiveIteratorIterator(
			$parentIt = new \RecursiveDirectoryIterator(OC::$configDir . 'overrides', \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST);

		foreach ($it as $file) {
			if ($file->isDir()) {
				$di = new \RecursiveDirectoryIterator($file->getRealPath(), \FilesystemIterator::SKIP_DOTS);

				if (iterator_count($di) === 0) {
					rmdir((string)$file);
				}
			}
		}

		// If all directories are empty, remove the 'overrides' folder and the 'autoload.config.php' file
		if (iterator_count($parentIt) === 0) {
			rmdir((string)$parentIt->getPath());
			unlink(OC::$configDir . 'autoload.config.php');
		}

		// Remove all migrations created by the app
		$qb = OC::$server->get('OC\DB\QueryBuilder\QueryBuilder');
		$qb->delete('migrations')->where($qb->expr()->eq('app', $qb->createNamedParameter($appId)));
		$qb->execute();
	}

	static function installDependencies($dependencies) {
		$installer = OC::$server->get('OC\Installer');

		foreach ($dependencies as $appId) {
			if (! $installer->isDownloaded($appId)) {
				$installer->downloadApp($appId);
			}

			if (! OC::$server->getAppManager()->isInstalled($appId)) {
				$installer->installApp($appId);
				OC::$server->getAppManager()->enableApp($appId);
				\OC_App::loadApp($appId);
			}

			// Highly unlikely that an app could be installed but not enabled, but it's possible
			if (! OC::$server->getAppManager()->isEnabledForUser($appId)) {
				OC::$server->getAppManager()->enableApp($appId);
				\OC_App::loadApp($appId);
			}
		}
	}

	static function uninstallConflicts($conflicts) {
		foreach ($conflicts as $appId) {
			OC::$server->getAppManager()->disableApp($appId);
		}
	}

	static function getFQCN($file){
		$fp = fopen($file, 'r');
		$class = $namespace = $buffer = '';
	
		while (!$class) {
			if (feof($fp)) {
				break;
			}
	
			$buffer .= fread($fp, 512);
			ob_start();
			$tokens = token_get_all($buffer);
			$err = ob_get_clean();
	
			if (strpos($buffer, '{') === false) {
				continue;
			}
	
			$break = false;
	
			for ($i = 0; $i < count($tokens); $i++) {
				if ($tokens[$i][0] === T_NAMESPACE) {
					for ($j = $i + 1; $j < count($tokens); $j++) {
						if ($tokens[$j][0] === T_STRING || $tokens[$j][0] === T_NAME_QUALIFIED) {
							$namespace .= '\\' . $tokens[$j][1];
						}
						else if ($tokens[$j] === '{' || $tokens[$j] === ';') {
							break;
						}
					}
				}
	
				if ($tokens[$i][0] === T_CLASS || $tokens[$i][0] === T_INTERFACE) {
					for ($j = $i + 1; $j < count($tokens); $j++) {
						if ($tokens[$j] === '{') {
							$class = $tokens[$i + 2][1];
							$break = true;
							break;
						}
					}
					if ($break) {
						break;
					}
				}
			}
		}
	
		fclose($fp);
	
		if ($class=='') {
			return false;
		}
	
		return trim("$namespace\\$class", '\\');
	}
}