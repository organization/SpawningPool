<?php

namespace SpawningPool\callback;

use pocketmine\event\Listener;
use pocketmine\Server;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\protocol\Info as ProtocolInfo;
use pocketmine\math\Vector3;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\Player;
use pocketmine\level\Location;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\math\AxisAlignedBB;
use SpawningPool\task\PlayerMovementTask1;
use SpawningPool\task\PlayerMovementTask2;
use SpawningPool\task\PlayerMovementTask4;
use SpawningPool\task\PlayerUpdateTick;
use pocketmine\item\Item;
use pocketmine\entity\Arrow;
use pocketmine\event\inventory\InventoryPickupArrowEvent;
use pocketmine\network\protocol\TakeItemEntityPacket;
use pocketmine\entity\Item as DroppedItem;
use pocketmine\event\inventory\InventoryPickupItemEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerKickEvent;
use SpawningPool\task\PlayerMovementTask3;

class PlayerMoveController {
	/** @var Vector3 */
	public $forceMovement;
	/** @var Vector3 */
	public $teleportPosition;
	/** @var Vector3 */
	public $newPosition;
	private $name;
	private static $created = [ ];
	public function __construct($name) {
		$this->name = $name;
		
		if (isset ( self::$created [$name] ))
			return self::$created [$name];
		
		self::$created [$name] = $this;
	}
	/**
	 *
	 * @param string $name        	
	 * @return PlayerMoveController
	 */
	public static function getUser($name) {
		if (! isset ( self::$created [$name] ))
			return new PlayerMoveController ( $name );
		return self::$created [$name];
	}
	public static function disassemble($name) {
		if (isset ( self::$created [$name] ))
			unset ( self::$created [$name] );
	}
}
class MovePlayerCallback implements Listener {
	private $server;
	private $lastUpdate;
	private $tickCounter = 0;
	private $callbackIndex = 0;
	private $finishedCallbackIndex = - 1;
	private $callbackQueue = [ ];
	public function __construct() {
		$this->server = Server::getInstance ();
		$this->lastUpdate = $this->server->getTick ();
		$this->server->getScheduler ()->scheduleRepeatingTask ( new PlayerUpdateTick (), 0 );
	}
	public function onPlayerLoginEvent(PlayerLoginEvent $event) {
		$this->setPrivateVariableData ( $event->getPlayer (), 'newPosition', null );
	}
	public function onPlayerJoinEvent(PlayerJoinEvent $event) {
		$this->setPrivateVariableData ( $event->getPlayer (), 'newPosition', null );
	}
	public function onPlayerQuitEvent(PlayerQuitEvent $event) {
		PlayerMoveController::disassemble ( $event->getPlayer ()->getName () );
	}
	public function onPlayerKickEvent(PlayerKickEvent $event) {
		$event->setCancelled ();
	}
	public function onDataPacketReceiveEvent(DataPacketReceiveEvent $event) {
		if ($event->getPacket ()->pid () != ProtocolInfo::MOVE_PLAYER_PACKET)
			return;
		
		$event->setCancelled ();
		
		$packet = $event->getPacket ();
		$player = $event->getPlayer ();
		
		$controller = PlayerMoveController::getUser ( $player->getName () );
		
		$newPos = new Vector3 ( $packet->x, $packet->y - $player->getEyeHeight (), $packet->z );
		
		$revert = false;
		if (! $player->isAlive () or $player->spawned !== true) {
			$revert = true;
			$controller->forceMovement = new Vector3 ( $player->x, $player->y, $player->z );
		}
		
		if ($controller->teleportPosition !== null or ($controller->forceMovement instanceof Vector3 and (($dist = $newPos->distanceSquared ( $controller->forceMovement )) > 0.1 or $revert))) {
			$player->sendPosition ( $controller->forceMovement, $packet->yaw, $packet->pitch );
		} else {
			$packet->yaw %= 360;
			$packet->pitch %= 360;
			
			if ($packet->yaw < 0) {
				$packet->yaw += 360;
			}
			
			$player->setRotation ( $packet->yaw, $packet->pitch );
			$controller->newPosition = $newPos;
			$controller->forceMovement = null;
		}
	}
	public function checkTickUpdates() {
		++ $this->tickCounter;
		
		$tickDiff = $this->tickCounter - $this->lastUpdate;
		
		if ($tickDiff <= 0)
			return true;
		
		$this->lastUpdate = $this->tickCounter;
		
		foreach ( $this->server->getOnlinePlayers () as $player )
			$this->processMovement ( $player, $tickDiff );
	}
	public function processMovement(Player $player, $tickDiff) {
		$controller = PlayerMoveController::getUser ( $player->getName () );
		
		if (! $player->isAlive () or ! $player->spawned or $controller->newPosition === null or $controller->teleportPosition !== null)
			return;
		
		$newPos = $controller->newPosition;
		$distanceSquared = $newPos->distanceSquared ( $player );
		
		$revert = false;
		
		if (($distanceSquared / ($tickDiff ** 2)) > 100) {
			$revert = true;
		} else {
			if ($player->chunk === null or ! $player->chunk->isGenerated ()) {
				$chunk = $player->level->getChunk ( $newPos->x >> 4, $newPos->z >> 4, false );
				if ($chunk === null or ! $chunk->isGenerated ()) {
					$revert = true;
					$this->setPrivateVariableData ( $player, 'nextChunkOrderRun', 0 );
				} else {
					if ($player->chunk !== null) {
						$player->chunk->removeEntity ( $player );
					}
					$player->chunk = $chunk;
				}
			}
		}
		
		if (! $revert and $distanceSquared != 0) {
			$dx = $newPos->x - $player->x;
			$dy = $newPos->y - $player->y;
			$dz = $newPos->z - $player->z;
			
			$this->move ( $player, $dx, $dy, $dz );
			
			$diffX = $player->x - $newPos->x;
			$diffY = $player->y - $newPos->y;
			$diffZ = $player->z - $newPos->z;
			
			$yS = 0.5 + $this->getPrivateVariableData ( $player, 'ySize' );
			if ($diffY >= - $yS or $diffY <= $yS)
				$diffY = 0;
			
			$diff = ($diffX ** 2 + $diffY ** 2 + $diffZ ** 2) / ($tickDiff ** 2);
			
			if ($diff > 0) {
				$player->x = $newPos->x;
				$player->y = $newPos->y;
				$player->z = $newPos->z;
				$radius = $player->width / 2;
				$player->boundingBox->setBounds ( $player->x - $radius, $player->y, $player->z - $radius, $player->x + $radius, $player->y + $player->height, $player->z + $radius );
			}
		}
		
		$from = new Location ( $player->lastX, $player->lastY, $player->lastZ, $player->lastYaw, $player->lastPitch, $player->level );
		$to = $player->getLocation ();
		
		$delta = pow ( $player->lastX - $to->x, 2 ) + pow ( $player->lastY - $to->y, 2 ) + pow ( $player->lastZ - $to->z, 2 );
		$deltaAngle = abs ( $player->lastYaw - $to->yaw ) + abs ( $player->lastPitch - $to->pitch );
		
		if (! $revert and ($delta > (1 / 16) or $deltaAngle > 10)) {
			
			$isFirst = ($player->lastX === null or $player->lastY === null or $player->lastZ === null);
			
			$player->lastX = $to->x;
			$player->lastY = $to->y;
			$player->lastZ = $to->z;
			
			$player->lastYaw = $to->yaw;
			$player->lastPitch = $to->pitch;
			
			if (! $isFirst) {
				$ev = new PlayerMoveEvent ( $player, $from, $to );
				$this->server->getPluginManager ()->callEvent ( $ev );
				
				if (! ($revert = $ev->isCancelled ())) { // Yes, this is intended
					if ($to->distanceSquared ( $ev->getTo () ) > 0.01) { // If plugins modify the destination
						$player->teleport ( $ev->getTo () );
					} else {
						$player->level->addEntityMovement ( $player->x >> 4, $player->z >> 4, $player->getId (), $player->x, $player->y + $player->getEyeHeight (), $player->z, $player->yaw, $player->pitch, $player->yaw );
					}
				}
			}
			
			if (! $player->isSpectator ()) {
				$this->checkNearEntities ( $player, $tickDiff );
			}
			
			$player->speed = $from->subtract ( $to );
		} elseif ($distanceSquared == 0) {
			$player->speed = new Vector3 ( 0, 0, 0 );
		}
		
		if ($revert) {
			
			$player->lastX = $from->x;
			$player->lastY = $from->y;
			$player->lastZ = $from->z;
			
			$player->lastYaw = $from->yaw;
			$player->lastPitch = $from->pitch;
			
			$player->sendPosition ( $from, $from->yaw, $from->pitch, 1 );
			$this->setPrivateVariableData ( $player, 'forceMovement', new Vector3 ( $from->x, $from->y, $from->z ) );
		} else {
			$controller->forceMovement = null;
			if ($distanceSquared != 0 and $this->getPrivateVariableData ( $player, 'nextChunkOrderRun' ) > 20)
				$this->setPrivateVariableData ( $player, 'nextChunkOrderRun', 20 );
		}
		
		$controller->newPosition = null;
	}
	private function checkNearEntities(Player $player, $tickDiff) {
		foreach ( $player->level->getNearbyEntities ( $player->boundingBox->grow ( 1, 0.5, 1 ), $player ) as $entity ) {
			$entity->scheduleUpdate ();
			
			if (! $entity->isAlive ()) {
				continue;
			}
			
			if ($entity instanceof Arrow and $entity->hadCollision) {
				$item = Item::get ( Item::ARROW, 0, 1 );
				if ($player->isSurvival () and ! $player->getInventory ()->canAddItem ( $item )) {
					continue;
				}
				
				$this->server->getPluginManager ()->callEvent ( $ev = new InventoryPickupArrowEvent ( $player->getInventory (), $entity ) );
				if ($ev->isCancelled ()) {
					continue;
				}
				
				$pk = new TakeItemEntityPacket ();
				$pk->eid = $player->getId ();
				$pk->target = $entity->getId ();
				Server::broadcastPacket ( $entity->getViewers (), $pk );
				
				$pk = new TakeItemEntityPacket ();
				$pk->eid = 0;
				$pk->target = $entity->getId ();
				$player->dataPacket ( $pk );
				
				$player->getInventory ()->addItem ( clone $item );
				$entity->kill ();
			} elseif ($entity instanceof DroppedItem) {
				if ($entity->getPickupDelay () <= 0) {
					$item = $entity->getItem ();
					
					if ($item instanceof Item) {
						if ($player->isSurvival () and ! $player->getInventory ()->canAddItem ( $item )) {
							continue;
						}
						
						$this->server->getPluginManager ()->callEvent ( $ev = new InventoryPickupItemEvent ( $player->getInventory (), $entity ) );
						if ($ev->isCancelled ()) {
							continue;
						}
						
						switch ($item->getId ()) {
							case Item::WOOD :
								$player->awardAchievement ( "mineWood" );
								break;
							case Item::DIAMOND :
								$player->awardAchievement ( "diamond" );
								break;
						}
						
						$pk = new TakeItemEntityPacket ();
						$pk->eid = $player->getId ();
						$pk->target = $entity->getId ();
						Server::broadcastPacket ( $entity->getViewers (), $pk );
						
						$pk = new TakeItemEntityPacket ();
						$pk->eid = 0;
						$pk->target = $entity->getId ();
						$player->dataPacket ( $pk );
						
						$player->getInventory ()->addItem ( clone $item );
						$entity->kill ();
					}
				}
			}
		}
	}
	public function move(Player $player, $dx, $dy, $dz) {
		if ($dx == 0 and $dz == 0 and $dy == 0)
			return true;
		
		if ($player->keepMovement) {
			$player->boundingBox->offset ( $dx, $dy, $dz );
			$player->setPosition ( $player->temporalVector->setComponents ( ($player->boundingBox->minX + $player->boundingBox->maxX) / 2, $player->boundingBox->minY, ($player->boundingBox->minZ + $player->boundingBox->maxZ) / 2 ) );
			$player->onGround = $player->isPlayer ? true : false;
			return true;
		} else {
			$this->setPrivateVariableData ( $player, 'ySize', $this->getPrivateVariableData ( $player, 'ySize' ) * 0.4 );
			echo "[$this->callbackIndex] move()\n";
			$this->server->getScheduler ()->scheduleAsyncTask ( new PlayerMovementTask1 ( $player, $dx, $dy, $dz, $this->callbackIndex ++ ) );
		}
	}
	private function playerMoveProcess1($name, AxisAlignedBB $bb, $minX, $minY, $minZ, $maxX, $maxY, $maxZ, $dx, $dy, $dz, $callbackIndex) {
		echo "[" . $callbackIndex . "]" . " playerMoveProcess1()\n";
		if ($callbackIndex != $this->finishedCallbackIndex + 1) {
		}
		
		$player = $this->server->getPlayer ( $name );
		
		if (! $player instanceof Player)
			return;
		
		$collides = [ ];
		
		for($z = $minZ; $z <= $maxZ; ++ $z) {
			for($x = $minX; $x <= $maxX; ++ $x) {
				for($y = $minY; $y <= $maxY; ++ $y) {
					$vector = new Vector3 ( $x, $y, $z );
					; /* 실제 유저에게 변경이 이뤄지는 곳 */
					$this->setPrivateVariableData ( $player, 'temporalVector', $vector );
					
					$block = $player->getLevel ()->getBlock ( $vector );
					if (! $block->canPassThrough () and $block->collidesWithBB ( $bb ))
						$collides [] = $block->getBoundingBox ();
				}
			}
		}
		
		$this->server->getScheduler ()->scheduleAsyncTask ( new PlayerMovementTask2 ( $player, $dx, $dy, $dz, $collides, $callbackIndex ) );
	}
	private function playerMoveProcess2($name, $dx, $dy, $dz, $inner, $movX, $movY, $movZ, $cx, $cy, $cz, $boundingBox, $callbackIndex) {
		echo "[" . $callbackIndex . "]" . " playerMoveProcess2()\n";
		if (! $boundingBox instanceof AxisAlignedBB){
			echo "error process2\n";
			return;
		}			
		
		$player = $this->server->getPlayer ( $name );
		
		if (! $player instanceof Player)
			return;
		
		; /* 실제 유저에게 변경이 이뤄지는 곳 */
		$player->boundingBox = $boundingBox;
		$this->server->getScheduler ()->scheduleAsyncTask ( new PlayerMovementTask3 ( $player, $dx, $dy, $dz, $inner, $movX, $movY, $movZ, $cx, $cy, $cz, $callbackIndex ) );
	}
	private function playerMoveProcess3($name, AxisAlignedBB $bb, $minX, $minY, $minZ, $maxX, $maxY, $maxZ, $dx, $dy, $dz, $inner, $movX, $movY, $movZ, $cx, $cy, $cz, $callbackIndex) {
		echo "[" . $callbackIndex . "]" . " playerMoveProcess3()\n";
		$player = $this->server->getPlayer ( $name );
		
		if (! $player instanceof Player)
			return;
		
		$collides = [ ];
		
		if ($inner) {
			for($z = $minZ; $z <= $maxZ; ++ $z) {
				for($x = $minX; $x <= $maxX; ++ $x) {
					for($y = $minY; $y <= $maxY; ++ $y) {
						$vector = new Vector3 ( $x, $y, $z );
						; /* 실제 유저에게 변경이 이뤄지는 곳 */
						$this->setPrivateVariableData ( $player, 'temporalVector', $vector );
						
						$block = $player->getLevel ()->getBlock ( $vector );
						if (! $block->canPassThrough () and $block->collidesWithBB ( $bb ))
							$collides [] = $block->getBoundingBox ();
					}
				}
			}
		}
		
		$this->server->getScheduler ()->scheduleAsyncTask ( new PlayerMovementTask4 ( $player, $dx, $dy, $dz, $collides, $inner, $movX, $movY, $movZ, $cx, $cy, $cz, $callbackIndex ) );
	}
	private function playerMoveProcess4($name, $ySize, $onGround, $movX, $movY, $movZ, $dx, $dy, $dz, $callbackIndex) {
		echo "[" . $callbackIndex . "]" . " playerMoveProcess4()" . $this->finishedCallbackIndex . "\n";
		$player = $this->server->getPlayer ( $name );
		if (! $player instanceof Player)
			return;
		
		; /* 실제 유저에게 변경이 이뤄지는 곳 */
		$this->setPrivateVariableData ( $player, 'ySize', $ySize );
		
		$this->checkChunks ( $player );
		$this->checkGroundState ( $player, $movX, $movY, $movZ, $dx, $dy, $dz );
		$this->updateFallState ( $player, $dy, $onGround );
		
		if ($movX != $dx)
			$player->motionX = 0;
		
		if ($movY != $dy)
			$player->motionY = 0;
		
		if ($movZ != $dz)
			$player->motionZ = 0;
	}
	public function playerMoveCallback1($name, AxisAlignedBB $bb, $minX, $minY, $minZ, $maxX, $maxY, $maxZ, $dx, $dy, $dz, $callbackIndex) {
		/* 다음순서로 처리해야하는 콜백인지 확인 */
		if ($callbackIndex == $this->finishedCallbackIndex + 1) {
			echo "A ";
			/* 다음순서로 처리해야하는 콜백일경우 바로 처리 */
			$this->playerMoveProcess1 ( $name, $bb, $minX, $minY, $minZ, $maxX, $maxY, $maxZ, $dx, $dy, $dz, $callbackIndex );
			
			/* 미리 들어와서 쌓여있던 다다음 처리 콜백들 일괄 처리 */
			if (! isset ( $this->callbackQueue [1] ))
				return;
			
			ksort ( $this->callbackQueue [1] ); /* 큐 오름차 정렬 */
			foreach ( $this->callbackQueue [1] as $callbackIndex => $mem ) {
				$name = $mem [0];
				$bb = $mem [1];
				$minX = $mem [2];
				$minY = $mem [3];
				$minZ = $mem [4];
				$maxX = $mem [5];
				$maxY = $mem [6];
				$maxZ = $mem [7];
				$dx = $mem [8];
				$dy = $mem [9];
				$dz = $mem [10];
				unset ( $this->callbackQueue [1] [$callbackIndex] );
				echo "B ";
				$this->playerMoveProcess1 ( $name, $bb, $minX, $minY, $minZ, $maxX, $maxY, $maxZ, $dx, $dy, $dz, $callbackIndex );
			}
		} else if (isset ( $this->callbackQueue [1] [$this->finishedCallbackIndex + 1] )) {
			/* 다다음 처리해야할 콜백이 먼저들어왔을 경우 메모리 저장 */
			$this->callbackQueue [1] [$callbackIndex] = [ 
					$name,
					$bb,
					$minX,
					$minY,
					$minZ,
					$maxX,
					$maxY,
					$maxZ,
					$dx,
					$dy,
					$dz 
			];
			
			$callbackIndex = $this->finishedCallbackIndex + 1;
			/* 다음으로 처리해야할 콜백이 이미 들어와있을 경우 진행 */
			$name = $this->callbackQueue [1] [$callbackIndex] [0];
			$bb = $this->callbackQueue [1] [$callbackIndex] [1];
			$minX = $this->callbackQueue [1] [$callbackIndex] [2];
			$minY = $this->callbackQueue [1] [$callbackIndex] [3];
			$minZ = $this->callbackQueue [1] [$callbackIndex] [4];
			$maxX = $this->callbackQueue [1] [$callbackIndex] [5];
			$maxY = $this->callbackQueue [1] [$callbackIndex] [6];
			$maxZ = $this->callbackQueue [1] [$callbackIndex] [7];
			$dx = $this->callbackQueue [1] [$callbackIndex] [8];
			$dy = $this->callbackQueue [1] [$callbackIndex] [9];
			$dz = $this->callbackQueue [1] [$callbackIndex] [10];
			unset ( $this->callbackQueue [1] [$callbackIndex] );
			echo "C ";
			$this->playerMoveProcess1 ( $name, $bb, $minX, $minY, $minZ, $maxX, $maxY, $maxZ, $dx, $dy, $dz, $callbackIndex );
		} else {
			echo "[$callbackIndex] saved\n";
			/* 다다음 처리해야할 콜백이 먼저들어왔을 경우 메모리 저장 */
			$this->callbackQueue [1] [$callbackIndex] = [ 
					$name,
					$bb,
					$minX,
					$minY,
					$minZ,
					$maxX,
					$maxY,
					$maxZ,
					$dx,
					$dy,
					$dz 
			];
		}
	}
	public function playerMoveCallback2($name, $dx, $dy, $dz, $inner, $movX, $movY, $movZ, $cx, $cy, $cz, $boundingBox, $callbackIndex) {
		/* 다음순서로 처리해야하는 콜백인지 확인 */
		if ($callbackIndex == $this->finishedCallbackIndex + 1) {
			/* 다음순서로 처리해야하는 콜백일경우 바로 처리 */
			$this->playerMoveProcess2 ( $name, $dx, $dy, $dz, $inner, $movX, $movY, $movZ, $cx, $cy, $cz, $boundingBox, $callbackIndex );
			
			/* 미리 들어와서 쌓여있던 다다음 처리 콜백들 일괄 처리 */
			if (! isset ( $this->callbackQueue [2] ))
				return;
			
			ksort ( $this->callbackQueue [2] ); /* 큐 오름차 정렬 */
			foreach ( $this->callbackQueue [2] as $callbackIndex => $mem ) {
				$name = $mem [0];
				$dx = $mem [1];
				$dy = $mem [2];
				$dz = $mem [3];
				$inner = $mem [4];
				$movX = $mem [5];
				$movY = $mem [6];
				$movZ = $mem [7];
				$cx = $mem [8];
				$cy = $mem [9];
				$cz = $mem [10];
				unset ( $this->callbackQueue [2] [$callbackIndex] );
				
				$this->playerMoveProcess2 ( $name, $dx, $dy, $dz, $inner, $movX, $movY, $movZ, $cx, $cy, $cz, $boundingBox, $callbackIndex );
			}
		} else if (isset ( $this->callbackQueue [2] [$this->finishedCallbackIndex + 1] )) {
			/* 다음으로 처리해야할 콜백이 이미 들어와있을 경우 진행 */
			ksort ( $this->callbackQueue [2] ); /* 큐 오름차 정렬 */
			foreach ( $this->callbackQueue [2] as $callbackIndex => $mem ) {
				$name = $mem [0];
				$dx = $mem [1];
				$dy = $mem [2];
				$dz = $mem [3];
				$inner = $mem [4];
				$movX = $mem [5];
				$movY = $mem [6];
				$movZ = $mem [7];
				$cx = $mem [8];
				$cy = $mem [9];
				$cz = $mem [10];
				$boundingBox = $mem [0];
				unset ( $this->callbackQueue [2] [$callbackIndex] );
				
				$this->playerMoveProcess2 ( $name, $dx, $dy, $dz, $inner, $movX, $movY, $movZ, $cx, $cy, $cz, $boundingBox, $callbackIndex );
			}
		} else {
			/* 다다음 처리해야할 콜백이 먼저들어왔을 경우 메모리 저장 */
			$this->callbackQueue [2] [$callbackIndex] = [ 
					$name,
					$dx,
					$dy,
					$dz,
					$inner,
					$movX,
					$movY,
					$movZ,
					$cx,
					$cy,
					$cz,
					$boundingBox 
			];
		}
	}
	public function playerMoveCallback3($name, AxisAlignedBB $bb, $minX, $minY, $minZ, $maxX, $maxY, $maxZ, $dx, $dy, $dz, $inner, $movX, $movY, $movZ, $cx, $cy, $cz, $callbackIndex) {
		/* 다음순서로 처리해야하는 콜백인지 확인 */
		if ($callbackIndex == $this->finishedCallbackIndex + 1) {
			/* 다음순서로 처리해야하는 콜백일경우 바로 처리 */
			$this->playerMoveProcess3 ( $name, $bb, $minX, $minY, $minZ, $maxX, $maxY, $maxZ, $dx, $dy, $dz, $inner, $movX, $movY, $movZ, $cx, $cy, $cz, $callbackIndex );
			
			/* 미리 들어와서 쌓여있던 다다음 처리 콜백들 일괄 처리 */
			if (! isset ( $this->callbackQueue [3] ))
				return;
			
			ksort ( $this->callbackQueue [3] ); /* 큐 오름차 정렬 */
			foreach ( $this->callbackQueue [3] as $callbackIndex => $mem ) {
				$name = $mem [0];
				$bb = $mem [1];
				$minX = $mem [2];
				$minY = $mem [3];
				$minZ = $mem [4];
				$maxX = $mem [5];
				$maxY = $mem [6];
				$maxZ = $mem [7];
				$dx = $mem [8];
				$dy = $mem [9];
				$dz = $mem [10];
				$inner = $mem [11];
				$movX = $mem [12];
				$movY = $mem [13];
				$movZ = $mem [14];
				$cx = $mem [15];
				$cy = $mem [16];
				$cz = $mem [17];
				unset ( $this->callbackQueue [3] [$callbackIndex] );
				
				$this->playerMoveProcess3 ( $name, $bb, $minX, $minY, $minZ, $maxX, $maxY, $maxZ, $dx, $dy, $dz, $inner, $movX, $movY, $movZ, $cx, $cy, $cz, $callbackIndex );
			}
		} else if (isset ( $this->callbackQueue [3] [$this->finishedCallbackIndex + 1] )) {
			/* 다음으로 처리해야할 콜백이 이미 들어와있을 경우 진행 */
			ksort ( $this->callbackQueue [3] ); /* 큐 오름차 정렬 */
			foreach ( $this->callbackQueue [3] as $callbackIndex => $mem ) {
				$name = $mem [0];
				$bb = $mem [1];
				$minX = $mem [2];
				$minY = $mem [3];
				$minZ = $mem [4];
				$maxX = $mem [5];
				$maxY = $mem [6];
				$maxZ = $mem [7];
				$dx = $mem [8];
				$dy = $mem [9];
				$dz = $mem [10];
				$inner = $mem [11];
				$movX = $mem [12];
				$movY = $mem [13];
				$movZ = $mem [14];
				$cx = $mem [15];
				$cy = $mem [16];
				$cz = $mem [17];
				unset ( $this->callbackQueue [3] [$callbackIndex] );
				
				$this->playerMoveProcess3 ( $name, $bb, $minX, $minY, $minZ, $maxX, $maxY, $maxZ, $dx, $dy, $dz, $inner, $movX, $movY, $movZ, $cx, $cy, $cz, $callbackIndex );
			}
		} else {
			/* 다다음 처리해야할 콜백이 먼저들어왔을 경우 메모리 저장 */
			$this->callbackQueue [3] [$callbackIndex] = [ 
					$name,
					$bb,
					$minX,
					$minY,
					$minZ,
					$maxX,
					$maxY,
					$maxZ,
					$dx,
					$dy,
					$dz,
					$inner,
					$movX,
					$movY,
					$movZ,
					$cx,
					$cy,
					$cz 
			];
		}
	}
	public function playerMoveCallback4($name, $ySize, $onGround, $movX, $movY, $movZ, $dx, $dy, $dz, $callbackIndex) {
		/* 다음순서로 처리해야하는 콜백인지 확인 */
		if ($callbackIndex == $this->finishedCallbackIndex + 1) {
			/* 다음순서로 처리해야하는 콜백일경우 바로 처리 */
			$this->playerMoveProcess4 ( $name, $ySize, $onGround, $movX, $movY, $movZ, $dx, $dy, $dz, $callbackIndex );
			$this->finishedCallbackIndex ++;
			/* 미리 들어와서 쌓여있던 다다음 처리 콜백들 일괄 처리 */
			if (! isset ( $this->callbackQueue [4] ))
				return;
			
			ksort ( $this->callbackQueue [4] ); /* 큐 오름차 정렬 */
			foreach ( $this->callbackQueue [4] as $callbackIndex => $mem ) {
				$name = $mem [0];
				$ySize = $mem [1];
				$onGround = $mem [2];
				$movX = $mem [3];
				$movY = $mem [4];
				$movZ = $mem [5];
				$dx = $mem [6];
				$dy = $mem [7];
				$dz = $mem [8];
				unset ( $this->callbackQueue [4] [$callbackIndex] );
				
				$this->playerMoveProcess4 ( $name, $ySize, $onGround, $movX, $movY, $movZ, $dx, $dy, $dz, $callbackIndex );
				$this->finishedCallbackIndex ++;
			}
		} else if (isset ( $this->callbackQueue [4] [$this->finishedCallbackIndex + 1] )) {
			/* 다음으로 처리해야할 콜백이 이미 들어와있을 경우 진행 */
			ksort ( $this->callbackQueue [4] ); /* 큐 오름차 정렬 */
			foreach ( $this->callbackQueue [4] as $callbackIndex => $mem ) {
				$name = $mem [0];
				$ySize = $mem [1];
				$onGround = $mem [2];
				$movX = $mem [3];
				$movY = $mem [4];
				$movZ = $mem [5];
				$dx = $mem [6];
				$dy = $mem [7];
				$dz = $mem [8];
				unset ( $this->callbackQueue [4] [$callbackIndex] );
				
				$this->playerMoveProcess4 ( $name, $ySize, $onGround, $movX, $movY, $movZ, $dx, $dy, $dz, $callbackIndex );
				$this->finishedCallbackIndex ++;
			}
		} else {
			/* 다다음 처리해야할 콜백이 먼저들어왔을 경우 메모리 저장 */
			$this->callbackQueue [4] [$callbackIndex] = [ 
					$name,
					$ySize,
					$onGround,
					$movX,
					$movY,
					$movZ,
					$dx,
					$dy,
					$dz 
			];
		}
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