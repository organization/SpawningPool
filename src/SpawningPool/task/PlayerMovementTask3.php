<?php

namespace SpawningPool\task;

use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Math;
use pocketmine\Player;
use SpawningPool\Main;

class PlayerMovementTask3 extends AsyncTask {
	private $name;
	private $levelTick;
	private $dx;
	private $dy;
	private $dz;
	/** @var AxisAlignedBB */
	private $boundingBox;
	private $bb;
	private $minX, $minY, $minZ, $maxX, $maxY, $maxZ;
	private $movX, $movY, $movZ;
	private $inner;
	private $cx, $cy, $cz;
	public function __construct(Player $player, $dx, $dy, $dz, $inner, $movX, $movY, $movZ, $cx, $cy, $cz) {
		$this->name = $player->getName ();
		$this->levelTick = $player->level->getTickRate ();
		
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
		$this->inner = $inner;
	}
	public function onRun() {
		$boundingBox = unserialize ( $this->boundingBox );
		if (! $boundingBox instanceof AxisAlignedBB)
			return;
		
		$this->bb = serialize ( $this->levelTick > 1 ? $boundingBox->getOffsetBoundingBox ( $this->dx, $this->dy, $this->dz ) : $boundingBox->addCoord ( $this->dx, $this->dy, $this->dz ) );
		$this->minX = Math::floorFloat ( $boundingBox->minX );
		$this->minY = Math::floorFloat ( $boundingBox->minY );
		$this->minZ = Math::floorFloat ( $boundingBox->minZ );
		$this->maxX = Math::ceilFloat ( $boundingBox->maxX );
		$this->maxY = Math::ceilFloat ( $boundingBox->maxY );
		$this->maxZ = Math::ceilFloat ( $boundingBox->maxZ );
	}
	public function onCompletion(Server $server) {
		$plugin = $server->getPluginManager ()->getPlugin ( 'SpawningPool' );
		$bb = unserialize ( $this->bb );
		if ($plugin instanceof Main and $bb instanceof AxisAlignedBB)
			$plugin->getCallback ()->moveplayer->playerMoveCallback3 ( $this->name, $bb, $this->minX, $this->minY, $this->minZ, $this->maxX, $this->maxY, $this->maxZ, $this->dx, $this->dy, $this->dz, $this->inner, $this->movX, $this->movY, $this->movZ, $this->cx, $this->cy, $this->cz );
	}
}

?>