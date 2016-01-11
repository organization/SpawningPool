<?php

namespace SpawningPool\callback;

use pocketmine\Server;
use pocketmine\plugin\Plugin;
use SpawningPool\Main;

class CallbackManager {
	/** @var Server */
	private $server;
	/** @var Main */
	private $plugin;
	/** @var AuthenticateCallback */
	public $authenticate;
	/** @var MovePlayerCallback */
	public $moveplayer;
	public function __construct(Server $server, Plugin $plugin) {
		$this->server = $server;
		$this->plugin = $plugin;
		
		$this->init ();
	}
	public function init() {
		$this->register ( $this->authenticate = new AuthenticateCallback () );
		$this->register ( $this->moveplayer = new MovePlayerCallback () );
	}
	public function register($listener) {
		$this->server->getPluginManager ()->registerEvents ( $listener, $this->plugin );
	}
}

?>