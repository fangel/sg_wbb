<?php

/**
 * SG_WBB is a class containing a lot of useful static functions for 
 * dealing with Paul Reinheimers Web Bot Battle
 *
 * Just register a turn handler function that takes a SG_WBB_Bot as its 
 * single argument 
 *
 * @author Morten Fangel (C) 2008
 * @license Creative Commons Share-Alike Attribution
 */
class SG_WBB {
	const SG_WBB_VERSION = '0.3b';
	
	const LL_NONE = 0;
	const LL_DEBUG = 1;
	const LL_NOTICE = 2;
	const LL_ERROR = 4;
	const LL_USER = 8;
	const LL_WS = 16;
	const LL_ALL = 31; // All of the LL_* consts added.
	
	protected static $turnHandler = null;
	protected static $key = '';
	protected static $botVersion = '0.0';
	protected static $gameId = '';
	protected static $callType = false;
	protected static $bot = null;
	protected static $useErrorHandler = true;
	protected static $logLevel = SG_WBB::LL_ALL;

	/**
	 * Sets the key that the Bot functions under
	 * @param string $key
	 */
	public static function setKey( $key ) {
		self::$key = $key;
	}
	
	/**
	 * Returns the key that the Bot functions under
	 * @return string
	 */
	public static function getKey() {
		return self::$key;
	}
	
	/**
	 * Gets the game id
	 * @return string
	 */
	public static function getGameId() {
		return self::$gameId;
	}
	
	/**
	 * Returns the bot
	 * @return SG_WBB_Bot
	 */
	public static function getBot() {
		return self::$bot;
	}
	
	/**
	 * Sets the bot version (echo'ed during INIT)
	 * @param string $version
	 */
	public static function setBotVersion( $version ) {
		self::$botVersion = $version;
	}
	
	/**
	 * Sets the turn handler
	 * @param callable $turn_handler
	 */
	public static function setTurnHandler( $turn_handler ) {
		if( is_callable( $turn_handler ) ) {
			self::$turnHandler = $turn_handler;
		}
	}
	
	/**
	 * Sets wether or not mlog should be used as the error handler for php
	 * @param bool $use_eh
	 */
	public static function setUseErrorHandler( $use_eh ) {
		self::$useErrorHandler = (bool) $use_eh;
	}
	
	/**
	 * Sets which log-levels should be make its way into the mlog
	 * Uses a bitmask of the SG_WBB::LL_* consts.
	 * @param int $log_level
	 */
	public static function setLogLevel( $log_level ) {
		self::$logLevel = (int) $log_level;
	}
	
	/**
	 * "Starts" the bot, loads the relevant data and calles
	 * the turn handler
	 */
	public static function takeTurn() {
		if( self::$useErrorHandler ) {
			set_error_handler( array('SG_WBB', 'errorLogger'));
		}
		
		if( isset($_GET['displaySource']) || isset($_GET['describe'])) {
			self::displaySource();
		}
		
		$state = self::getState();
		
		SG_WBB::mlog('GET: ' . http_build_query($_GET), SG_WBB::LL_DEBUG);
		SG_WBB::mlog('Info: ' . 
			' key: ' . self::getKey() . 
			' gameId: ' . self::getGameId() . 
			' callType: ' . self::$callType . 
			' turnHandler: ' . self::$turnHandler
			, SG_WBB::LL_DEBUG
		);
		
		switch( self::$callType ) {
			case 'gameInit':
				$state = array();
				
				// TODO: Parse incoming cost values
				// Example:
				// GET: callType=gameInit
				//		&gameID=xxxx
				//		&serverURL=http%3A%2F%2Fexample.preinheimer.com%2Fwbb%2Farena.php
				//		&scanRange=300
				//		&scanDegrees=10
				//		&scanCost=7
				//		&driveCost=1
				//		&fireWidth=2
				//		&fireRange=300
				//		&fireBaseCost=0
				//		&serverKey=xxxxx
				
				
				echo self::$botVersion . '-' . self::SG_WBB_VERSION;
				break;
			case 'round':
				self::$bot = SG_WBB_Bot::spawnBot( $state );
			
				SG_WBB::mlog('Bot: ' . print_r(self::$bot, true), SG_WBB::LL_DEBUG);
				
				call_user_func( self::$turnHandler, self::$bot );				
				$state = self::$bot->getState();
				break;
			case 'death':
				SG_WBB::mlog('Bot died, removing state file', SG_WBB::LL_DEBUG);
				$file = self::getTempFile('wbb-' . self::getGameId() . '-' . self::getKey() . '.state.txt');
				if( file_exists( $file ) ) {
					unlink( $file );
				}
				break;
			default:
				throw new Exception('Failed to understand call type');
		}
		
		SG_WBB::mlog("\n", SG_WBB::LL_DEBUG);
		
		$succ = self::setState($state);
		if( ! $succ ) {
			throw new Exception('Failed to save state');
		}
	}
	
