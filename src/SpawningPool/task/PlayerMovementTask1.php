<?php

namespace SpawningPool\task;

use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Math;
use pocketmine\Player;
use SpawningPool\Main;

class PlayerMovementTask1 extends AsyncTask {
	private $name;
	private $levelTick;
	private $dx;
	private $dy;
	private $dz;
	/** @var AxisAlignedBB */
	private $boundingBox;
	private $bb;
	private $minX, $minY, $minZ, $maxX, $maxY, $maxZ;
	public function __construct(Player $player, $dx, $dy, $dz) {
		$this->name = $player->getName ();
		$this->levelTick = $player->level->getTickRate ();
		$this->dx = $dx;
		$this->dy = $dy;
		$this->dz = $dz;
		$this->boundingBox = serialize ( $player->boundingBox );
	}
	public function onRun() {
		echo "PlayerMovementTask1 onRun()\n";
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
		// TODO or $boundingBox is $bb right?
	}
	public function onCompletion(Server $server) {
		echo "PlayerMovementTask1 onCompletion()\n";
		$plugin = $server->getPluginManager ()->getPlugin ( 'SpawningPool' );
		$bb = unserialize ( $this->bb );
		if ($plugin instanceof Main and $bb instanceof AxisAlignedBB) {
			$plugin->getCallback ()->moveplayer->playerMoveCallback1 ( $this->name, $bb, $this->minX, $this->minY, $this->minZ, $this->maxX, $this->maxY, $this->maxZ, $this->dx, $this->dy, $this->dz );
		}
	}
}

?>