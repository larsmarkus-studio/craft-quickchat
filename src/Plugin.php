<?php

declare(strict_types=1);

namespace larsmarkusstudio\quickchat;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use larsmarkusstudio\quickchat\models\Settings;
use larsmarkusstudio\quickchat\services\Chat;
use larsmarkusstudio\quickchat\variables\QuickchatVariable;
use yii\base\Event;

/**
 * Quickchat: a tiny live chat on Datastar + SSE, no cookies, no third-party service.
 *
 * @author Lars Markus
 * @license MIT
 *
 * @property-read Settings $settings
 * @property-read Chat $chat
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return ['components' => ['chat' => Chat::class]];
    }

    public function init(): void
    {
        parent::init();

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function (Event $event) {
            $event->sender->set('quickchat', QuickchatVariable::class);
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function (RegisterUrlRulesEvent $event) {
            $event->rules['quickchat'] = 'quickchat/cp/index';
        });
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        // ponytail: one COUNT per CP page load, cache it if the CP feels slow
        if ($unread = $this->chat->unreadCount()) {
            $item['badgeCount'] = $unread;
        }
        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('quickchat/_settings', [
            'settings' => $this->getSettings(),
        ]);
    }
}
