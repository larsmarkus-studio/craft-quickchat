<?php

declare(strict_types=1);

namespace larsmarkusstudio\quickchat\variables;

use Craft;
use craft\web\View;
use larsmarkusstudio\quickchat\models\Settings;
use larsmarkusstudio\quickchat\Plugin;
use putyourlightson\datastar\Datastar;
use Twig\Markup;

class QuickchatVariable
{
    /**
     * `{{ craft.quickchat.widget() }}`: renders only the launcher. Panel, stylesheet and
     * messages arrive over SSE on the first click.
     */
    public function widget(): ?Markup
    {
        if (!Plugin::getInstance()->getSettings()->enabled) {
            return null;
        }
        // Registers datastar.js on this page only; a no-op when the Datastar plugin already did
        Datastar::getInstance();

        return new Markup(Craft::$app->getView()->renderTemplate('quickchat/_widget/launcher', [
            'settings' => Plugin::getInstance()->getSettings(),
        ], View::TEMPLATE_MODE_CP), 'UTF-8');
    }

    public function getSettings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    public function url(string $action): string
    {
        return Plugin::getInstance()->chat->url($action);
    }
}
