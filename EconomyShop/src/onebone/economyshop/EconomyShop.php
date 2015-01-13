<?php

namespace onebone\economyshop;

use pocketmine\event\block\BlockBreakEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\tile\Sign;
use pocketmine\utils\Config;
use pocketmine\item\Item;
use pocketmine\event\block\BlockPlaceEvent;

use onebone\economyapi\EconomyAPI;

class EconomyShop extends PluginBase implements Listener{

	/**
	 * @var array
	 */
	private $shop;

	/**
	 * @var Config
	 */
	private $shopSign;

	/**
	 * @var Config
	 */
	private $lang;

	private $placeQueue;

	/**
	 * @var EconomyShop
	 */
	private static $instance;

	public function onEnable(){
		if(self::$instance instanceof EconomyShop){
			return;
		}
		self::$instance = $this;
		if(!file_exists($this->getDataFolder())){
			mkdir($this->getDataFolder());	
		}
		$this->shop = (new Config($this->getDataFolder()."Shops.yml", Config::YAML))->getAll();
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->prepareLangPref();
		$this->placeQueue = array();
		
		ItemList::$items = (new Config($this->getDataFolder()."items.properties", Config::PROPERTIES, ItemList::$items))->getAll();
	}

	public function getShops(){
		return $this->shop;
	}

	/**
	 * @param string $locationIndex
	 * @param float|null $price
	 * @param int|null $amount
	 *
	 * @return bool
	 */
	public function editShop($locationIndex, $price = null, $amount = null){
		if(isset($this->shop[$locationIndex])){
			$price = ($price === null) ? $this->shop[$locationIndex]["price"]: $price;
			$amount = ($amount === null) ? $this->shop[$locationIndex]["amount"]:$amount;

			$location = explode(":", $locationIndex);
			$tile = $this->getServer()->getLevelByName($location[3]);
			if($tile instanceof Sign){
				$tag = $tile->getText()[0];
				$data = [];
				foreach($this->shopSign->getAll() as $value){
					if($value[0] == $tag){
						$data = $value;
						break;
					}
				}
				$tile->setText(
					$data[0],
					str_replace("%1", $price, $data[1]),
					$tile->getText()[2],
					str_replace("%3", $amount, $data[3])
				);
			}

			save:
			$this->shop[$locationIndex] = [
				"x" => (int)$location[0],
				"y" => (int)$location[1],
				"z" => (int)$location[2],
				"level" => $location[3],
				"price" => $price,
				"item" => $this->shop[$locationIndex]["item"],
				"meta" => $this->shop[$locationIndex]["meta"],
				"amount" => $amount
			];
			return true;
		}
		return false;
	}

	/**
	 * @return EconomyShop
	 */
	public static function getInstance(){
		return self::$instance;
	}

	public function prepareLangPref(){
		$this->saveResource("language.properties");
		$this->saveResource("ShopText.yml");
		$this->lang = new Config($this->getDataFolder()."language.properties", Config::PROPERTIES);
		$this->shopSign = new Config($this->getDataFolder()."ShopText.yml", Config::YAML);
	}
	
	public function onDisable(){
		$config = (new Config($this->getDataFolder()."Shops.yml", Config::YAML));
		$config->setAll($this->shop);
		$config->save();
	}

	public function tagExists($tag){
		foreach($this->shopSign->getAll() as $key => $val){
			if($tag == $key){
				return $val;
			}
		}
		return false;
	}

	public function getItem($item){ // gets ItemID and ItemName
		$item = strtolower($item);
		$e = explode(":", $item);
		$e[1] = isset($e[1]) ? $e[1] : 0;
		if(array_key_exists($item, ItemList::$items)){
			return array(ItemList::$items[$item], true); // Returns Item ID
		}else{
			foreach(ItemList::$items as $name => $id){
				$explode = explode(":", $id);
				$explode[1] = isset($explode[1]) ? $explode[1]:0;
				if($explode[0] == $e[0] and $explode[1] == $e[1]){
					return array($name, false);
				}
			}
		}
		return false;
	}

	public function getMessage($key, $val = array("%1", "%2", "%3")){
		if($this->lang->exists($key)){
			return str_replace(array("%1", "%2", "%3"), array($val[0], $val[1], $val[2]), $this->lang->get($key));
		}
		return "There are no message which has key \"$key\"";
	}

