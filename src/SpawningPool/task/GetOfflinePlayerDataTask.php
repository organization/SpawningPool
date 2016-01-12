<?php

namespace SpawningPool\task;

use pocketmine\scheduler\AsyncTask;
use pocketmine\nbt\NBT;
use pocketmine\Server;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\utils\Config;
use SpawningPool\Main;
use pocketmine\level\Position;

class GetOfflinePlayerDataTask extends AsyncTask {
	private $name;
	private $spawn;
	private $levelname;
	private $gamemode;
	private $datapath;
	private $nbt;
	public function __construct($name, Position $spawn, $levelname, $gamemode, $datapath) {
		$this->name = $name;
		$this->spawn = $spawn->x . ":" . $spawn->y . ":" . $spawn->z . ":" . $levelname;
		$this->gamemode = $gamemode;
		$this->datapath = $datapath;
	}
	public function onCompletion(Server $server) {
		$plugin = $server->getPluginManager ()->getPlugin ( "SpawningPool" );
		$nbt = unserialize ( $this->nbt );
		if ($plugin instanceof Main) {
			$plugin->getServer ()->saveOfflinePlayerData ( $this->name, $nbt );
			$plugin->getCallback ()->authenticate->authenticateCallback ( $this->name, $nbt );
		}
	}
	public function onRun() {
		$nbt = $this->getOfflinePlayerData ( $this->name );
		$this->nbt = serialize ( $nbt );
	}
	/**
	 *
	 * @param string $name        	
	 *
	 * @return CompoundTag
	 */
	public function getOfflinePlayerData($name) {
		$name = strtolower ( $name );
		$path = $this->datapath . "players/";
		if (file_exists ( $path . "$name.dat" )) {
			try {
				$nbt = new NBT ( NBT::BIG_ENDIAN );
				$nbt->readCompressed ( file_get_contents ( $path . "$name.dat" ) );
				
				return $nbt->getData ();
			} catch ( \Throwable $e ) { // zlib decode error / corrupt data
				rename ( $path . "$name.dat", $path . "$name.dat.bak" );
			}
		}
		$spawn = explode ( ':', $this->spawn );
		$nbt = new CompoundTag ( "", [ 
				new LongTag ( "firstPlayed", floor ( microtime ( true ) * 1000 ) ),
				new LongTag ( "lastPlayed", floor ( microtime ( true ) * 1000 ) ),
				new ListTag ( "Pos", [ 
						new DoubleTag ( 0, $spawn [0] ),
						new DoubleTag ( 1, $spawn [1] ),
						new DoubleTag ( 2, $spawn [2] ) 
				] ),
				new StringTag ( "Level", $spawn [3] ),
				new ListTag ( "Inventory", [ ] ),
				new CompoundTag ( "Achievements", [ ] ),
				new IntTag ( "playerGameType", $this->gamemode ),
				new ListTag ( "Motion", [ 
						new DoubleTag ( 0, 0.0 ),
						new DoubleTag ( 1, 0.0 ),
						new DoubleTag ( 2, 0.0 ) 
				] ),
				new ListTag ( "Rotation", [ 
						new FloatTag ( 0, 0.0 ),
						new FloatTag ( 1, 0.0 ) 
				] ),
				new FloatTag ( "FallDistance", 0.0 ),
				new ShortTag ( "Fire", 0 ),
				new ShortTag ( "Air", 300 ),
				new ByteTag ( "OnGround", 1 ),
				new ByteTag ( "Invulnerable", 0 ),
				new StringTag ( "NameTag", $name ) 
		] );
		$nbt->Pos->setTagType ( NBT::TAG_Double );
		$nbt->Inventory->setTagType ( NBT::TAG_Compound );
		$nbt->Motion->setTagType ( NBT::TAG_Double );
		$nbt->Rotation->setTagType ( NBT::TAG_Float );
		
		if (file_exists ( $path . "$name.yml" )) { // Importing old PocketMine-MP files
			$data = new Config ( $path . "$name.yml", Config::YAML, [ ] );
			$nbt ["playerGameType"] = ( int ) $data->get ( "gamemode" );
			$nbt ["Level"] = $data->get ( "position" ) ["level"];
			$nbt ["Pos"] [0] = $data->get ( "position" ) ["x"];
			$nbt ["Pos"] [1] = $data->get ( "position" ) ["y"];
			$nbt ["Pos"] [2] = $data->get ( "position" ) ["z"];
			$nbt ["SpawnLevel"] = $data->get ( "spawn" ) ["level"];
			$nbt ["SpawnX"] = ( int ) $data->get ( "spawn" ) ["x"];
			$nbt ["SpawnY"] = ( int ) $data->get ( "spawn" ) ["y"];
			$nbt ["SpawnZ"] = ( int ) $data->get ( "spawn" ) ["z"];
			foreach ( $data->get ( "inventory" ) as $slot => $item ) {
				if (count ( $item ) === 3) {
					$nbt->Inventory [$slot + 9] = new CompoundTag ( "", [ 
							new ShortTag ( "id", $item [0] ),
							new ShortTag ( "Damage", $item [1] ),
							new ByteTag ( "Count", $item [2] ),
							new ByteTag ( "Slot", $slot + 9 ),
							new ByteTag ( "TrueSlot", $slot + 9 ) 
					] );
				}
			}
			foreach ( $data->get ( "hotbar" ) as $slot => $itemSlot ) {
				if (isset ( $nbt->Inventory [$itemSlot + 9] )) {
					$item = $nbt->Inventory [$itemSlot + 9];
					$nbt->Inventory [$slot] = new CompoundTag ( "", [ 
							new ShortTag ( "id", $item ["id"] ),
							new ShortTag ( "Damage", $item ["Damage"] ),
							new ByteTag ( "Count", $item ["Count"] ),
							new ByteTag ( "Slot", $slot ),
							new ByteTag ( "TrueSlot", $item ["TrueSlot"] ) 
					] );
				}
			}
			foreach ( $data->get ( "armor" ) as $slot => $item ) {
				if (count ( $item ) === 2) {
					$nbt->Inventory [$slot + 100] = new CompoundTag ( "", [ 
							new ShortTag ( "id", $item [0] ),
							new ShortTag ( "Damage", $item [1] ),
							new ByteTag ( "Count", 1 ),
							new ByteTag ( "Slot", $slot + 100 ) 
					] );
				}
			}
			foreach ( $data->get ( "achievements" ) as $achievement => $status ) {
				$nbt->Achievements [$achievement] = new ByteTag ( $achievement, $status == true ? 1 : 0 );
			}
			unlink ( $path . "$name.yml" );
		}
		
		return $nbt;
	}
}

?>