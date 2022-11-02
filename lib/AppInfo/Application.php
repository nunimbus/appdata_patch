<?php
/**
 * @copyright Copyright (c) 2021, Andrew Summers
 *
 * @author Andrew Summers
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\AppDataPatch\AppInfo;

use OCA\ClassOverrides\InstallFunctions;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {

	public const APP_ID = 'appdata_patch';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		if (! class_exists('OCA\\ClassOverrides\\InstallFunctions')) {
			$classMap = \OC::$composerAutoloader->getClassMap();
			$classMap['OCA\\ClassOverrides\\InstallFunctions'] = \OC::$server->getAppManager()->getAppPath(self::APP_ID) . '/lib/InstallFunctions.php';
			\OC::$composerAutoloader->addClassMap($classMap);
		}
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
		$server = $context->getServerContainer();

		if (
			$server->getRequest()->getRequestUri() == '/index.php/settings/apps/disable' &&
			in_array(self::APP_ID, $server->getRequest()->getParams()['appIds'])
		) {
			InstallFunctions::uninstall();
		}
	}
}