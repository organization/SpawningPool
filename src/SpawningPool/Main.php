<?php

namespace SpawningPool;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\Utils;
use SpawningPool\callback\CallbackManager;

class Main extends PluginBase {
	/** @var CallbackManager */
	private $callback;
	public function onLoad() {
		$this->write ();
		// $this->prove();
	}
	public function onEnable() {
		$this->callback = new CallbackManager ( $this->getServer (), $this );
	}
	/**
	 * 콜백매니저 인스턴스를 반환합니다.
	 *
	 * @return \SpawningPool\callback\CallbackManager
	 */
	public function getCallback() {
		return $this->callback;
	}
	private function write() {
		$spawningPool = new SpawningPool ( $this->getServer (), Utils::getCoreCount () );
		$this->setPrivateVariableData ( $this->getServer ()->getScheduler (), "asyncPool", $spawningPool );
		foreach ( $this->getServer ()->getLevels () as $level )
			$level->registerGenerator ();
	}
	private function prove() {
		$prove = new Prove ();
		
		/* It Works */
		$prove->useMultiCore1 ();
		
		/* Not Works */
		// $prove->useMultiCore2();
		// $prove->useSingleCore();
	}
	private function setPrivateVariableData($object, $variableName, $set) {
		$property = (new \ReflectionClass ( $object ))->getProperty ( $variableName );
		$property->setAccessible ( true );
		$property->setValue ( $object, $set );
	}
}

?>