<?php

/*
 * Copyright 2015 ChalkPE
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * @author ChalkPE
 * @since 2015-04-18 17:17
 * @license Apache-2.0
 */

namespace chalk\vipplus;

use chalk\utils\Messages;
use onebone\economyapi\EconomyAPI;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\PluginCommand;
use pocketmine\entity\Arrow;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityShootBowEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\item\Item;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;

class VIPPlus extends PluginBase implements Listener {
    /** @var VIPPlus */
    private static $instance = null;

    /** @var VIP[] */
    private $vips = [];

    /** @var Messages */
    private $messages;

    /** @var Item[] */
    private $armorContents = [];

    /** @var string */
    private $prefix = "";

    /** @var string */
    private $colorFormat = "";

    /** @var array */
    private $arrowQueue = [];

    /* ====================================================================================================================== *
     *                         Below methods are plugin implementation part. Please do not call them!                         *
     * ====================================================================================================================== */

    public function onLoad(){
        VIPPlus::$instance = $this;
    }

    public function onEnable(){
        $this->loadConfig();
        $this->loadVips();
        $this->loadMessages();

        $this->registerAll();
    }

    public function onDisable(){
        $this->saveVips();
    }

    private function registerAll(){
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->registerCommand("vip-heal");
        $this->registerCommand("vip-inferno");
    }

    /**
     * @param string $name
     */
    private function registerCommand($name){
        $command = new PluginCommand($this->getMessages()->getMessage($name . "-command-name"), $this);
        $command->setUsage($command->getName());
        $command->setDescription($this->getMessages()->getMessage($name . "-command-description"));

        $this->getServer()->getCommandMap()->register("VIPPlus", $command);
    }

    public function loadConfig(){
        @mkdir($this->getDataFolder());
        $this->saveDefaultConfig();

        $this->armorContents = [];
        foreach($this->getConfig()->get("vip-armor-contents", []) as $index => $itemId){
            $this->armorContents[$index] = Item::get($itemId);
        }

        $this->prefix = $this->getConfig()->get("vip-prefix", "");
        $this->colorFormat = $this->getConfig()->get("vip-color-format", "");
    }

    /**
     * @param bool $override
     */
    public function loadVips($override = true){
        $vipsConfig = new Config($this->getDataFolder() . "vips.json", Config::JSON);
        if($override){
            $this->vips = [];
        }

        foreach($vipsConfig->getAll() as $index => $data){
            $this->vips[] = VIP::createFromArray($index, $data);
        }
    }

    /**
     * @return bool
     */
    public function saveVips(){
        $vipsConfig = new Config($this->getDataFolder() . "vips.json", Config::JSON);
        $vips = [];

        foreach($this->getVips() as $vip){
            $vips[$vip->getName()] = $vip->toArray();
        }

        $vipsConfig->setAll($vips);
        return $vipsConfig->save();
    }

    /**
     * @param bool $override
     */
    public function loadMessages($override = false){
        $this->saveResource("messages.yml", $override);

        $messagesConfig = new Config($this->getDataFolder() . "messages.yml", Config::YAML);
        $this->messages = new Messages($messagesConfig->getAll());
    }

    /**
     * @return Messages
     */
    public function getMessages(){
        return $this->messages;
    }

    /* ====================================================================================================================== *
     *                                     Below methods are API part. You can call them!                                     *
     * ====================================================================================================================== */

    /**
     * @return VIPPlus
     */
    public static function getInstance(){
        return VIPPlus::$instance;
    }

    /**
     * @return VIP[]
     */
    public function getVips(){
        return $this->vips;
    }

    /**
     * @return VIP[]
     */
    public function getOnlineVips(){
        return array_filter($this->getVips(), function(VIP $vip){
            return $vip->getPlayer() !== null;
        });
    }

    /**
     * @param string|Player|VIP $name
     * @return string
     */
    private static function validateName($name){
        if($name instanceof Player or $name instanceof VIP){
            $name = $name->getName();
        }

        return strToLower($name);
    }

    /**
     * @param string|Player|VIP $name
     * @return int
     */
    private function indexOfVip($name){
        $name = VIPPlus::validateName($name);

        foreach($this->getVips() as $index => $vip){
            if($name === $vip->getName()){
                return $index;
            }
        }
        return -1;
    }

    /**
     * @param string|Player|VIP $name
     * @return VIP|null
     */
    public function getVip($name){
        $name = VIPPlus::validateName($name);

        $index = $this->indexOfVip($name);
        return $index >= 0 ? $this->getVips()[$index] : null;
    }

    /**
     * @param string|Player|VIP $name
     * @return bool
     */
    public function isVip($name){
        $name = VIPPlus::validateName($name);

        return $this->getVip($name) !== null;
    }

