<?php declare(strict_types = 0);
/*
** MIB Browser module for Zabbix 8.0
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
*/

namespace Modules\MibBrowser;

use Modules\MibBrowser\Lib\I18n;

use APP,
	CMenuItem,
	CWebUser,
	Zabbix\Core\CModule;

class Module extends CModule {

	public function init(): void {
		if (!CWebUser::isLoggedIn() || CWebUser::getType() < USER_TYPE_ZABBIX_ADMIN) {
			return;
		}

		$menu = APP::Component()->get('menu.main');
		$data_collection = $menu->find(_('Data collection'));

		$item = (new CMenuItem(I18n::t('MIB browser')))->setAction('mibbrowser.view');

		if ($data_collection !== null && $data_collection->hasSubMenu()) {
			$submenu = $data_collection->getSubMenu();

			if ($submenu->find(_('Templates')) !== null) {
				$submenu->insertAfter(_('Templates'), $item);
			}
			else {
				$submenu->add($item);
			}
		}
		else {
			$menu->findOrAdd(I18n::t('Tools'))
				->setIcon(ZBX_ICON_COG_FILLED)
				->getSubMenu()
				->add($item);
		}
	}
}
