<?php

namespace SpawningPool\task;

use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\Player;
use pocketmine\math\AxisAlignedBB;
use SpawningPool\Main;

class PlayerMovementTask4 extends AsyncTask {
	private $name;
	private $levelTick;
	private $dx;
	private $dy;
	private $dz;
	private $list;
	/** @var AxisAlignedBB */
	private $boundingBox;
	private $onGround;
	private $stepHeight;
	private $ySize;
	private $inner;
	private $movX, $movY, $movZ;
	private $cx, $cy, $cz;
	private $callbackIndex;
	public function __construct(Player $player, $dx, $dy, $dz, $collides, $inner, $movX, $movY, $movZ, $cx, $cy, $cz, $callbackIndex) {
		$this->name = $player->getName ();
		
		$this->dx = $dx;
		$this->dy = $dy;
		$this->dz = $dz;
		
		$this->movX = $movX;
		$this->movY = $movY;
		$this->movZ = $movZ;
		
		$this->cx = $cx;
		$this->cy = $cy;
		$this->cz = $cz;
		
		$this->boundingBox = serialize ( $player->boundingBox );
		$this->list = serialize ( $collides );
		
		$this->onGround = $player->onGround;
		$this->stepHeight = $this->getPrivateVariableData ( $player, 'stepHeight' );
		$this->ySize = $this->getPrivateVariableData ( $player, 'ySize' );
		
		$this->inner = $inner;
		$this->callbackIndex = $callbackIndex;
	}
	public function onRun() {
		$boundingBox = unserialize ( $this->boundingBox );
		$list = unserialize ( $this->list );
		
		if (! $boundingBox instanceof AxisAlignedBB)
			return;
		
		if ($this->inner) {
			$dx = $this->dx;
			$dy = $this->dy;
			$dz = $this->dz;
			
			foreach ( $list as $bb )
				$dy = $bb->calculateYOffset ( $boundingBox, $dy );
			
			$boundingBox->offset ( 0, $dy, 0 );
			
			foreach ( $list as $bb )
				$dx = $bb->calculateXOffset ( $boundingBox, $dx );
			
			$boundingBox->offset ( $dx, 0, 0 );
			
			foreach ( $list as $bb )
				$dz = $bb->calculateZOffset ( $boundingBox, $dz );
			
			$boundingBox->offset ( 0, 0, $dz );
			
			if (($this->cx ** 2 + $this->cz ** 2) >= ($dx ** 2 + $dz ** 2)) {
				$dx = $this->cx;
				$dy = $this->cy;
				$dz = $this->cz;
				$boundingBox->setBB ( $axisalignedbb1 );
			} else {
				$this->ySize += 0.5;
			}
			
			$this->dx = $dx;
			$this->dy = $dy;
			$this->dz = $dz;
			
			$this->boundingBox = serialize ( $boundingBox );
		}
	}
	public function onCompletion(Server $server) {
		$plugin = $server->getPluginManager ()->getPlugin ( 'SpawningPool' );
		if ($plugin instanceof Main)
			$plugin->getCallback ()->moveplayer->playerMoveCallback4 ( $this->name, $this->ySize, $this->onGround, $this->movX, $this->movY, $this->movZ, $this->dx, $this->dy, $this->dz, $this->callbackIndex );
	}
	public function getPrivateVariableData($object, $variableName) {
		$reflectionClass = new \ReflectionClass ( $object );
		$property = $reflectionClass->getProperty ( $variableName );
		$property->setAccessible ( true );
		return $property->getValue ( $object );
	}
}

?>