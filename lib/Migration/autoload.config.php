<?php

$autoloadClasslist = array();

$it = new \RecursiveIteratorIterator(
	new \RecursiveDirectoryIterator(OC::$configDir . 'overrides', \FilesystemIterator::SKIP_DOTS),
	\RecursiveIteratorIterator::CHILD_FIRST);

foreach ($it as $file) {
	$filePath = $file->getRealPath();

	if ($file->isFile() && $file->getExtension() == 'php') {
		$class = getFQCN($filePath);
		$autoloadClasslist[$class] = $filePath;
	}
}

foreach ($autoloadClasslist as $class=>$file) {
	$classExists = false;

	foreach (OC::$composerAutoloader->getRegisteredLoaders() as $path=>$loader) {
		$classMap = $loader->getClassMap();

		if (isset($classMap[$class]) || array_key_exists($class, $classMap)) {
			$classMap[$class] = $file;
			$loader->addClassMap($classMap);
			$classExists = true;
			break;
		}
	}

	if (! $classExists) {
		$classMap[$class] = $file;
		$loader->addClassMap($classMap);
	}
}

function getFQCN($file){
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