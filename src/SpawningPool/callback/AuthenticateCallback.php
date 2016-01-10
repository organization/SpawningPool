<?php

namespace SpawningPool\callback;

use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\protocol\Info as ProtocolInfo;
use pocketmine\utils\TextFormat;
use pocketmine\Server;
use pocketmine\network\protocol\PlayStatusPacket;
use pocketmine\event\player\PlayerPreLoginEvent;
use SpawningPool\task\GetOfflinePlayerDataTask;
use pocketmine\Player;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\level\Position;
use pocketmine\network\protocol\StartGamePacket;
use pocketmine\network\protocol\SetTimePacket;
use pocketmine\network\protocol\SetSpawnPositionPacket;
use pocketmine\network\protocol\SetHealthPacket;
use pocketmine\network\protocol\SetDifficultyPacket;
use pocketmine\network\protocol\ContainerSetContentPacket;
use pocketmine\item\Item;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\level\Level;
use pocketmine\event\Timings;
use pocketmine\level\format\FullChunk;
use pocketmine\math\Vector3;
use pocketmine\entity\Entity;
use pocketmine\math\AxisAlignedBB;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\entity\Effect;
use pocketmine\inventory\PlayerInventory;

class AuthenticateCallback implements Listener {
	private $server;
	private $queue = [ ];
	public function __construct() {
		$this->server = Server::getInstance ();
	}
	public function onDataPacketReceiveEvent(DataPacketReceiveEvent $event) {
		if ($event->getPacket ()->pid () != ProtocolInfo::LOGIN_PACKET)
			return;
		$event->setCancelled ();
		
		$packet = $event->getPacket ();
		$player = $event->getPlayer ();
		
		if ($player->loggedIn) {
			return;
		}
		
		// $player->username = TextFormat::clean ( $packet->username );
		// $player->displayName = $packet->username;
		// $player->iusername = strtolower ( $packet->username );
		
		$this->setPrivateVariableData ( $player, 'username', $packet->username );
		$this->setPrivateVariableData ( $player, 'displayName', $packet->username );
		$this->setPrivateVariableData ( $player, 'iusername', $packet->username );
		
		$player->setNameTag ( $packet->username );
		
		if (count ( $this->server->getOnlinePlayers () ) >= $this->server->getMaxPlayers () and $player->kick ( "disconnectionScreen.serverFull", false ))
			return;
		
		if ($packet->protocol1 !== ProtocolInfo::CURRENT_PROTOCOL) {
			if ($packet->protocol1 < ProtocolInfo::CURRENT_PROTOCOL) {
				$message = "disconnectionScreen.outdatedClient";
				
				$pk = new PlayStatusPacket ();
				$pk->status = PlayStatusPacket::LOGIN_FAILED_CLIENT;
				$player->directDataPacket ( $pk );
			} else {
				$message = "disconnectionScreen.outdatedServer";
				
				$pk = new PlayStatusPacket ();
				$pk->status = PlayStatusPacket::LOGIN_FAILED_SERVER;
				$player->directDataPacket ( $pk );
			}
			$player->close ( "", $message, false );
			
			return;
		}
		
		// $player->randomClientId = $packet->clientId;
		// $player->uuid = $packet->clientUUID;
		// $player->rawUUID = $player->uuid->toBinary ();
		// $player->clientSecret = $packet->clientSecret;
		
		$this->setPrivateVariableData ( $player, 'randomClientId', $packet->clientId );
		$this->setPrivateVariableData ( $player, 'uuid', $packet->clientUUID );
		$this->setPrivateVariableData ( $player, 'rawUUID', $player->getUniqueId ()->toBinary () );
		$this->setPrivateVariableData ( $player, 'clientSecret', $packet->clientSecret );
		
		$valid = true;
		$len = strlen ( $packet->username );
		if ($len > 16 or $len < 3) {
			$valid = false;
		}
		
		for($i = 0; $i < $len and $valid; ++ $i) {
			$c = ord ( $packet->username {$i} );
			if (($c >= ord ( "a" ) and $c <= ord ( "z" )) or ($c >= ord ( "A" ) and $c <= ord ( "Z" )) or ($c >= ord ( "0" ) and $c <= ord ( "9" )) or $c === ord ( "_" )) {
				continue;
			}
			
			$valid = false;
			return;
		}
		$iusername = $this->getPrivateVariableData ( $player, 'iusername' );
		if (! $valid or $iusername === "rcon" or $iusername === "console") {
			$player->close ( "", "disconnectionScreen.invalidName" );
			return;
		}
		
		if (strlen ( $packet->skin ) !== 64 * 32 * 4 and strlen ( $packet->skin ) !== 64 * 64 * 4) {
			$player->close ( "", "disconnectionScreen.invalidSkin" );
			return;
		}
		
		$player->setSkin ( $packet->skin, $packet->skinName );
		
		$this->server->getPluginManager ()->callEvent ( $ev = new PlayerPreLoginEvent ( $player, "Plugin reason" ) );
		if ($ev->isCancelled ()) {
			$player->close ( "", $ev->getKickMessage () );
			return;
		}
		$this->queue [$player->getName ()] = $player;
		$this->tryAuthenticate ( $player );
	}
	public function tryAuthenticate(Player $player) {
		$this->server->getScheduler ()->scheduleAsyncTask ( new GetOfflinePlayerDataTask ( $player->getName (), $this->server->getDefaultLevel ()->getSafeSpawn (), $this->server->getDefaultLevel ()->getName (), $this->server->getGamemode (), $this->server->getDataPath () ) );
	}
	public function authenticateCallback($name, $nbt) {
		if (! isset ( $this->queue [$name] ))
			return;
		
		$player = $this->queue [$name];
		if ($player instanceof Player) {
			unset ( $this->queue [$name] );
			$this->processLogin ( $player, $nbt );
		}
	}
	public function processLogin(Player $player, CompoundTag $nbt) {
		if (! $this->server->isWhitelisted ( strtolower ( $player->getName () ) )) {
			$player->close ( $player->getLeaveMessage (), "Server is white-listed" );
			
			return;
		} elseif ($this->server->getNameBans ()->isBanned ( strtolower ( $player->getName () ) ) or $this->server->getIPBans ()->isBanned ( $player->getAddress () )) {
			$player->close ( $player->getLeaveMessage (), "You are banned" );
			
			return;
		}
		
		if ($player->hasPermission ( Server::BROADCAST_CHANNEL_USERS )) {
			$this->server->getPluginManager ()->subscribeToPermission ( Server::BROADCAST_CHANNEL_USERS, $player );
		}
		if ($player->hasPermission ( Server::BROADCAST_CHANNEL_ADMINISTRATIVE )) {
			$this->server->getPluginManager ()->subscribeToPermission ( Server::BROADCAST_CHANNEL_ADMINISTRATIVE, $player );
		}
		
		foreach ( $this->server->getOnlinePlayers () as $p ) {
			if ($p !== $player and strtolower ( $p->getName () ) === strtolower ( $player->getName () )) {
				if ($p->kick ( "logged in from another location" ) === false) {
					$player->close ( $player->getLeaveMessage (), "Logged in from another location" );
					return;
				}
			} elseif ($p->loggedIn and $player->getUniqueId ()->equals ( $p->getUniqueId () )) {
				if ($p->kick ( "logged in from another location" ) === false) {
					$player->close ( $player->getLeaveMessage (), "Logged in from another location" );
					return;
				}
			}
		}
		
		// $nbt = $player->server->getOfflinePlayerData ( $this->username );
		if (! isset ( $nbt->NameTag )) {
			$nbt->NameTag = new StringTag ( "NameTag", $this->getPrivateVariableData ( $player, 'username' ) );
		} else {
			$nbt ["NameTag"] = $this->getPrivateVariableData ( $player, 'username' );
		}
		$player->gamemode = $nbt ["playerGameType"] & 0x03;
		if ($this->server->getForceGamemode ()) {
			$player->gamemode = $this->server->getGamemode ();
			$nbt->playerGameType = new IntTag ( "playerGameType", $player->gamemode );
		}
		
		// $player->allowFlight = $player->isCreative ();
		$this->setPrivateVariableData ( $player, 'allowFlight', $player->isCreative () );
		
		if (($level = $this->server->getLevelByName ( $nbt ["Level"] )) === null) {
			$player->setLevel ( $this->server->getDefaultLevel () );
			$nbt ["Level"] = $this->level->getName ();
			$nbt ["Pos"] [0] = $player->level->getSpawnLocation ()->x;
			$nbt ["Pos"] [1] = $player->level->getSpawnLocation ()->y;
			$nbt ["Pos"] [2] = $player->level->getSpawnLocation ()->z;
		} else {
			$player->setLevel ( $level );
		}
		
		if (! ($nbt instanceof CompoundTag)) {
			$player->close ( $player->getLeaveMessage (), "Invalid data" );
			
			return;
		}
		
		$player->achievements = [ ];
		
		/** @var Byte $achievement */
		foreach ( $nbt->Achievements as $achievement ) {
			$player->achievements [$achievement->getName ()] = $achievement->getValue () > 0 ? true : false;
		}
		$nbt->lastPlayed = new LongTag ( "lastPlayed", floor ( microtime ( true ) * 1000 ) );
		if ($this->server->getAutoSave ()) {
			$this->server->saveOfflinePlayerData ( $player->getName (), $nbt, true );
		}
		
		// parent::__construct ( $this->level->getChunk ( $nbt ["Pos"] [0] >> 4, $nbt ["Pos"] [2] >> 4, true ), $nbt );
		$this->entityConstruct ( $player, $player->getLevel ()->getChunk ( $nbt ["Pos"] [0] >> 4, $nbt ["Pos"] [2] >> 4, true ), $nbt );
		
		$player->loggedIn = true;
		$this->server->addOnlinePlayer ( $player );
		
		$this->server->getPluginManager ()->callEvent ( $ev = new PlayerLoginEvent ( $player, "Plugin reason" ) );
		if ($ev->isCancelled ()) {
			$player->close ( $player->getLeaveMessage (), $ev->getKickMessage () );
			
			return;
		}
		
		if ($player->isCreative ()) {
			$player->getInventory ()->setHeldItemSlot ( 0 );
		} else {
			$player->getInventory ()->setHeldItemSlot ( $player->getInventory ()->getHotbarSlotIndex ( 0 ) );
		}
		
		$pk = new PlayStatusPacket ();
		$pk->status = PlayStatusPacket::LOGIN_SUCCESS;
		$player->dataPacket ( $pk );
		
		if ($this->getPrivateVariableData ( $player, 'spawnPosition' ) === null and isset ( $player->namedtag->SpawnLevel ) and ($level = $this->server->getLevelByName ( $player->namedtag ["SpawnLevel"] )) instanceof Level) {
			$this->setPrivateVariableData ( $player, 'spawnPosition', new Position ( $player->namedtag ["SpawnX"], $player->namedtag ["SpawnY"], $player->namedtag ["SpawnZ"], $level ) );
		}
		$spawnPosition = $player->getSpawn ();
		
		$pk = new StartGamePacket ();
		$pk->seed = - 1;
		$pk->dimension = 0;
		$pk->x = $player->x;
		$pk->y = $player->y;
		$pk->z = $player->z;
		$pk->spawnX = ( int ) $spawnPosition->x;
		$pk->spawnY = ( int ) $spawnPosition->y;
		$pk->spawnZ = ( int ) $spawnPosition->z;
		$pk->generator = 1; // 0 old, 1 infinite, 2 flat
		$pk->gamemode = $player->gamemode & 0x01;
		$pk->eid = 0; // Always use EntityID as zero for the actual player
		$player->dataPacket ( $pk );
		
		$pk = new SetTimePacket ();
		$pk->time = $player->level->getTime ();
		$pk->started = $player->level->stopTime == false;
		$player->dataPacket ( $pk );
		
		$pk = new SetSpawnPositionPacket ();
		$pk->x = ( int ) $spawnPosition->x;
		$pk->y = ( int ) $spawnPosition->y;
		$pk->z = ( int ) $spawnPosition->z;
		$player->dataPacket ( $pk );
		
		$pk = new SetHealthPacket ();
		$pk->health = $player->getHealth ();
		$player->dataPacket ( $pk );
		
		$pk = new SetDifficultyPacket ();
		$pk->difficulty = $this->server->getDifficulty ();
		$player->dataPacket ( $pk );
		
		// $this->server->getLogger ()->info ( $this->server->getLanguage ()->translateString ( "pocketmine.player.logIn", [
		// TextFormat::AQUA . $player->username . TextFormat::WHITE,
		// $player->ip,
		// $player->port,
		// $player->id,
		// $player->level->getName (),
		// round ( $player->x, 4 ),
		// round ( $player->y, 4 ),
		// round ( $player->z, 4 )
		// ] ) );
		$this->server->getLogger ()->info ( $this->server->getLanguage ()->translateString ( "pocketmine.player.logIn", [ 
				TextFormat::AQUA . $this->getPrivateVariableData ( $player, 'username' ) . TextFormat::WHITE,
				$this->getPrivateVariableData ( $player, 'ip' ),
				$this->getPrivateVariableData ( $player, 'port' ),
				$this->getPrivateVariableData ( $player, 'id' ),
				$player->level->getName (),
				round ( $player->x, 4 ),
				round ( $player->y, 4 ),
				round ( $player->z, 4 ) 
		] ) );
		
		if ($player->isOp ()) {
			$player->setRemoveFormat ( false );
		}
		
		if ($player->gamemode === Player::SPECTATOR) {
			$pk = new ContainerSetContentPacket ();
			$pk->windowid = ContainerSetContentPacket::SPECIAL_CREATIVE;
			$player->dataPacket ( $pk );
		} else {
			$pk = new ContainerSetContentPacket ();
			$pk->windowid = ContainerSetContentPacket::SPECIAL_CREATIVE;
			$pk->slots = Item::getCreativeItems ();
			$player->dataPacket ( $pk );
		}
		
		$this->setPrivateVariableData ( $player, 'teleportPosition', $player->getPosition () );
		$this->setPrivateVariableData ( $player, 'forceMovement', $player->getPosition () );
		
		$this->server->onPlayerLogin ( $player );
	}
	private function entityConstruct(Player $player, FullChunk $chunk, CompoundTag $nbt) {
		assert ( $chunk !== null and $chunk->getProvider () !== null );
		
		$this->setPrivateVariableData ( $player, 'timings', Timings::getEntityTimings ( $player ) );
		$this->setPrivateVariableData ( $player, 'isPlayer', $player instanceof Player );
		
		$player->temporalVector = new Vector3 ();
		
		if ($player->eyeHeight === null) {
			$player->eyeHeight = $player->height / 2 + 0.1;
		}
		
		$this->setPrivateVariableData ( $player, 'id', Entity::$entityCount ++ );
		$this->setPrivateVariableData ( $player, 'justCreated', true );
		
		$player->namedtag = $nbt;
		
		$player->chunk = $chunk;
		$player->setLevel ( $chunk->getProvider ()->getLevel () );
		$this->setPrivateVariableData ( $player, 'server', $chunk->getProvider ()->getLevel ()->getServer () );
		
		$player->boundingBox = new AxisAlignedBB ( 0, 0, 0, 0, 0, 0 );
		$player->setPositionAndRotation ( $player->temporalVector->setComponents ( $player->namedtag ["Pos"] [0], $player->namedtag ["Pos"] [1], $player->namedtag ["Pos"] [2] ), $player->namedtag->Rotation [0], $player->namedtag->Rotation [1] );
		$player->setMotion ( $player->temporalVector->setComponents ( $player->namedtag ["Motion"] [0], $player->namedtag ["Motion"] [1], $player->namedtag ["Motion"] [2] ) );
		
		assert ( ! is_nan ( $player->x ) and ! is_infinite ( $player->x ) and ! is_nan ( $player->y ) and ! is_infinite ( $player->y ) and ! is_nan ( $player->z ) and ! is_infinite ( $player->z ) );
		
		if (! isset ( $player->namedtag->FallDistance )) {
			$player->namedtag->FallDistance = new FloatTag ( "FallDistance", 0 );
		}
		$player->fallDistance = $player->namedtag ["FallDistance"];
		
		if (! isset ( $player->namedtag->Fire )) {
			$player->namedtag->Fire = new ShortTag ( "Fire", 0 );
		}
		$player->fireTicks = $player->namedtag ["Fire"];
		
		if (! isset ( $player->namedtag->Air )) {
			$player->namedtag->Air = new ShortTag ( "Air", 300 );
		}
		$player->setDataProperty ( $player::DATA_AIR, $player::DATA_TYPE_SHORT, $player->namedtag ["Air"] );
		
		if (! isset ( $player->namedtag->OnGround )) {
			$player->namedtag->OnGround = new ByteTag ( "OnGround", 0 );
		}
		$player->onGround = $player->namedtag ["OnGround"] > 0 ? true : false;
		
		if (! isset ( $player->namedtag->Invulnerable )) {
			$player->namedtag->Invulnerable = new ByteTag ( "Invulnerable", 0 );
		}
		$player->invulnerable = $player->namedtag ["Invulnerable"] > 0 ? true : false;
		
		$player->chunk->addEntity ( $player );
		$player->level->addEntity ( $player );
		$this->initialHuman ( $player );
		$player->lastUpdate = $this->server->getTick ();
		$this->server->getPluginManager ()->callEvent ( new EntitySpawnEvent ( $player ) );
		
		$player->scheduleUpdate ();
	}
	public function initialEntity(Player $player) {
		assert ( $player->namedtag instanceof CompoundTag );
		if (isset ( $player->namedtag->ActiveEffects )) {
			foreach ( $player->namedtag->ActiveEffects->getValue () as $e ) {
				$effect = Effect::getEffect ( $e ["Id"] );
				if ($effect === null) {
					continue;
				}
				$effect->setAmplifier ( $e ["Amplifier"] )->setDuration ( $e ["Duration"] )->setVisible ( $e ["ShowParticles"] > 0 );
				$player->addEffect ( $effect );
			}
		}
		if (isset ( $player->namedtag->CustomName )) {
			$player->setNameTag ( $player->namedtag ["CustomName"] );
			if (isset ( $player->namedtag->CustomNameVisible )) {
				$player->setNameTagVisible ( $this->namedtag ["CustomNameVisible"] > 0 );
			}
		}
		$player->scheduleUpdate ();
	}
	private function initialHuman(Player $player) {
		$player->setDataFlag ( $player::DATA_PLAYER_FLAGS, $player::DATA_PLAYER_FLAG_SLEEP, false );
		$player->setDataProperty ( $player::DATA_PLAYER_BED_POSITION, $player::DATA_TYPE_POS, [ 
				0,
				0,
				0 
		] );
		
		$inventory = new PlayerInventory ( $player );
		$this->setPrivateVariableData ( $player, 'inventory', $inventory );
		if ($player instanceof Player) {
			$player->addWindow ( $inventory, 0 );
		}
		
		if (! ($player instanceof Player)) {
			if (isset ( $player->namedtag->NameTag )) {
				$player->setNameTag ( $player->namedtag ["NameTag"] );
			}
			if (isset ( $player->namedtag->Skin ) and $player->namedtag->Skin instanceof CompoundTag) {
				$player->setSkin ( $player->namedtag->Skin ["Data"], $player->namedtag->Skin ["Slim"] > 0 );
			}
			
			$this->setPrivateVariableData ( $player, 'uuid', UUID::fromData ( $player->getId (), $player->getSkinData (), $player->getNameTag () ) );
		}
		
		if (isset ( $player->namedtag->Inventory ) and $player->namedtag->Inventory instanceof ListTag) {
			foreach ( $player->namedtag->Inventory as $item ) {
				if ($item ["Slot"] >= 0 and $item ["Slot"] < 9) { // Hotbar
					$player->inventory->setHotbarSlotIndex ( $item ["Slot"], isset ( $item ["TrueSlot"] ) ? $item ["TrueSlot"] : - 1 );
				} elseif ($item ["Slot"] >= 100 and $item ["Slot"] < 104) { // Armor
					$player->getInventory ()->setItem ( $player->getInventory ()->getSize () + $item ["Slot"] - 100, NBT::getItemHelper ( $item ) );
				} else {
					$player->getInventory ()->setItem ( $item ["Slot"] - 9, NBT::getItemHelper ( $item ) );
				}
			}
		}
		$this->initialEntity ( $player );
	}
	function getPrivateVariableData($object, $variableName) {
		$reflectionClass = new \ReflectionClass ( $object );
		$property = $reflectionClass->getProperty ( $variableName );
		$property->setAccessible ( true );
		return $property->getValue ( $object );
	}
	function setPrivateVariableData($object, $variableName, $set) {
		$reflectionClass = new \ReflectionClass ( $object );
		$property = $reflectionClass->getProperty ( $variableName );
		$property->setAccessible ( true );
		$property->setValue ( $object, $set );
	}
}

?>