<?php

namespace esc\Modules;


use esc\Classes\DB;
use esc\Classes\Hook;
use esc\Classes\Log;
use esc\Classes\ManiaLinkEvent;
use esc\Classes\Template;
use esc\Interfaces\ModuleInterface;
use esc\Models\Player;

class InputSetup implements ModuleInterface
{
    /**
     * @var \Illuminate\Support\Collection
     */
    private static $binds;

    /**
     * KeyBinds constructor.
     */
    public function __construct()
    {
        Hook::add('PlayerConnect', [self::class, 'sendScript']);

        ManiaLinkEvent::add('show_key_bind_settings', [self::class, 'showSettings']);
        ManiaLinkEvent::add('bound_key_pressed', [self::class, 'keyPressed']);
        ManiaLinkEvent::add('update_keybinds', [self::class, 'sendScript']);
        ManiaLinkEvent::add('update_bind', [self::class, 'updateBind']);

        QuickButtons::addButton('🎮', 'Input-Setup', 'show_key_bind_settings');
    }

    public static function showSettings(Player $player)
    {
        $binds = self::$binds->filter(function ($bind) use ($player) {
            if ($bind['access']) {
                return $player->hasAccess($bind['access']);
            }

            return true;
        })->values();

        Template::show($player, 'input-setup.settings', compact('binds'));
    }

    /**
     * Add a new key-bind
     *
     * @param  string  $id
     * @param  string  $description
     * @param  callable  $callback
     * @param  string  $defaultKey
     * @param  string|null  $access
     */
    public static function add(string $id, string $description, $callback, string $defaultKey, string $access = null)
    {
        if (!self::$binds) {
            self::$binds = collect();
        }

        self::$binds->push([
            'id' => $id,
            'description' => $description,
            'callback' => $callback,
            'default' => $defaultKey,
            'code' => 0,
            'access' => $access,
        ]);
    }

    /**
     * Update a key-bind
     *
     * @param  Player  $player
     * @param  mixed  ...$data
     */
    public static function updateBind(Player $player, ...$data)
    {
        $binds = DB::table('user-settings')
            ->where('player_Login', '=', $player->Login)
            ->where('name', '=', 'key-binds')
            ->first();

        if (!$binds) {
            $binds = collect();
        } else {
            $binds = collect(json_decode($binds->value));
        }

        $data = json_decode(implode(',', $data));

        if ($binds->isNotEmpty()) {
            $binds = $binds->where('id', '!=', $data->id);
        }

        $binds->push($data);

        DB::table('user-settings')
            ->updateOrInsert([
                'player_Login' => $player->Login,
                'name' => 'key-binds'
            ], [
                'value' => $binds->toJson()
            ]);
    }

    /**
     * Send the key-bind script to the player
     *
     * @param  \esc\Models\Player  $player
     */
    public static function sendScript(Player $player)
    {
        $userBinds = $player->setting('key-binds', true);
        $userBinds = collect($userBinds)->keyBy('id');

        $binds = self::$binds->map(function ($bind) use ($player, $userBinds) {
            if ($bind['access'] && !$player->hasAccess($bind['access'])) {
                return null;
            }

            if ($userBinds->has($bind['id'])) {
                $b = $userBinds->get($bind['id']);
                return [
                    'id' => $bind['id'],
                    'code' => $b->code,
                    'name' => $b->name,
                    'def' => $bind['default'],
                ];
            }

            return [
                'id' => $bind['id'],
                'code' => $bind['code'],
                'name' => $bind['default'],
                'def' => $bind['default'],
            ];
        })->filter()->values();

        Template::show($player, 'input-setup.script', compact('binds'));
    }

    /**
     * Handle bound key presses
     *
     * @param  \esc\Models\Player  $player
     * @param  string  $id
     */
    public static function keyPressed(Player $player, string $id)
    {
        self::$binds->where('id', $id)->each(function ($bind) use ($player) {
            if ($bind['access']) {
                if (!$player->hasAccess($bind['access'])) {
                    return;
                }
            }

            if (gettype($bind['callback']) == "object") {
                $func = $bind['callback'];
                $func($player);
            } else {
                if (is_callable($bind['callback'], false, $callableName)) {
                    Log::write("Execute: ".$bind['callback'][0]." ".$bind['callback'][1],
                        isVeryVerbose());
                    call_user_func($bind['callback'], $player);
                } else {
                    throw new \Exception("KeyBind callback invalid, must use: [ClassName, ClassFunctionName] or Closure");
                }
            }
        });
    }

    /**
     * @inheritDoc
     */
    public static function start(string $mode, bool $isBoot = false)
    {
    }
}