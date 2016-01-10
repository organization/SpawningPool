<?php

namespace SpawningPool;

use pocketmine\event\Timings;
use pocketmine\Server;
use pocketmine\scheduler\AsyncTask;
use SpawningPool\task\InitialTask;

class SpawningPool {
	
	/** @var Server */
	private $server;
	
	/** @var \Pool */
	private $pool;
	/** @var AsyncTask[] */
	private $tasks = [ ];
	protected $size;
	public function __construct(Server $server, $size) {
		$this->server = $server;
		$this->size = ( int ) $size;
		$this->pool = new \Pool ( $size, SpawningWorker::class, [ 
				$this->server->getLogger () 
		] );
		for($i = 0; $i < $size; $i ++)
			$this->pool->submit ( new InitialTask () );
	}
	public function getSize() {
		return $this->size;
	}
	public function increaseSize($newSize) {
		$this->size = $newSize;
		$this->pool->resize ( $newSize );
	}
	public function submitTaskToWorker(AsyncTask $task, $worker) {
		if ($task->isGarbage ())
			return;
		
		$worker = ( int ) $worker;
		if ($worker < 0 or $worker >= $this->size)
			throw new \InvalidArgumentException ( "Invalid worker $worker" );
		
		$this->tasks [$task->getTaskId ()] = $task;
		$this->pool->submitTo ( ( int ) $worker, $task );
	}
	public function submitTask(AsyncTask $task) {
		if ($task->isGarbage ())
			return;
		
		$this->tasks [$task->getTaskId ()] = $task;
		$this->pool->submit ( $task );
	}
	private function removeTask(AsyncTask $task, $force = false) {
		unset ( $this->tasks [$task->getTaskId ()] );
	}
	public function removeTasks() {
		$this->pool->shutdown ();
	}
	public function collectTasks() {
		Timings::$schedulerAsyncTimer->startTiming ();
		
		for($i = 0; $i < 2; $i ++) {
			if (! $this->pool->collect ( function (AsyncTask $task) {
				if ($task->isGarbage () and ! $task->isRunning () and ! $task->isCrashed ()) {
					if (! $task->hasCancelledRun ()) {
						$task->onCompletion ( $this->server );
					}
					$this->removeTask ( $task );
				} elseif ($task->isTerminated () or $task->isCrashed ()) {
					$this->server->getLogger ()->critical ( "Could not execute asynchronous task " . (new \ReflectionClass ( $task ))->getShortName () . ": Task crashed" );
					$this->removeTask ( $task );
				}
				
				return $task->isGarbage ();
			} ))
				break;
		}
		Timings::$schedulerAsyncTimer->stopTiming ();
	}
}
