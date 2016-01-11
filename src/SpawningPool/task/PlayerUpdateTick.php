<?php

namespace SpawningPool\task;

use pocketmine\scheduler\Task;
use pocketmine\Server;
use SpawningPool\Main;

class PlayerUpdateTick extends Task {
	public function onRun($currentTick) {
		$plugin = Server::getInstance ()->getPluginManager ()->getPlugin ( 'SpawningPool' );
		if ($plugin instanceof Main)
			$plugin->getCallback ()->moveplayer->checkTickUpdates ();
	}
}

?>