<?php

namespace SpawningPool;

use pocketmine\utils\Utils;

class ProveWorker extends \Collectable {
	public function run() {
		$this->bcpi ();
	}
	public function bcpi() {
		$precision = 10;
		$num = 0;
		$k = 0;
		bcscale ( $precision + 3 );
		$limit = ($precision + 3) / 14;
		while ( true ) {
			$num = bcadd ( $num, bcdiv ( bcmul ( bcadd ( '13591409', bcmul ( '545140134', $k ) ), bcmul ( bcpow ( - 1, $k ), $this->bcfact ( 6 * $k ) ) ), bcmul ( bcmul ( bcpow ( '640320', 3 * $k + 1 ), bcsqrt ( '640320' ) ), bcmul ( $this->bcfact ( 3 * $k ), bcpow ( $this->bcfact ( $k ), 3 ) ) ) ) );
			++ $k;
		}
		return bcdiv ( 1, (bcmul ( 12, ($num) )), $precision );
	}
	public function bcfact($n) {
		return ($n == 0 || $n == 1) ? 1 : bcmul ( $n, $this->bcfact ( $n - 1 ) );
	}
}
class Prove {
	public function useSingleCore() {
		$this->bcpi ();
	}
	public function useMultiCore1() {
		$core = $this->getCoreCount ();
		$pool = new \Pool ( $core );
		for($i = 0; $i < $core; $i ++)
			$pool->submit ( new ProveWorker () );
	}
	public function useMultiCore2() {
		$core = $this->getCoreCount ();
		$thread = array ();
		for($i = 0; $i < $core; $i ++)
			$thread [$i] = new \Thread ( 'bcpi' );
		for($i = 0; $i < $core; $i ++)
			$thread [$i]->start ();
		while ( true ) {
			/* :D */
		}
	}
	public function getCoreCount() {
		return Utils::getCoreCount ();
	}
	public function bcpi() {
		$precision = 10;
		$num = 0;
		$k = 0;
		bcscale ( $precision + 3 );
		$limit = ($precision + 3) / 14;
		while ( true ) {
			$num = bcadd ( $num, bcdiv ( bcmul ( bcadd ( '13591409', bcmul ( '545140134', $k ) ), bcmul ( bcpow ( - 1, $k ), $this->bcfact ( 6 * $k ) ) ), bcmul ( bcmul ( bcpow ( '640320', 3 * $k + 1 ), bcsqrt ( '640320' ) ), bcmul ( $this->bcfact ( 3 * $k ), bcpow ( $this->bcfact ( $k ), 3 ) ) ) ) );
			++ $k;
		}
		return bcdiv ( 1, (bcmul ( 12, ($num) )), $precision );
	}
	public function bcfact($n) {
		return ($n == 0 || $n == 1) ? 1 : bcmul ( $n, $this->bcfact ( $n - 1 ) );
	}
}

?>