	/**
	 * Calls a method on the server hosting the game
	 * @return string
	 */
	public static function callServer( $method, array $uparams ) {
		$params = array();
		$params['method'] = $method;
		$params['clientKey'] = self::getKey();
		$params['gameID'] = self::getGameId();
		$params += $uparams;
		
		$url = $_GET['url'] . "?" . http_build_query($params);
		SG_WBB::mlog('Call WS: ' . $url, SG_WBB::LL_WS);
		$response = file_get_contents($url);
		SG_WBB::mlog('WS response: ' . $response, SG_WBB::LL_WS);
		return $response;
	}
	
	/**
	 * Saves a message on a local log on the server
	 *
	 * If the constant SG_WBB_USE_STDOUT is set to true, stdout will be 
	 * used instead instead of the mlog file
	 *
	 * @param string $message
	 * @param int $log_level
	 * @return bool
	 */
	public static function mlog( $message, $log_level = 1 ) {
		if( self::$logLevel & $log_level ) {
			$file = self::getTempFile('wbb-' . self::getGameId() . '-' . self::getKey() . '.mlog.txt');
			if( !$file ) {
				return false;
			}
			
			if( defined('SG_WBB_USE_STDOUT') && SG_WBB_USE_STDOUT ) {
				$file = 'php://stdout';
			}			
		
			return file_put_contents($file, $message . "\n", FILE_APPEND);
		}
	}
	
	/**
	 * A simple error logger used for piping phps errors into mlog
	 */
	public static function errorLogger($errno, $errstr, $errfile, $errline) {
	    SG_WBB::mlog('ERROR: ' . $errno . ', ' . $errstr . ' . in ' . $errfile . ' (' . $errline . ')', SG_WBB::LL_ERROR);
	}
	
	/**
	 * Loads the state that the bot is in
	 * @return array
	 */
	protected static function getState() {
		if( ! isset($_GET['serverKey']) || $_GET['serverKey'] != self::$key ) {
			throw new Exception("Invalid key");
		}
		if( ! isset($_GET['gameID']) ) {
			throw new Exception("No game id found");
		}
		if( ! isset($_GET['callType']) ) {
			throw new Exception("No call type found");
		}
		
		self::$gameId = $_GET['gameID'];
		self::$callType = $_GET['callType'];
		
		$file = self::getTempFile('wbb-' . self::getGameId() . '-' . self::getKey() . '.state.txt');
		if( ! file_exists($file) ) {
			return null;
		}
		
		$cont = file_get_contents($file);
		if( !$cont ) {
			return null;
		}
		
		$state = unserialize($cont);
		if( $state === false ) {
			return null;
		}
		
		return $state;
	}
	
	/**
	 * Sets the state - that is, stores it to the servers filesystem
	 * @param array $state
	 */
	protected static function setState( $state ) {
		SG_WBB::mlog('setState: ' . var_export($state, true), SG_WBB::LL_DEBUG);
		$file = self::getTempFile('wbb-' . self::getGameId() . '-' . self::getKey() . '.state.txt');
		
		$succ = file_put_contents($file, serialize($state) );
		return $succ;
	}
	
	/**
	 * Returns a filepath to a file called $name in a temp directory
	 * @TODO Ensure that the file is actually writable
	 * @TODO If sys_get_temp_dir doesnt exist, dont always use /tmp/
	 * @return string|false filename
	 */
	protected static function getTempFile( $name ) {
		if( function_exists('sys_get_temp_dir') ) {
			$dir = sys_get_temp_dir();
	    	if($dir[strlen($dir) - 1] != '/') {
	        	$dir .= '/';
	    	}
		} else {
			$dir = '/tmp/';
		}
	
		$file = $dir . $name;
		return $file;
	}
	