	public function onSignChange(SignChangeEvent $event){
		$result = $this->tagExists($event->getLine(0));
		if($result !== false){
			$player = $event->getPlayer();
			if(!$player->hasPermission("economyshop.shop.create")){
				$player->sendMessage($this->getMessage("no-permission-create"));
				return;
			}
			if(!is_numeric($event->getLine(1)) or !is_numeric($event->getLine(3))){
				$player->sendMessage($this->getMessage("wrong-format"));
				return;
			}

			// Item identify
			$item = $this->getItem($event->getLine(2));
			if($item === false){
				$player->sendMessage($this->getMessage("item-not-support", array($event->getLine(2), "", "")));
				return;
			}
			if($item[1] === false){ // Item name found
				$id = explode(":", strtolower($event->getLine(2)));
				$event->setLine(2, $item[0]);
			}else{
				$tmp = $this->getItem(strtolower($event->getLine(2)));
				$id = explode(":", $tmp[0]);
			}
			$id[0] = (int)$id[0];
			if(!isset($id[1])){
				$id[1] = 0;
			}
			// Item identify end

			$block = $event->getBlock();
			$this->shop[$block->getX().":".$block->getY().":".$block->getZ().":".$block->getLevel()->getFolderName()] = array(
				"x" => $block->getX(),
				"y" => $block->getY(),
				"z" => $block->getZ(),
				"level" => $block->getLevel()->getFolderName(),
				"price" => (int) $event->getLine(1),
				"item" => (int) $id[0],
				"meta" => (int) $id[1],
				"amount" => (int) $event->getLine(3)
			);

			$player->sendMessage($this->getMessage("shop-created", array($id[0], $id[1], $event->getLine(1))));

			$event->setLine(0, $result[0]); // TAG
			$event->setLine(1, str_replace("%1", $event->getLine(1), $result[1])); // PRICE
			$event->setLine(2, str_replace("%2", $event->getLine(2), $result[2])); // ID AND DAMAGE
			$event->setLine(3, str_replace("%3", $event->getLine(3), $result[3])); // AMOUNT
		}
	}

	public function onPlayerTouch(PlayerInteractEvent $event){
		$block = $event->getBlock();
		if(isset($this->shop[$block->getX().":".$block->getY().":".$block->getZ().":".$block->getLevel()->getFolderName()])){
			$shop = $this->shop[$block->getX().":".$block->getY().":".$block->getZ().":".$block->getLevel()->getFolderName()];
			$player = $event->getPlayer();
			$money = EconomyAPI::getInstance()->myMoney($player);
			if($shop["price"] > $money){
				$player->sendMessage("[EconomyShop] You don't have enough money to buy ".($shop["item"].":".$shop["meta"])." ($$shop[price])");
				$event->setCancelled(true);
				if($event->getItem()->isPlaceable()){
					$this->placeQueue[$player->getName()] = true;
				}
				return;
			}else{
				$player->getInventory()->addItem(new Item($shop["item"], $shop["meta"], $shop["amount"]));
				EconomyAPI::getInstance()->reduceMoney($player, $shop["price"], true, "EconomyShop");
				$player->sendMessage("[EconomyShop] You have bought $shop[item]:$shop[meta] ($$shop[price])");
				$event->setCancelled(true);
				if($event->getItem()->isPlaceable()){
					$this->placeQueue[$player->getName()] = true;
				}
			}
		}
	}

	public function onBreakEvent(BlockBreakEvent $event){
		$block = $event->getBlock();
		if(isset($this->shop[$block->getX().":".$block->getY().":".$block->getZ().":".$block->getLevel()->getFolderName()])){
			$player = $event->getPlayer();
			if(!$player->hasPermission("economyshop.shop.remove")){
				$player->sendMessage($this->getMessage("no-permission-break"));
				$event->setCancelled(true);
				return;
			}
			$this->shop[$block->getX().":".$block->getY().":".$block->getZ().":".$block->getLevel()->getFolderName()] = null;
			unset($this->shop[$block->getX().":".$block->getY().":".$block->getZ().":".$block->getLevel()->getFolderName()]);
			$player->sendMessage($this->getMessage("removed-shop"));
		}
	}

	public function onPlaceEvent(BlockPlaceEvent $event){
		$username = $event->getPlayer()->getName();
		if(isset($this->placeQueue[$username])){
			$event->setCancelled(true);
			unset($this->placeQueue[$username]);
		}
	}
}
