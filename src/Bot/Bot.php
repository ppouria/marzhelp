<?php

namespace App\Bot;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\RunningMode\Polling;
use SergiX44\Nutgram\Configuration;

class Bot
{
    protected $bot;

    public function __construct()
    {
        // TODO: Add (TELEGRAM_BOT_TOKEN) to ../config
        define('TELEGRAM_BOT_TOKEN', '7946367992:AAGXte6nw_oF8_cQrxK1QdHPsprKzO75MUM');

        $config = new Configuration(
            clientTimeout: 10,
            clientOptions: [
                'verify' => false
            ]
        );

        $this->bot = new Nutgram(TELEGRAM_BOT_TOKEN, $config);    
    }

    public function run()
    {
        $this->bot->setRunningMode(Polling::class);
        $this->bot->onCommand('start', function (Nutgram $bot) {
            $bot->sendMessage('Hi!');
        });
        $this->bot->run();
    }
}