<?php

namespace SpawningPool\task;

use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\Player;
use pocketmine\math\AxisAlignedBB;

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
	public function __construct(Player $player, $dx, $dy, $dz, $collides, $inner, $movX, $movY, $movZ, $cx, $cy, $cz) {
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
		$player = $server->getPlayer ( $this->name );
		
		if (! $player instanceof Player)
			return;
		
		$this->setPrivateVariableData ( $player, 'ySize', $this->ySize );
		
		$this->checkChunks ( $player );
		$this->checkGroundState ( $player, $this->movX, $this->movY, $this->movZ, $this->dx, $this->dy, $this->dz );
		$this->updateFallState ( $player, $this->dy, $this->onGround );
		
		if ($this->movX != $this->dx)
			$player->motionX = 0;
		
		if ($this->movY != $this->dy)
			$player->motionY = 0;
		
		if ($this->movZ != $this->dz)
			$player->motionZ = 0;
	}
	private function checkChunks(Player $player) {
		if ($player->chunk === null or ($player->chunk->getX () !== ($player->x >> 4) or $player->chunk->getZ () !== ($player->z >> 4))) {
			if ($player->chunk !== null) {
				$player->chunk->removeEntity ( $player );
			}
			$player->chunk = $player->level->getChunk ( $player->x >> 4, $player->z >> 4, true );
			
			if (! $this->getPrivateVariableData ( $player, 'justCreated' )) {
				$newChunk = $player->level->getChunkPlayers ( $player->x >> 4, $player->z >> 4 );
				foreach ( $this->getPrivateVariableData ( $player, 'hasSpawned' ) as $player ) {
					if (! isset ( $newChunk [$player->getLoaderId ()] )) {
						$player->despawnFrom ( $player );
					} else {
						unset ( $newChunk [$player->getLoaderId ()] );
					}
				}
				foreach ( $newChunk as $player ) {
					$player->spawnTo ( $player );
				}
			}
			
			if ($player->chunk === null) {
				return;
			}
			
			$player->chunk->addEntity ( $player );
		}
	}
	private function checkGroundState(Player $player, $movX, $movY, $movZ, $dx, $dy, $dz) {
		$player->isCollidedVertically = $movY != $dy;
		$player->isCollidedHorizontally = ($movX != $dx or $movZ != $dz);
		$player->isCollided = ($player->isCollidedHorizontally or $player->isCollidedVertically);
		$player->onGround = ($movY != $dy and $movY < 0);
	}
	private function updateFallState(Player $player, $distanceThisTick, $onGround) {
		if ($onGround === true) {
			if ($player->fallDistance > 0) {
				if ($this instanceof Living) {
					$this->fall ( $player->fallDistance );
				}
				$player->resetFallDistance ();
			}
		} elseif ($distanceThisTick < 0) {
			$player->fallDistance -= $distanceThisTick;
		}
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