    /**
     * @param string|Player|VIP $name
     * @return null|string
     */
    public function addVip($name){
        $name = VIPPlus::validateName($name);

        if($this->isVip($name)){
            return $this->getMessages()->getMessage("vip-already-vip", [$name]);
        }
        $this->getVips()[] = $name;
        $this->saveVips();

        $gratuityAmount = $this->getConfig()->get("vip-gratuity-amount", 0);
        if($gratuityAmount > 0){
            EconomyAPI::getInstance()->addMoney($name, $gratuityAmount, true, "VIPPlus");
        }

        return $this->getMessages()->getMessage("vip-added", [$name]);
    }

    /**
     * @param string|Player|VIP $name
     * @return null|string
     */
    public function removeVip($name){
        $name = VIPPlus::validateName($name);

        $vip = $this->getVip($name);
        if($vip === null){
            return $this->getMessages()->getMessage("vip-not-vip", [$name]);
        }
        unset($vip);
        $this->saveVips();

        return $this->getMessages()->getMessage("vip-removed", [$name]);
    }

    /* ====================================================================================================================== *
     *                                Below methods are non-API part. Please do not call them!                                *
     * ====================================================================================================================== */

    /**
     * @param CommandSender $sender
     * @param Command $command
     * @param string $commandAlias
     * @param array $args
     * @return bool
     */
    public function onCommand(CommandSender $sender, Command $command, $commandAlias, array $args){
        if($command->getName() === "vip"){
            if(!$sender->hasPermission("vip.manage") or $sender instanceof Player){
                return false;
            }

            if(!is_array($args) or count($args) < 2 or !is_string($args[1])){
                $sender->sendMessage($this->getCommand("vip")->getUsage());
                return true;
            }

            $playerName = VIPPlus::validateName($args[1]);

            switch($args[0]){
                default:
                    $sender->sendMessage($this->getCommand("vip")->getUsage());
                    break;

                case "list":
                    $sender->sendMessage($this->getMessages()->getMessage("vip-list-info", [count($this->getOnlineVips()), count($this->getVips()), implode(", ", $this->vips)]));
                    break;

                case "add":
                    $sender->sendMessage($this->addVip($playerName));
                    break;

                case "remove":
                    $sender->sendMessage($this->removeVip($playerName));
                    break;
            }
        }else if($sender->hasPermission("vip.use") and ($vip = $this->getVip($sender)) !== null){
            $this->onVipCommand($vip, $command);
            return true;
        }
        return true;
    }

    /**
     * @param VIP $vip
     * @param Command $command
     */
    public function onVipCommand(VIP $vip, Command $command){
        $function = null;

        switch($command->getName()){
            case $this->getMessages()->getMessage("vip-heal-command-name"):
                $function = function(Player $player){
                    $player->setOnFire(10);
                };
                break;

            case $this->getMessages()->getMessage("vip-inferno-command-name"):
                $function = function(Player $player){
                    $player->setHealth(20);
                };
                break;
        }

        if($function !== null){
            foreach($vip->getNearbyPlayers(16) as $player){
                $function($player);
            }
        }
    }

    public function onVipJoin(PlayerJoinEvent $event){
        $vip = $this->getVip($event->getPlayer()->getName());
        if($vip === null){
            return;
        }

        $vip->setArmor($this->armorContents);
        $vip->setPrefix($this->prefix);
        
        $attachment = $event->getPlayer()->addAttachment($this);
        $attachment->setPermission("infinite.VIP", true); //Enable VIP mode for InfiniteBlock plugin
        $attachment->setPermission("Farms.VIP", true); //Enable VIP mode for Farms plugin
    }

    public function onVipChat(PlayerChatEvent $event){
        if($this->isVip($event->getPlayer()->getName()) === false){
            return;
        }

        $event->setFormat($this->colorFormat . $event->getFormat());
    }

    public function onPlayerDamagedByEntity(EntityDamageByEntityEvent $event){
        $attacker = $event->getDamager();
        $victim = $event->getEntity();

        if(!($victim instanceof Player)){
            return;
        }

        if((($vipVictim = $this->getVip($victim)) !== null and $vipVictim->refuseToPvp()) or (($vipAttacker = $this->getVip($attacker)) !== null and $vipAttacker->refuseToPvp())){
            $event->setDamage(0);
            $event->setCancelled(true);
            return;
        }

        if($attacker instanceof Arrow and ($arrowIndex = array_search($attacker->getId(), $this->arrowQueue)) !== false){
            $victim->setOnFire(10);
            unset($this->arrowQueue[$arrowIndex]);
        }
    }

    public function onVipShootBow(EntityShootBowEvent $event){
        $player = $event->getEntity();
        if(!($player instanceof Player) or ($vip = $this->getVip($player)) === null){
            return;
        }

        if($vip->refuseToPvp()){
            $event->setCancelled(true);
            return;
        }

        $this->arrowQueue[] = $event->getProjectile()->getId();
    }
}