	protected static function displaySource() {
		$source = file_get_contents( $_SERVER['SCRIPT_FILENAME']);
	    $source = str_replace(self::$key, "########################", $source);
		echo '<h1>' . basename( $_SERVER['SCRIPT_FILENAME']) . ' (v' . self::$botVersion . ')</h1>';
	    highlight_string($source);
		echo '<h1>' . basename(__FILE__) . ' (v' . self::SG_WBB_VERSION . ')</h1>';
		echo '<p>The latest version of SG_WBB can be found at ';
		echo '<a href="http://github.com/fangel/sg_wbb/tree/master">http://github.com/fangel/sg_wbb/tree/master</a>';
		echo '</p>';
		highlight_file(__FILE__);
	    exit;
	}
}

/**
 * SG_WBB_Bot is representing your bot in the battle
 * If you need to store variables that are saved from turn to turn,
 * then just set them like $bot->var, and they will be handled by the
 * get and set functions, and save to disk between turns
 * 
 * @author Morten Fangel (C) 2008
 * @license Creative Commons Share-Alike Attribution
 */
class SG_WBB_Bot {
	protected $state;
	protected $pos_x;
	protected $pos_y;
	protected $energy;
	protected $armor;
	
	/**
	 * Returns a new SG_WBB_Bot with the info in $state
	 * and present in _GET
	 * @param array $state
	 * @return SG_WBB_Bot
	 */
	public static function spawnBot( array $state ) {
		$bot = new SG_WBB_Bot();
		$bot->state = $state;
		$bot->pos_x = (int) $_GET['x'];
		$bot->pos_y = (int) $_GET['y'];
		$bot->energy = (int) $_GET['energy'];
		$bot->armor = (int) $_GET['armor'];
		
		return $bot;
	}
	
	/**
	 * Fires a shot
	 * @param int $energy How much force to shoot with
	 * @param int $angle Which direction to fire
	 * @return int Bots hit
	 */
	public function fire( $angle, $energy ) {
		$energy = min( $energy, $this->energy );
		$cont = SG_WBB::callServer('fire', array('energy' => $energy, 'degree' => $angle));
		$this->energy -= $energy;
		
		$xml = simplexml_load_string($cont);
		return (int) $xml->responseValues->botsHit;
	}
	
	/**
	 * Moves towards $to_x, $to_y
	 * @param int $to_x
	 * @param int $to_y
	 */
	public function drive( $to_x, $to_y ) {
		$dir = $this->drivingDirections( $to_x, $to_y );
		$dir['distance'] = min($dir['distance'], $this->energy);
		
		$cont = SG_WBB::callServer('drive', $dir);
		
		$this->energy -= $dir['distance'];
		
		switch( $dir['direction'] ) {
			case 1:
				$this->pos_x -= $dir['distance'];
				break;
			case 2:
				$this->pos_y -= $dir['distance'];
				break;	
			case 3:
				$this->pos_x += $dir['distance'];
				break;
			case 4:
				$this->pos_y += $dir['distance'];
				break;
		}
		
		if( 0 < $this->energy && !($this->pos_x == $to_x && $this->pos_y == $to_y) ) {
			$this->drive($to_x, $to_y);
		}
	}
	
	/**
	 * Scans for targets in the direction of $angle
	 * Returns false if nothing found, and a array of
	 * angle-direction tuples if anything was found
	 * @param int $angle
	 * @return array|bool
	 */
	public function scan( $angle ) {
		$cont = SG_WBB::callServer('scan', array('degree' => $angle));
		$this->energy -= 7;
		
		SG_WBB::mlog('SCAN: ' . $cont, SG_WBB::LL_WS);
		
		$xml = simplexml_load_string($cont);
		if( $xml->responseValues->hits > 0 ) {
			$targets = array();
			foreach($xml->responseValues->coords->bot AS $hit) {
				$angle = (float) $hit->angle;
				$distance = (float) $hit->distance;
				$condition = (int) $hit->condition;
				$targets[] = SG_WBB_Target::spawnTarget( $angle, $distance, $condition );
			}
			return $targets;
		}
		return false;
	}
	
	/**
	 * Returns a array with x and y position of the bot
	 * @return array
	 */
	public function getPosition() {
		return array('x' => $this->pos_x, 'y' => $this->pos_y);
	}
	
	/**
	 * Returns the energy of the bot
	 * @return int
	 */
	public function getEnergy() {
		return $this->energy;
	}
	
	/**
	 * Returns the armor of the bot
	 * @return int
	 */
	public function getArmor() {
		return $this->armor;
	}
	
	/**
	 * Returns the "state" of the bot - that is, all user set variables
	 * @return array
	 */
	public function getState() {
		return $this->state;
	}
	
