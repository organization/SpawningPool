<?php

namespace SpawningPool\task;

use pocketmine\scheduler\AsyncTask;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\math\AxisAlignedBB;

class PlayerMovementTask2 extends AsyncTask {
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
	private $inner = false;
	private $movX;
	private $movY;
	private $movZ;
	private $cx;
	private $cy;
	private $cz;
	public function __construct(Player $player, $dx, $dy, $dz, $collides) {
		$this->name = $player->getName ();
		$this->dx = $dx;
		$this->dy = $dy;
		$this->dz = $dz;
		
		$this->movX = $dx;
		$this->movY = $dy;
		$this->movZ = $dz;
		
		$this->boundingBox = serialize ( $player->boundingBox );
		$this->list = serialize ( $collides );
		
		$this->onGround = $player->onGround;
		$this->stepHeight = $this->getPrivateVariableData ( $player, 'stepHeight' );
		$this->ySize = $this->getPrivateVariableData ( $player, 'ySize' );
	}
	public function onRun() {
		$boundingBox = unserialize ( $this->boundingBox );
		$list = unserialize ( $this->list );
		
		if (! $boundingBox instanceof AxisAlignedBB)
			return;
		
		$dx = $this->dx;
		$dy = $this->dy;
		$dz = $this->dz;
		
		$movX = $dx;
		$movY = $dy;
		$movZ = $dz;
		
		foreach ( $list as $bb )
			$dy = $bb->calculateYOffset ( $boundingBox, $dy );
		
		$boundingBox->offset ( 0, $dy, 0 );
		$fallingFlag = ($this->onGround or ($dy != $movY and $movY < 0));
		
		foreach ( $list as $bb )
			$dx = $bb->calculateXOffset ( $boundingBox, $dx );
		
		$boundingBox->offset ( $dx, 0, 0 );
		
		foreach ( $list as $bb )
			$dz = $bb->calculateZOffset ( $boundingBox, $dz );
		
		$boundingBox->offset ( 0, 0, $dz );
		
		if ($this->stepHeight > 0 and $fallingFlag and $this->ySize < 0.05 and ($movX != $dx or $movZ != $dz)) {
			$this->cx = $dx;
			$this->cy = $dy;
			$this->cz = $dz;
			
			$dx = $movX;
			$dy = $this->stepHeight;
			$dz = $movZ;
			
			$axisalignedbb1 = clone $boundingBox;
			$boundingBox->setBB ( unserialize ( $this->boundingBox ) );
			$this->inner = true;
		}
		
		$this->dx = $dx;
		$this->dy = $dy;
		$this->dz = $dz;
		
		$this->boundingBox = serialize ( $boundingBox );
	}
	public function onCompletion(Server $server) {
		$boundingBox = unserialize ( $this->boundingBox );
		
		if (! $boundingBox instanceof AxisAlignedBB)
			return;
		
		$player = $server->getPlayer ( $this->name );
		
		if (! $player instanceof Player)
			return;
		
		$player->boundingBox = $boundingBox;
		
		$server->getScheduler ()->scheduleAsyncTask ( new PlayerMovementTask3 ( $player, $this->dx, $this->dy, $this->dz, $this->inner, $this->movX, $this->movY, $this->movZ, $this->cx, $this->cy, $this->cz) );
	}
	public function getPrivateVariableData($object, $variableName) {
		$reflectionClass = new \ReflectionClass ( $object );
		$property = $reflectionClass->getProperty ( $variableName );
		$property->setAccessible ( true );
		return $property->getValue ( $object );
	}
	public function setPrivateVariableData($object, $variableName, $set) {
		$reflectionClass = new \ReflectionClass ( $object );
		$property = $reflectionClass->getProperty ( $variableName );
		$property->setAccessible ( true );
		$property->setValue ( $object, $set );
	}
}

?>