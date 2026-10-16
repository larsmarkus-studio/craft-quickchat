<?php

declare(strict_types=1);

namespace larsmarkusstudio\quickchat\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Every value can be overridden in `config/quickchat.php`.
 */
class Settings extends Model
{
    public bool $enabled = true;
    public string $title = 'Questions? Chat with us';
    public string $welcomeMessage = 'Hi! Ask us anything, we usually reply within a few minutes.';
    public string $offlineMessage = 'We are offline right now. Mail us and we get back to you.';
    /** Shown when offline. Env vars allowed, e.g. `$SUPPORT_EMAIL`. */
    public string $supportEmail = '';
    /** Gets a mail for every new conversation. Empty = no mail. Env vars allowed. */
    public string $notifyEmail = '';
    /** ISO weekdays: 1 = Monday … 7 = Sunday. */
    public array|string $openDays = [1, 2, 3, 4, 5]; // '' when no box is ticked
    /** `HH:MM` in the Craft system timezone. `openTo` before `openFrom` spans midnight. */
    public string $openFrom = '09:00';
    public string $openTo = '17:00';
    /** Seconds between checks for new messages, in the widget and the CP. */
    public int $pollSeconds = 3;
    /**
     * 0: every check is a short request that answers at once, so no PHP process is tied up.
     * Above 0: each request stays open this long and pushes messages the moment they arrive.
     * Only for servers that pass streamed responses through unbuffered and have PHP processes
     * to spare: every open chat and every open dashboard holds one.
     */
    public int $holdSeconds = 0;

    public function getSupportEmail(): string
    {
        return (string) App::parseEnv($this->supportEmail);
    }

    public function getNotifyEmail(): string
    {
        return (string) App::parseEnv($this->notifyEmail);
    }

    protected function defineRules(): array
    {
        return [
            [['title', 'welcomeMessage', 'offlineMessage', 'openFrom', 'openTo'], 'required'],
            [['openFrom', 'openTo'], 'match', 'pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/'],
            [['pollSeconds'], 'integer', 'min' => 1, 'max' => 60],
            [['holdSeconds'], 'integer', 'min' => 0, 'max' => 300],
        ];
    }
}
