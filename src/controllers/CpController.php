<?php

declare(strict_types=1);

namespace larsmarkusstudio\quickchat\controllers;

use Craft;
use craft\web\Controller;
use larsmarkusstudio\quickchat\Plugin;
use larsmarkusstudio\quickchat\services\Chat;
use putyourlightson\datastar\assets\DatastarAssetBundle;
use putyourlightson\datastar\Datastar;
use yii\web\Response;

/**
 * The CP dashboard: list + thread, re-rendered whenever anything changes.
 */
class CpController extends Controller
{
    use LiveUpdates;

    public function beforeAction($action): bool
    {
        $this->requirePermission('accessPlugin-quickchat');
        return parent::beforeAction($action);
    }

    public function actionIndex(?int $id = null): Response
    {
        $id ??= (int) Craft::$app->getRequest()->getQueryParam('id') ?: null;
        // The Datastar plugin only boots the module on site requests; booting defines the bundle's alias.
        // Then the aliased (`data-star-*`) build, on this CP page only, whatever `registerScript` says.
        Datastar::getInstance();
        $this->getView()->registerAssetBundle(DatastarAssetBundle::class);
        $chat = Plugin::getInstance()->chat;
        if ($id) {
            $chat->markRead($id);
        }
        return $this->renderTemplate('quickchat/_cp/index', $this->vars($id, $this->request->getCsrfToken()) + [
            'seen' => $chat->lastVisitorMessageId(),
            'v' => $chat->version(),
            'dingUrl' => Craft::$app->getAssetManager()->getPublishedUrl(Plugin::getInstance()->getBasePath() . '/web/ding.wav', true),
        ]);
    }

    public function actionStream(): Response
    {
        $this->requirePostRequest();
        $qc = $this->qc();
        $id = (int) ($qc['sel'] ?? 0) ?: null;
        $version = (string) ($qc['v'] ?? '');
        // Newest visitor message this page knows about; `_ding` is the browser-only chime trigger
        $seen = (int) ($qc['seen'] ?? 0);
        $chat = Plugin::getInstance()->chat;
        // Held streams render after the headers are out, when touching the session throws: read the
        // CSRF token and create the formatter (reads the user's locale) first. Polls don't need it.
        $csrf = $this->request->getCsrfToken();
        Craft::$app->getFormatter();

        return $this->live(function () use ($chat, $id, &$version, &$seen, $csrf) {
            if ($id) {
                $chat->markReadIfUnread($id);
            }
            if (($now = $chat->version()) !== $version) {
                $version = $now;
                $this->patchDashboard($id, $now, $csrf);
                if (($latest = $chat->lastVisitorMessageId()) > $seen) {
                    $seen = $latest;
                    $this->sse()->patchSignals(['qc' => ['seen' => $latest, '_ding' => $latest]]);
                }
            }
            return true;
        }, fn() => $this->sse()->patchElements($this->partial('_cp/stream', ['n' => random_int(1, 999999999), 'csrf' => $csrf])));
    }

    public function actionReply(): Response
    {
        $this->requirePostRequest();
        $qc = $this->qc();
        $chat = Plugin::getInstance()->chat;
        $c = $chat->findById((int) ($qc['sel'] ?? 0));
        $body = mb_substr(trim((string) ($qc['reply'] ?? '')), 0, Chat::MAX_BODY);
        if ($c && $body !== '') {
            $chat->addMessage($c, $body, true, Craft::$app->getUser()->getId());
            $this->sse()->patchSignals(['qc' => ['reply' => '']]);
            // Shown right away rather than on the next poll
            $this->patchDashboard((int) $c['id'], $chat->version(), $this->request->getCsrfToken());
        }
        return $this->sse()->getEventStream();
    }

    public function actionStatus(): Response
    {
        $this->requirePostRequest();
        $qc = $this->qc();
        $chat = Plugin::getInstance()->chat;
        $id = (int) ($qc['sel'] ?? 0) ?: null;
        if ($id) {
            $chat->setStatus($id, (string) ($qc['status'] ?? ''));
            $this->patchDashboard($id, $chat->version(), $this->request->getCsrfToken());
        }
        return $this->sse()->getEventStream();
    }

    /** List + thread, and the version they show, so the next poll doesn't render them again. */
    private function patchDashboard(?int $id, string $version, string $csrf): void
    {
        $vars = $this->vars($id, $csrf);
        $this->sse()->patchElements($this->partial('_cp/list', $vars));
        $this->sse()->patchElements($this->partial('_cp/thread', $vars));
        $this->sse()->patchSignals(['qc' => ['v' => $version]]);
    }

    private function vars(?int $id, string $csrf): array
    {
        $chat = Plugin::getInstance()->chat;
        $conversation = $id ? $chat->findById($id) : null;
        return [
            'chat' => $chat,
            'conversations' => $chat->conversations(),
            'conversation' => $conversation,
            'messages' => $conversation ? $chat->messages($id) : [],
            'isOpen' => $chat->isOpen(),
            'csrf' => $csrf,
        ];
    }
}
