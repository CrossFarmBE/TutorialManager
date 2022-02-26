<?php

/*
       _             _____           ______ __
      | |           |  __ \         |____  / /
      | |_   _ _ __ | |  | | _____   __ / / /_
  _   | | | | | '_ \| |  | |/ _ \ \ / // / '_ \
 | |__| | |_| | | | | |__| |  __/\ V // /| (_) |
  \____/ \__,_|_| |_|_____/ \___| \_//_/  \___/


This program was produced by JunDev76 and cannot be reproduced, distributed or used without permission.

Developers:
 - JunDev76 (https://github.jundev.me/)

Copyright 2022. JunDev76. Allrights reserved.
*/

namespace JunDev76\TutorialManager;

use FormSystem\form\ButtonForm;
use FormSystem\form\CustomForm;
use JsonException;
use JunDev76\EconomySystem\EconomySystem;
use JunDev76\SkinManager\SkinManager;
use JunKR\CrossUtils;
use pocketmine\block\BlockLegacyIds;
use pocketmine\block\ItemFrame;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Location;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIds;
use pocketmine\network\mcpe\protocol\EmotePacket;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\AsyncTask;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\Position;
use pocketmine\world\World;
use ReflectionException;
use slapper\entities\SlapperHuman;

class TutorialManager extends PluginBase{

    public array $db = [];

    use SingletonTrait;

    protected function onLoad() : void{
        self::setInstance($this);
    }

    public const NPCNAME = '애밀리';
    public const MESSAGETICK = 40;

    public function removeAllWorld() : void{
        $this->getServer()->getAsyncPool()->submitTask(new class($this->getServer()->getDataPath()) extends AsyncTask{

            public function __construct(public string $datapatch){
            }

            public function onRun() : void{
                $path = $this->datapatch;
                exec("rm -rf {$path}worlds/Tutorial_*");
            }

        });
    }

    public function finish(Player $player) : void{
        $this->db[$player->getName()] = 1;
        unset($this->player_stage_index_map[$player->getName()]);
        $this->getServer()->dispatchCommand($player, '스폰');
    }

    public function copyWorld(Player $player) : void{
        if(realpath($this->getServer()->getDataPath() . 'worlds/Tutorial_' . strtolower($player->getName()))){
            $this->moveStage($player, 1);
            return;
        }

        $this->getServer()->getAsyncPool()->submitTask(new class($this->getServer()->getDataPath(), strtolower($player->getName())) extends AsyncTask{

            public function __construct(public string $datapatch, public string $username){
            }

            public function onRun() : void{
                $path = $this->datapatch;
                exec("cp -r {$path}worlds/TutorialBaseWorld {$path}worlds/Tutorial_{$this->username}");
            }

            public function onCompletion() : void{
                TutorialManager::getInstance()->copyWorldOnCompletion($this->username);
            }

        });
    }

    public function copyWorldOnCompletion(string $playerName) : void{
        $player = $this->getServer()->getPlayerExact($playerName);
        if($player === null){
            return;
        }

        $this->moveStage($player, 1);
    }

    public function getStageLocation(int $index, World $world) : ?Location{
        $index--;
        if(!isset($this->stage_map[$index])){
            return null;
        }

        $pos = explode('/', $this->stage_map[$index]);

        return new Location((float) $pos[0], (float) $pos[1], (float) $pos[2], $world, (float) $pos[3], (float) $pos[4]);
    }

    public function sendStageTitle(Player $player, int $index) : void{
        $index--;
        if(!isset($this->stage_welcome_titles[$index])){
            return;
        }
        $title = $this->stage_welcome_titles[$index];
        $player->sendTitle(($title[0] ?? ''), ($title[1] ?? ''), 5, 40, 5);
    }

    public function getStageWorld(Player $player) : ?World{
        $loaded = $this->getServer()->getWorldManager()->loadWorld('Tutorial_' . strtolower($player->getName()));
        if(!$loaded){
            $this->copyWorld($player);
            return null;
        }

        return $this->getServer()->getWorldManager()->getWorldByName('Tutorial_' . strtolower($player->getName()));
    }

