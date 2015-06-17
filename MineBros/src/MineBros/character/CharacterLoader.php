<?php

namespace MineBros\character;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\math\Vector3;
use pocketmine\Player;
use MineBros\Main;

class CharacterLoader implements Listener {

    public $nameDict;
    public $passiveTickSubscribers = array();

    private $characters = array();
    private $owner;

    public function __construct(Main $owner){
        $this->owner = $owner;
    }

    public function chooseRandomCharacter(Player $forWhom, $notify = false){
        $keys = array_keys($this->characters);
        $character = $this->characters[$keys[mt_rand(0, count($this->characters)-1)]];
        $this->nameDict[$forWhom->getName()] = $character->getName();
        if($notify) $forWhom->sendMessage(\pocketmine\utils\TextFormat::YELLOW.'[MineBros] '.\pocketmine\utils\TextFormat::WHITE.'능력이 설정되었습니다. /mi help로 확인해보세요.');
        return $character;
    }

    public function chooseCharacter(Player $forWhom, $name){
        if(!isset($this->characters[$name])) return false;
        $this->nameDict[$forWhom->getName()] = $this->characters[$name]->getName();
    }

    public function reset(){
        $this->nameDict = array();
    }

    public function registerCharacter(BaseCharacter $character){
        if(isset($this->characters[$character->getName()])){
            $owner->getLogger()->warning("[MineBros] Oops: Duplicated name detected while registering character");
            return false;
        }
        $this->characters[$character->getName()] = $character;
        if($character->getOptions() & BaseCharacter::TRIGR_PASIV) $this->passiveTickSubscribers[] = $character->getName();
        $character->init();
    }

    public function loadFromDirectory($path){
        foreach(scandir($path) as $p){
            if(substr($p, -4, 4) === '.php'){
                include($p);
                if(class_exists($classname = substr($p, 0, -4))) $this->registerCharacter(new $classname());
            }
        }
    }

    public function onBlockTouch(PlayerInteractEvent $ev){
        if($ev->getPlayer()->getInventory()->getItemInHand()->getId() !== 265) return; //Iron ingot
        if(!isset($this->nameDict[$ev->getPlayer()->getName()])) return;
        if($this->nameDict[$ev->getPlayer()->getName()]->getOptions() & BaseCharacter::TRIGR_TOUCH){
            $ev->setCancelled();
            $this->nameDict[$ev->getEntity()->getName()]->onTouchAnything($ev->getPlayer(), false, $ev->getTouchVector());
        }
    }

    public function onPlayerTouch(EntityDamageByEntityEvent $ev){
        if(!($ev->getEntity() instanceof Player and $ev->getDamager() instanceof Player)
          or $ev->getPlayer()->getInventory()->getItemInHand()->getId() !== 265
          or !isset($this->nameDict[$ev->getEntity()->getName()])) return;
        if($this->nameDict[$ev->getEntity()->getName()]->getOptions() & BaseCharacter::TRIGR_TOUCH){
            $ev->setCancelled();
            $entity = $ev->getEntity();
            $this->nameDict[$ev->getEntity()->getName()]->onTouchAnything($ev->getEntity(), true, new Vector3($entity->x, $entity->y, $entity->z), $ev->getEntity());
        }
        if($this->nameDict[$ev->getEntity()->getName()]->getOptions() & BaseCharacter::TRIGR_PONLY){
            $ev->setCancelled();
            $this->nameDict[$ev->getEntity()->getName()]->onHarmPlayer($ev->getEntity(), $ev->getDamager(), $ev->getCause());
        }
    }

    public function onPassiveTick($currentTick){
        foreach($this->passiveTickSubscribers as $s){
            foreach(array_keys($this->nameDict, $s) as $a){
                $player = $this->owner->getServer()->getPlayerExact($a);
                if($player === NULL) return;
                    else $this->characters[$s]->onPassiveTick($player, $currentTick);
            }
        }
    }

}
