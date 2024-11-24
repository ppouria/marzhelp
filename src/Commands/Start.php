<?php

namespace App\Commands;

use SergiX44\Nutgram\Nutgram;

class Start
{
    public function __invoke(Nutgram $bot)
    {
        $bot->sendMessage('Hello, World!');
    }
}