    public function moveStage(Player $player, int $index, ?string $sound = 'note.pling') : void{
        $world = $this->getStageWorld($player);
        if($world === null){
            return;
        }
        if($index === 1){
            $player->getInventory()->clearAll();
            $player->setGamemode(GameMode::ADVENTURE());
            $player->sendMessage("§a§lWelcome To Cross.\n\n§r§f크로스팜에 오신것을 진심으로 환영해요.\n\n크로스팜을 원활하게 플레이하시는것을 돕기 위해, 튜토리얼을 진행합니다.\n\n§f튜토리얼은 스킵하실 수 있지만. 크로스팜은 다른 서버들과 다르게, 진행형 튜토리얼을 제공합니다. 색다르게 즐기실 수 있으니, 스킵은 웬만하면 하지 말아주세요.\n\n튜토리얼 중에는 다른 유저의 채팅이 표시되지 않습니다.");
            $player->getEffects()->add(new EffectInstance(VanillaEffects::SATURATION(), 999999, 255, false));
            $player->getEffects()->add(new EffectInstance(VanillaEffects::NIGHT_VISION(), 999999, 255, false));
        }
        $this->sendStageTitle($player, $index);
        if(($pos = $this->getStageLocation($index, $world)) !== null){
            $player->teleport($pos);
        }
        $this->player_stage_index_map[$player->getName()] = $index;
        if($sound !== null){
            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($sound, $player){
                if(!$player->isOnline()){
                    return;
                }

                CrossUtils::playSound($player, $sound);
            }), 2);
        }
    }

    // x/y/z/yaw/pitch
    public array $stage_map = [
        '8.5/4/25.5/220/0',
        '53.5/4/25.5/220/0',
        '86.5/3/25.5/220/0',
        '116.5/4/25.5/220/0',
        '147.5/4/26.5/180/0',
        '184.5/4/25.5/180/0',
        '211.5/4/26.5/220/0',
        '236.5/3/19.5/311/0',
        '267.5/3/21.5/311/0',
        '305.5/3/20.5/311/0',
        '335.5/3/21.5/311/0',
        '336.5/3/63.5/311/0',
        '362.5/4/31.5/180/0',
        '383.5/4/31.5/180/0',
        '365.5/3/64.5/311/0',
        '407.5/3/20.5/300/0',
        '435.5/2/20.5/300/0',
        '569.5/4/32.5/250/0',
        '591.5/4/37.5/180/0',
        '657.5/4/34.5/220/0',
        '692.5/4/35.5/220/0',
    ];

    public array $stage_welcome_titles = [
        0 => ['§l§a환영해요', '§r§7크로스팜 튜토리얼, ' . PHP_EOL . '시작해봅시다!'],
        1 => ['§l§a채팅!', '§7채팅 사용에 대해 배워볼까요?']
    ];

    public array $player_stage_index_map = [];

    /**
     * @throws JsonException
     */
    protected function onDisable() : void{
        file_put_contents($this->getDataFolder() . 'db.json', json_encode($this->db, JSON_THROW_ON_ERROR));
    }

    /**
     * @throws ReflectionException
     * @throws JsonException
     */
    public function onEnable() : void{
        $this->removeAllWorld();
        $this->db = CrossUtils::getDataArray($this->getDataFolder() . 'db.json');
        $this->getServer()->getPluginManager()->registerEvent(EntityDamageByEntityEvent::class, function($ev){
            $this->onAttack($ev);
        }, EventPriority::NORMAL, $this, true);
        $this->getServer()->getPluginManager()->registerEvent(PlayerChatEvent::class, function(PlayerChatEvent $ev){
            $ev->setRecipients(array_filter($ev->getRecipients(), function($commandSender){
                return !isset($this->player_stage_index_map[$commandSender->getName()]);
            }));
        }, EventPriority::NORMAL, $this);
        $this->getServer()->getPluginManager()->registerEvent(PlayerJoinEvent::class, function(PlayerJoinEvent $ev){
            $player = $ev->getPlayer();
            if(!isset($this->db[$player->getName()])){
                $this->moveStage($player, 1);
            }
        }, EventPriority::MONITOR, $this);
        $this->getServer()->getPluginManager()->registerEvent(PlayerCommandPreprocessEvent::class, function(PlayerCommandPreprocessEvent $ev){
            $player = $ev->getPlayer();
            $msg = $ev->getMessage();
            if(isset($this->player_stage_index_map[$player->getName()])){
                $ev->cancel();
                $stage = $this->player_stage_index_map[$player->getName()];
                if($stage === 2){
                    $player->sendMessage('§e§l[애밀리] §r§7잘했어요! 다음 챕터로 보내줄게요!');
                    $this->moveStage($player, 3);
                    return;
                }
                if($stage === 3){
                    if(!str_starts_with($msg, '/')){
                        CrossUtils::playSound($player, 'note.bass');
                        $player->sendMessage('§e§l[애밀리] §r§7명령어를 입력해주세요.');
                        return;
                    }
                    $player->sendMessage('§e§l[애밀리] §r§7우와! 이해가 빠르시군요? 다음 챕터로 보내줄게요!');
                    $this->moveStage($player, 4);
                    return;
                }
                if($stage === 4){
                    if($msg === '/광산'){
                        $player->sendMessage('§a§l[시스템] §r§7잘했어요!');
                        $player->sendMessage('§a§l[시스템] §r§7인벤토리에 철 곡괭이를 지급했어요! 광물을 캐볼까요?');
                        $this->moveStage($player, 5);

                        $pickaxe = ItemFactory::getInstance()->get(ItemIds::IRON_PICKAXE);
                        $pickaxe->getNamedTag()->setByte('tutorialitem', true);

                        $player->setGamemode(GameMode::SURVIVAL());
                        $player->getInventory()->setItem(4, $pickaxe);
                        $player->getInventory()->setHeldItemIndex(4);
                        return;
                    }
                    CrossUtils::playSound($player, 'note.bass');
                    $player->sendMessage('§a§l[시스템] §r§7삑! §e/광산§r§7을 입력해주세요!');
                    return;
                }
                if($stage === 6){
                    if($msg === '/판매전체' || $msg === '/판매 전체'){
                        CrossUtils::playSound($player, 'note.pling');
                        $player->sendMessage('§a§l[시스템] §r§7광물이 판매되었어요!');
                        $player->getInventory()->clearAll();

                        $player->sendTitle("丁", "§e" . EconomySystem::getInstance()->koreanWonFormat(200000) . "§f이 지급되었습니다.", 0, 15, 0);
                        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player){
                            if(!$player->isOnline()){
                                return;
                            }

                            $this->moveStage($player, 7);
                            $player->sendMessage('§a§l[시스템] §r§7오! 20만원을 모았네요? 팜을 구매해봅시다!');
                            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player){
                                if(!$player->isOnline()){
                                    return;
                                }

                                $player->sendMessage('§a§l[시스템] §r§e/팜 구매 §7를 실행해주세요.');
                            }), 20);
                        }), 20);
                        return;
                    }
                    CrossUtils::playSound($player, 'note.bass');
                    $player->sendMessage('§a§l[시스템] §r§7삑! §e/판매전체§r§7을 입력해주세요!');
                    return;
                }
                if($stage === 16){
                    if($msg === '/팜 기타설정'){
                        CrossUtils::playSound($player, 'note.pling');

                        $form = new CustomForm(function(Player $player, $data) use (&$form){
                            if($data[1] === false){
                                $form->sendForm($player);
                                return;
                            }
                            $player->sendMessage('§l§a[시스템] §r§7잘했어요!');
                            CrossUtils::playSound($player, 'note.pling');
                            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player){
                                if(!$player->isOnline()){
                                    return;
                                }

                                $this->moveStage($player, 17);
                                $player->sendMessage('§l§a[시스템] §r§7오! 접근제한을 풀었더니 애밀리가 놀러왔네요!');
                                CrossUtils::playSound($player, 'note.pling');
                                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player){
                                    if(!$player->isOnline()){
                                        return;
                                    }

                                    $player->sendMessage('§l§e[애밀리] §r§7흠.. 팜이 너무 허접하고 심심하군요!');
                                    CrossUtils::playSound($player, 'note.pling');
                                    $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player){
                                        if(!$player->isOnline()){
                                            return;
                                        }

                                        $player->sendMessage('§l§a[시스템] §r§7치! 애밀리 때문에 기분이 썩 좋지 않네요!');
                                        CrossUtils::playSound($player, 'note.pling');
                                        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player){
                                            if(!$player->isOnline()){
                                                return;
                                            }

                                            $player->sendMessage('§l§a[시스템] §r§7애밀리를 팜에서 추방시킵시다!');
                                            CrossUtils::playSound($player, 'note.pling');
                                            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player){
                                                if(!$player->isOnline()){
                                                    return;
                                                }

                                                $player->sendMessage('§l§a[시스템] §r§7애밀리를 웅크리고 때려주세요!');
                                                CrossUtils::playSound($player, 'note.pling');
                                            }), 20);
                                        }), 20);
                                    }), 20);
                                }), 20);
                            }), 20);
                        });
                        $form->setTitle('§l팜 기타설정');
                        $form->addLabel('§a접근§f을 풀어주세요! 다른 유저들이 내 팜에 오도록 허용하려면 풀어야합니다.');
                        $form->addToggle('접근', false);
                        $form->sendForm($player);
                        return;
                    }
                    CrossUtils::playSound($player, 'note.bass');
                    $player->sendMessage('§a§l[시스템] §r§7삑! §e/팜 기타설정§r§7을 입력해주세요!');
                    return;
                }
                if($stage === 18){
                    $message = $ev->getMessage();
                    if(!str_starts_with($message, '#')){
                        $player->sendMessage('§a§l[시스템] §r§f메세지는 §a#§f으로 시작해야합니다.');
                        CrossUtils::playSound($player, 'note.bass');
                        return;
                    }

                    $this->moveStage($player, 19);
                    return;
                }
                if($stage === 19){
                    $message = $ev->getMessage();
                    if($message === '/후원'){
                        $this->moveStage($player, 20);
                        $player->sendMessage('§a§l[시스템] §r§f유저 상호작용에 대한 글을 읽어보세요! 모두 읽었다면, 애밀리를 웅크리고 때려보세요!');
                        return;
                    }

                    $player->sendMessage('§l§a[시스템] §r§e/후원§7을 실행하세요.');
                    CrossUtils::playSound($player, 'note.bass');
                    return;
                }
                if($stage === 11){
                    if($msg === '/판매전체' || $msg === '/판매 전체'){
                        CrossUtils::playSound($player, 'note.pling');
                        $player->sendMessage('§a§l[시스템] §r§7작물이 판매되었어요!');
                        $player->getInventory()->clearAll();

                        $player->sendTitle("丁", "§e" . EconomySystem::getInstance()->koreanWonFormat(1000000) . "§f이 지급되었습니다.", 0, 15, 0);
                        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player){
                            if(!$player->isOnline()){
                                return;
                            }

                            $this->moveStage($player, 12);
                            $player->sendMessage('§a§l[시스템] §r§7오! 100만원을 모았네요? 상점으로 이동해봅시다!');
                            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player){
                                if(!$player->isOnline()){
                                    return;
                                }

                                $player->sendMessage('§a§l[시스템] §r§e/상점 §7을 실행해주세요.');
                            }), 20);
                        }), 20);
                        return;
                    }
                    CrossUtils::playSound($player, 'note.bass');
                    $player->sendMessage('§a§l[시스템] §r§7삑! §e/판매전체§r§7을 입력해주세요!');
                    return;
                }
                if($stage === 14){
                    if($msg === '/팜 목록'){
                        CrossUtils::playSound($player, 'note.pling');

                        $form = new ButtonForm(function(Player $player){
                            $player->setGamemode(GameMode::SURVIVAL());
                            $player->sendMessage('§l§a[시스템] §r§7이제 아까 구매한, §e효과부여대 §7를 설치해볼까요?');
                            $this->moveStage($player, 15);
                        });
                        $form->setTitle('§l팜 목록');
                        $form->setContent('이동하고 싶은 팜을 클릭해주세요');

                        $form->addButton('§l§a(튜토리얼) §r§l§8튜토리얼팜', false, 'crossteam/skyisland');

                        $form->sendForm($player);
                        return;
                    }
                    CrossUtils::playSound($player, 'note.bass');
                    $player->sendMessage('§a§l[시스템] §r§7삑! §e/팜 목록§r§7을 입력해주세요!');
                    return;
                }
                if($stage === 12){
                    if($msg === '/상점'){
                        CrossUtils::playSound($player, 'note.pling');
                        $player->sendMessage('§a§l[시스템] §r§7상점으로 이동했어요! §e효과부여대§7를 구매해주세요!');

                        $this->moveStage($player, 13);
                        return;
                    }
                    CrossUtils::playSound($player, 'note.bass');
                    $player->sendMessage('§a§l[시스템] §r§7삑! §e/상점§r§7을 입력해주세요!');
                    return;
                }
                if($stage === 7){
                    if($msg === '/팜 구매'){
                        CrossUtils::playSound($player, 'note.pling');
                        $player->sendMessage('§a§l[시스템] §r§7팜을 샀어요!');

                        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player){
                            if(!$player->isOnline()){
                                return;
                            }

                            $player->sendMessage('§a§l[시스템] §r§7팜으로 이동해볼까요?');
                            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player){
                                if(!$player->isOnline()){
                                    return;
                                }

                                $item = ItemFactory::getInstance()->get(10026);
                                $item->setCustomName('§r§2§l● §r§a고추 §f농사법');
                                $item->getNamedTag()->setString('turtorialshdtkqjq', 'gochu');
                                $player->getInventory()->clearAll();
                                $player->getInventory()->setItem(4, $item);
                                $player->getInventory()->setHeldItemIndex(4);

                                $player->sendMessage('§a§l[시스템] §r§7농사법을 사용해봅시다! 초록색 책을 쭉 누르세요! (PC: 우클릭)');
                                $this->moveStage($player, 8);
                            }), 20);
                        }), 20);
                        return;
                    }
                    CrossUtils::playSound($player, 'note.bass');
                    if($msg === '/팜구매'){
                        $player->sendMessage('§a§l[시스템] §r§7삑! 팜 띄고 구매 입니다! §e/팜 구매§r§7를 입력해주세요!');
                        return;
                    }
                    $player->sendMessage('§a§l[시스템] §r§7삑! §e/팜 구매§r§7를 입력해주세요!');
                    return;
                }
                $player->sendMessage('§a§l[시스템] §r§7튜토리얼 중에는 채팅/명령어를 입력할 수 없어요.');
            }
        }, EventPriority::NORMAL, $this);

        $this->getServer()->getPluginManager()->registerEvent(BlockBreakEvent::class, function(BlockBreakEvent $ev){
            $player = $ev->getPlayer();
            if(isset($this->player_stage_index_map[$player->getName()])){
                if($this->player_stage_index_map[$player->getName()] === 5){
                    $block = $ev->getBlock()->getPosition()->getWorld()->getBlock($ev->getBlock()->getPosition()->add(0, -1, 0));
                    if($block->getId() !== BlockLegacyIds::END_STONE){
                        $ev->cancel();
                        return;
                    }
                    $player->sendMessage('§a§l[애밀리] §r§7아이템을 주워보세요!');
                    $ev->uncancel();
                    return;
                }
                if($this->player_stage_index_map[$player->getName()] === 10){
                    $block = $ev->getBlock()->getId();
                    if($block === BlockLegacyIds::POTATO_BLOCK){
                        $player->getInventory()->clearAll();
                        $player->getInventory()->addItem(ItemFactory::getInstance()->get(ItemIds::POTATO, 0, 1));
                        $player->sendMessage('§a§l[시스템] §r§7잘하셨어요!! 이제 판매를 해봅시다! §e/판매전체 §r§7를 입력해주세요!');

                        $player->setGamemode(GameMode::ADVENTURE());
                        $this->moveStage($player, 11);
                    }
                }
            }
        }, EventPriority::HIGHEST, $this, true);

        $this->getServer()->getPluginManager()->registerEvent(PlayerItemUseEvent::class, function(PlayerItemUseEvent $ev){
            $player = $ev->getPlayer();
            if(isset($this->player_stage_index_map[$player->getName()]) && $this->player_stage_index_map[$player->getName()] === 8){
                $item = $ev->getItem();
                if($item->getNamedTag()->getTag('turtorialshdtkqjq') !== null){
                    $player->sendMessage('§b§l[알림] §r§7농사법을 읽어, 습득하였습니다.');
                    $player->sendMessage('§a§l[시스템] §r§7잘하셨어요! 이제 고추를 심어주세요!');

                    $player->getInventory()->clearAll();
                    $item = ItemFactory::getInstance()->get(392);
                    $item->getNamedTag()->setString('turtorialdkdlxpa', 'gochu');
                    $player->getInventory()->clearAll();
                    $player->getInventory()->setItem(4, $item);
                    $player->getInventory()->setHeldItemIndex(4);

                    $this->moveStage($player, 9);
                }
            }
        }, EventPriority::HIGHEST, $this, true);

        $this->getServer()->getPluginManager()->registerEvent(PlayerInteractEvent::class, function(PlayerInteractEvent $ev){
            $player = $ev->getPlayer();
            if(isset($this->player_stage_index_map[$player->getName()])){
                if($this->player_stage_index_map[$player->getName()] === 13 || ($i14 = $this->player_stage_index_map[$player->getName()] === 14)){
                    $b = $ev->getBlock();
                    if($b instanceof ItemFrame){
                        $ev->cancel();
                        if(isset($i14)){
                            return;
                        }
                        if($b->getFramedItem()?->getId() !== ItemIds::ENCHANTING_TABLE){
                            $player->sendMessage('§a§l[시스템] §r§7효과부여대를 구매해주세요!');
                            return;
                        }

                        $form = new CustomForm(function(Player $player, array $data) : void{
                            if(!is_numeric(($data[2] ?? null))){
                                $player->sendMessage('§a§l[상점] §r§7숫자만 입력해주세요.');
                                return;
                            }
                            // 구매
                            if($data[1] === false){
                                $money = 100000;
                                $player->getInventory()->addItem(ItemFactory::getInstance()->get(ItemIds::ENCHANTING_TABLE));
                                $player->sendMessage('§a§l[상점] §r§e' . '효과부여대' . '(을)를 §e' . 1 . "개§f 구매했어요.\n   §f- 소비한 금액: §e" . EconomySystem::getInstance()->koreanWonFormat($money));
                                $this->moveStage($player, 14);
                                $player->sendMessage('§a§l[상점] §r§e/팜 목록 §r§7을 입력해볼까요?');
                                return;
                            }

                            // 판매
                            if($data[1] === true){
                                $player->sendMessage('§a§l[상점] §r§7판매할 수 없는 아이템이에요.');
                            }
                        });
                        $form->setTitle('§l상점 사용 도우미');
                        $price = [1000000, -1];
                        $form->addLabel('§a§l[상점] §r§e' . '효과부여대' . "§r§f(을)를 §e구매 또는 판매§f 하시겠습니까?\n\n\n§r" . ('§e- 구매가: ' . ($price[0] !== -1 ? (EconomySystem::getInstance()->koreanWonFormat($price[0]) . PHP_EOL) : '§c구매불가') . PHP_EOL . '§b- 판매가: ' . ($price[1] !== -1 ? (EconomySystem::getInstance()->koreanWonFormat($price[1])) : '§c판매불가')) . PHP_EOL);

                        $form->addToggle('§c§l구매  §r§f/ §r§b  판매', false);
                        $form->addInput("\n\n§r§f몇개를 §e구매 또는 판매 §f할까요?", 1, 1);

                        $form->sendForm($player);
                    }
                    return;
                }
                if($ev->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK){
                    return;
                }
                if($this->player_stage_index_map[$player->getName()] === 9){
                    $item = $ev->getItem();
                    if($item->getId() === ItemIds::POTATO){
                        $player->sendMessage('§a§l[시스템] §r§7잘하셨어요! 이제 고추를 수확해봅시다!');
                        $player->setGamemode(GameMode::SURVIVAL());

                        $player->getInventory()->clearAll();
                        $this->moveStage($player, 10);
                    }
                    return;
                }
                if($this->player_stage_index_map[$player->getName()] === 15){
                    if($ev->getItem()->getId() === ItemIds::ENCHANTING_TABLE){
                        $player->getInventory()->clearAll();
                        $player->sendMessage('§l§a[시스템] §r§7이제, §e/팜 기타설정 §7을 입력해주세요');
                        $player->setGamemode(GameMode::ADVENTURE());
                        $this->moveStage($player, 16);
                    }
                    return;
                }
            }
        }, EventPriority::HIGHEST, $this, true);

        $this->getServer()->getPluginManager()->registerEvent(EntityItemPickupEvent::class, function(EntityItemPickupEvent $ev){
            $player = $ev->getEntity();
            if(!$player instanceof Player){
                return;
            }
            if(isset($this->player_stage_index_map[$player->getName()])){
                if($this->player_stage_index_map[$player->getName()] === 5){
                    $ev->uncancel();

                    $player->getInventory()->clearAll();
                    $player->setGamemode(GameMode::ADVENTURE());
                    $player->sendMessage('§a§l[시스템] §r§7오! 잘했어요! 광물을 팔아볼까요?');
                    $this->moveStage($player, 6);
                    $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player){
                        if(!$player->isOnline()){
                            return;
                        }

                        $player->sendMessage('§a§l[시스템] §r§7이제 §e/판매전체 §7명령어를 실행해봐요!');
                    }), 20);
                    return;
                }
            }
        }, EventPriority::HIGHEST, $this, true);
    }

    // public function getPlayer

    public function onAttack(EntityDamageByEntityEvent $ev) : void{
        $slapper = $ev->getEntity();
        $player = $ev->getDamager();

        if(!($slapper instanceof SlapperHuman && $player instanceof Player)){
            return;
        }

        if(!isset($this->player_stage_index_map[$player->getName()])){
            return;
        }

        //1
        if(($ntag = $slapper->getNameTag()) === '§a§l이 친구를 클릭하세요!'){
            $tag = '§a§l[도우미] §r§f';
            CrossUtils::playSound($player, 'note.pling');
            $player->sendMessage($tag . '반가워요! §f' . $player->getName() . '§f.');
            $slapper->setNameTag('§r§7나의 튜토리얼 도우미,' . PHP_EOL . '§l§e애밀리❤');
            // WAVE
            $player->getNetworkSession()->sendDataPacket(EmotePacket::create($slapper->getId(), '4c8ae710-df2e-47cd-814d-cc7bf21a3d67', 1 << 0));

            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $tag, $slapper){
                if(!$player->isOnline()){
                    return;
                }

                CrossUtils::playSound($player, 'note.pling');
                $player->sendMessage($tag . '저는 이번 튜토리얼을 도와줄 §e' . self::NPCNAME . '§f이라고 해요. 기억해주실 수 있죠?');
                $tag = '§e§l[' . self::NPCNAME . ']§r§7 ';

                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $tag){
                    if(!$player->isOnline()){
                        return;
                    }

                    CrossUtils::playSound($player, 'note.pling');
                    $player->sendMessage($tag . '앞으로 나오는 간단한 설명들을 꼼꼼히 읽어야 쉽게 클리어하실 수 있을꺼예요.');

                    $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $tag){
                        if(!$player->isOnline()){
                            return;
                        }

                        CrossUtils::playSound($player, 'note.pling');
                        $player->sendMessage($tag . '튜토리얼 중에는 다른 유저들의 채팅이 보이지 않아요. 참고하세요!');
                        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $tag){
                            if(!$player->isOnline()){
                                return;
                            }

                            CrossUtils::playSound($player, 'note.pling');
                            $player->sendMessage($tag . '저의 소개를 마쳤으니, 다음 챕터로 보내드릴게요.');

                            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player){
                                if(!$player->isOnline()){
                                    return;
                                }

                                $this->moveStage($player, 2);
                            }), self::MESSAGETICK);
                        }), self::MESSAGETICK);
                    }), self::MESSAGETICK);
                }), self::MESSAGETICK);
            }), self::MESSAGETICK);
            return;
        }

        if($ntag === '§e§l애밀리❤' && ($this->player_stage_index_map[$player->getName()] === 17)){
            if(!$player->isSneaking()){
                CrossUtils::playSound($player, 'note.bass');
                $player->sendMessage('§a§l[시스템] §r§7웅크리고 때려주세요!');
                return;
            }

            $form = new ButtonForm(function(Player $player, $data) use (&$form){
                if($data !== 0){
                    $form->sendForm($player);
                    return;
                }

                $this->moveStage($player, 18);
                $player->sendMessage('§l§e[애밀리] §r§f악.. 미안해요!');

                $player->sendMessage('§l§a[시스템] §r§f이제 §a#채팅§f에 대해 알아볼까요?');
                $player->sendMessage('§l§a[시스템] §r§f채팅 앞에 §a#§f을 붙이고 아무채팅이나 쳐보세요!');
            });
            $form->setTitle('§l팜 메뉴');
            $form->setContent('');
            $form->addButton('§l팜에서 추방하기' . PHP_EOL . '§r§7팜에서 추방시킵니다.');
            $form->sendForm($player);
            return;
        }

        if(($this->player_stage_index_map[$player->getName()] === 20) && str_contains($ntag, '애밀리❤')){
            if(!$player->isSneaking()){
                CrossUtils::playSound($player, 'note.bass');
                $player->sendMessage('§a§l[시스템] §r§7웅크리고 때려주세요!');
                return;
            }

            $form = new ButtonForm(function(Player $player, $data) use (&$form){
                if($data !== 0){
                    $form->sendForm($player);
                    return;
                }

                CrossUtils::playSound($player, 'note.pling');
                if(isset(SkinManager::getInstance()->db[$player->getName()])){
                    $player->sendMessage('§a§l[시스템] §r§7당신은 이미 스킨을 선택하였으므로, 튜토리얼을 종료하고 스폰으로 이동합니다.');
                    $this->finish($player);
                    return;
                }

                $world = $this->getServer()->getWorldManager()->getWorldByName('skinselect');
                if($world === null){
                    $this->getServer()->getWorldManager()->loadWorld('skinselect');
                    $world = $this->getServer()->getWorldManager()->getWorldByName('skinselect');
                }

                $player->teleport(new Position(2.5, 4, 1.5, $world), 0, 0);
            });
            $form->setTitle('§l상호작용');
            $form->setContent('');
            $form->addButton("§l확인");
            $form->sendForm($player);
        }
    }

}