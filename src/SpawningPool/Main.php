<?php

namespace SpawningPool;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\Utils;

class Main extends PluginBase {
	public function onLoad(){
		$this->write ();
		// $this->prove();
	}
	public function write() {
		$spawningPool = new SpawningPool ( $this->getServer (), Utils::getCoreCount () );
		$this->setPrivateVariableData ( $this->getServer ()->getScheduler (), "asyncPool", $spawningPool );
		foreach ($this->getServer()->getLevels() as $level)
			$level->registerGenerator();
	}
	public function prove() {
		$prove = new Prove ();
		
		/* It Works */
		$prove->useMultiCore1 ();
		
		/* Not Works */
		// $prove->useMultiCore2();
		// $prove->useSingleCore();
	}
	public function setPrivateVariableData($object, $variableName, $set) {
		$property = (new \ReflectionClass ( $object ))->getProperty ( $variableName );
		$property->setAccessible ( true );
		$property->setValue ( $object, $set );
	}
}

?>