	public function __get( $var ) {
		if( isset($this->state[$var]) ) {
			return $this->state[$var];
		}
		return false;
	}
	
	public function __set( $var, $val ) {
		$this->state[$var] = $val;
	}
	
	public function __isset( $var ) {
		return isset($this->state[$var]);
	}
	
	/**
	 * This function returns the driving diretions to get to x, y from
	 * the current positition
	 * Adapted from Paul Reinheimers original function found at 
	 * http://example.preinheimer.com/wbb/
	 * 
	 * @param int $x X co-ordinate of desired location
	 * @param int $y Y co-ordinate of desired location
	 * @author Paul Reinheimer
	 * @return array 
	 */
	private function drivingDirections($x, $y) {
		//Determine which direction to drive in, the one we have the furthest to go for
		if (abs($this->pos_x - $x) > abs($this->pos_y > $y)) {
			//We've got further to go along the X axis
			if ($this->pos_x > $x) {
				//too far to the right
				$direction = 1;
			} else {
				//We're too far to the left
				$direction = 3;
			}
			$distance = abs($this->pos_x - $x);
		} else {
			//We've got further to go along the y axis
			if ($this->pos_y > $y) {
				//too high
				$direction = 4;
			} else {
				$direction = 2;
			}
			$distance = abs($this->pos_y - $y);
		}
		return array('direction'=>$direction, 'distance'=>$distance);
	}
}

/**
 * SG_WBB_Target represents a target
 *
 * Will store the target by its position. This is calculated from your
 * bots current position and the angle and distance to the target
 * 
 * Angle and direction can then be recalculated when your bot moves
 *
 * @author Morten Fangel (C) 2008
 * @license Creative Commons Share-Alike Contribution
 */
class SG_WBB_Target {
	private $pos_x;
	private $pos_y;
	private $condition;
	
	/**
	 * Creates a new target object.
	 * @param int $angel
	 * @param int $distance
	 * @param int $condition
	 * @return SG_WBB_Target
	 */
	public static function spawnTarget( $angle, $distance, $condition ) {
		$bot_pos = SG_WBB::getBot()->getPosition();
				
		$target = new SG_WBB_Target();
		$target->pos_x = round($bot_pos['x'] + cos( deg2rad($angle) ) * $distance);
		$target->pos_y = round($bot_pos['y'] + sin( deg2rad($angle) ) * $distance);
		$target->condition = $condition;
		
		$msg = '';
		$msg .= 'Calc: (' . $target->pos_x . ', ' . $target->pos_y . ')' . "\n";
		$msg .= "\t" . 'Calc: ' . $target->getAngle() . ', ' . $target->getDistance() . "\n";
		$msg .= "\t" . 'Expt: ' . $angle . ', ' . $distance . "\n";
		
		SG_WBB::mlog('TARGET: ' . $msg, SG_WBB::LL_DEBUG);
		
		return $target;
	}
	
	/**
	 * Returns the position of this target
	 * @return array x and y
	 */
	public function getPosition() {
		return array('x' => $this->pos_x, 'y' => $this->pos_y);
	}
	
	/**
	 * Returns the angle to this target
	 *
	 * Adapted from Paul Reinheimers calculateAngle found at
	 * http://example.preinheimer.com/wbb/
	 *
	 * @return int (float?)
	 */
	public function getAngle() {
		$this_pos = $this->getPosition();
		$bot_pos = SG_WBB::getBot()->getPosition();
		
		$angle = atan2(($this_pos['y']-$bot_pos['y']),($this_pos['x']-$bot_pos['x'])) * (180/M_PI);
	
		$angle = ($angle < 0) ? $angle + 360 : $angle;
	
		return $angle;
	}
	
	/**
	 * Returns the distance to this bot
	 *
	 * Adapted from Paul Reinheimers calculateAngle found at
	 * http://example.preinheimer.com/wbb/
	 *
	 * @return int (float?)
	 */
	public function getDistance() {
		$this_pos = $this->getPosition();
		$bot_pos = SG_WBB::getBot()->getPosition();
	
		$distance = sqrt(pow(($this_pos['x']-$bot_pos['x']),2) + pow(($this_pos['y']-$bot_pos['y']),2));
		
		return $distance;
	}
	
	/**
	 * Returns the condition of the target. Higher is more health.
	 * @return int
	 */
	public function getCondition() {
		return $this->condition;
	}
}
