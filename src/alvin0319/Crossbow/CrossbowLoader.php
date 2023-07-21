<?php

declare(strict_types=1);

namespace alvin0319\Crossbow;

use alvin0319\Crossbow\item\Crossbow;
use alvin0319\Crossbow\item\ExtraVanillaItems;
use pocketmine\data\bedrock\EnchantmentIdMap;
use pocketmine\data\bedrock\EnchantmentIds;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\inventory\CreativeInventory;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\ItemFlags;
use pocketmine\item\enchantment\Rarity;
use pocketmine\item\enchantment\StringToEnchantmentParser;
use pocketmine\item\ItemUseResult;
use pocketmine\item\StringToItemParser;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ReleaseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\format\io\GlobalItemDataHandlers;

final class CrossbowLoader extends PluginBase implements Listener{

	public static array $crossbowLoadData = [];

	public function onEnable() : void{
        $itemDeserializer = GlobalItemDataHandlers::getDeserializer();
        $itemSerializer = GlobalItemDataHandlers::getSerializer();
        $creativeInventory = CreativeInventory::getInstance();
        $stringToItemParser = StringToItemParser::getInstance();

        $crossbow = ExtraVanillaItems::CROSSBOW();
        $itemDeserializer->map(ItemTypeNames::CROSSBOW, static fn() => clone $crossbow);
        $itemSerializer->map($crossbow, static fn() => new SavedItemData(ItemTypeNames::CROSSBOW));
        $creativeInventory->add($crossbow);
        $stringToItemParser->register("crossbow", static fn() => clone $crossbow);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		$enchMap = EnchantmentIdMap::getInstance();

		$enchMap->register(EnchantmentIds::MULTISHOT, $multishot = new Enchantment("Multishot", Rarity::MYTHIC, ItemFlags::BOW, ItemFlags::NONE, 1));
		$enchMap->register(EnchantmentIds::QUICK_CHARGE, $quickCharge = new Enchantment("Quick charge", Rarity::MYTHIC, ItemFlags::BOW, ItemFlags::NONE, 3));

		StringToEnchantmentParser::getInstance()->register("multishot", fn() => $multishot);
		StringToEnchantmentParser::getInstance()->register("quick_charge", fn() => $quickCharge);

		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() use ($enchMap) : void{
			foreach(self::$crossbowLoadData as $name => $bool){
				$player = $this->getServer()->getPlayerExact($name);
				if($player !== null){
					$itemInHand = $player->getInventory()->getItemInHand();
					if($itemInHand instanceof Crossbow){
						$quickCharge = $itemInHand->getEnchantmentLevel($enchMap->fromId(EnchantmentIds::QUICK_CHARGE));
						$time = $player->getItemUseDuration();
						if($time >= 24 - $quickCharge * 5){
                            $returnedItems = [];
                            $itemInHand->onReleaseUsing($player, $returnedItems);
							$player->getInventory()->setItemInHand($itemInHand);
							unset(self::$crossbowLoadData[$name]);
						}
					}else{
						unset(self::$crossbowLoadData[$name]);
					}
				}else{
					unset(self::$crossbowLoadData[$name]);
				}
			}
		}), 1);
	}

	/**
	 * @param DataPacketReceiveEvent $event
	 *
	 * @handleCancelled true
	 */
	public function onDataPacketReceive(DataPacketReceiveEvent $event) : void{
		$packet = $event->getPacket();
		if(!$packet instanceof InventoryTransactionPacket){
			return;
		}
		$player = $event->getOrigin()->getPlayer() ?: throw new AssumptionFailedError("Player is not online");
		$trData = $packet->trData;
		$conv = TypeConverter::getInstance();
        $returnedItems = [];
        switch(true){
			case $trData instanceof UseItemTransactionData:
				$item = $conv->netItemStackToCore($trData->getItemInHand()->getItemStack());
				if($item instanceof Crossbow){
					$event->cancel();
					$oldItem = clone $item;
					$ev = new PlayerItemUseEvent($player, $item, $player->getDirectionVector());
					if($player->hasItemCooldown($item) || $player->isSpectator()){
						$ev->cancel();
					}
					$ev->call();
					if($ev->isCancelled()){
						return;
					}
					if($item->onClickAir($player, $player->getDirectionVector(), $returnedItems)->equals(ItemUseResult::FAIL())){
						$player->getNetworkSession()->getInvManager()?->syncSlot($player->getInventory(), $player->getInventory()->getHeldItemIndex(), ItemStack::null());
						return;
					}
					$player->resetItemCooldown($item);
					if(!$item->equalsExact($oldItem) && $oldItem->equalsExact($player->getInventory()->getItemInHand())){
						$player->getInventory()->setItemInHand($item);
					}
					if(!$oldItem->isCharged() && !$item->isCharged()){
						$player->setUsingItem(true);
					}else{
						$player->setUsingItem(false);
					}
				}
				break;
			case $trData instanceof ReleaseItemTransactionData:
				$item = $player->getInventory()->getItemInHand();
				if($item instanceof Crossbow){
					$event->cancel();
					if(!$player->isUsingItem() || $player->hasItemCooldown($item)){
						return;
					}
					if($item->onReleaseUsing($player, $returnedItems)->equals(ItemUseResult::SUCCESS())){
						$player->resetItemCooldown($item);
						$player->getInventory()->setItemInHand($item);
					}
				}
				break;
		}
	}
}