<?php

declare(strict_types=1);

namespace larsmarkusstudio\quickchat\controllers;

use Craft;
use craft\web\Controller;
use larsmarkusstudio\quickchat\Plugin;
use larsmarkusstudio\quickchat\services\Chat;
use putyourlightson\datastar\assets\DatastarAssetBundle;
use putyourlightson\datastar\traits\Sse;
use yii\web\Response;

/**
 * The CP dashboard: list + thread, kept live by one SSE stream that re-renders both on any change.
 */
class CpController extends Controller
{
    use Sse;

    public function beforeAction($action): bool
    {
        $this->requirePermission('accessPlugin-quickchat');
        return parent::beforeAction($action);
    }

    public function actionIndex(?int $id = null): Response
    {
        $id ??= (int) Craft::$app->getRequest()->getQueryParam('id') ?: null;
        // The aliased (`data-star-*`) build, on this CP page only, whatever the site's `registerScript` says
        $this->getView()->registerAssetBundle(DatastarAssetBundle::class);
        if ($id) {
            Plugin::getInstance()->chat->markRead($id);
        }
        return $this->renderTemplate('quickchat/_cp/index', $this->vars($id));
    }

    public function actionStream(): Response
    {
        $this->requirePostRequest();
        $qc = $this->qc();
        $id = (int) ($qc['sel'] ?? 0) ?: null;
        $version = (string) ($qc['v'] ?? '');
        $seconds = Plugin::getInstance()->getSettings()->streamSeconds;
        $chat = Plugin::getInstance()->chat;

        return $this->sse()->getEventStream(function () use ($chat, $id, $version, $seconds) {
            set_time_limit($seconds + 15);
            $end = time() + $seconds;
            while (time() < $end && !connection_aborted()) {
                if ($id) {
                    $c = $chat->findById($id);
                    if ($c && $chat->isUnread($c)) {
                        $chat->markRead($id);
                    }
                }
                if (($now = $chat->version()) !== $version) {
                    $version = $now;
                    $this->patchDashboard($id);
                    $this->sse()->patchSignals(['qc' => ['v' => $version]]);
                }
                while (ob_get_level() > 0) {
                    ob_end_flush(); // the SSE service leaves a buffer open after each event
                }
                echo ": ping\n\n";
                flush();
                sleep(1);
            }
            $this->sse()->patchElements($this->renderPartial('_cp/stream', ['n' => random_int(1, 999999999)]));
        });
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
        }
        return $this->sse()->getEventStream();
    }

    public function actionStatus(): Response
    {
        $this->requirePostRequest();
        $qc = $this->qc();
        Plugin::getInstance()->chat->setStatus((int) ($qc['sel'] ?? 0), (string) ($qc['status'] ?? ''));
        return $this->sse()->getEventStream(); // the stream picks up the change
    }

    private function patchDashboard(?int $id): void
    {
        $vars = $this->vars($id);
        $this->sse()->patchElements($this->renderPartial('_cp/list', $vars));
        $this->sse()->patchElements($this->renderPartial('_cp/thread', $vars));
    }

    private function vars(?int $id): array
    {
        $chat = Plugin::getInstance()->chat;
        $conversation = $id ? $chat->findById($id) : null;
        return [
            'chat' => $chat,
            'conversations' => $chat->conversations(),
            'conversation' => $conversation,
            'messages' => $conversation ? $chat->messages($id) : [],
            'isOpen' => $chat->isOpen(),
        ];
    }

    private function renderPartial(string $template, array $vars): string
    {
        return Craft::$app->getView()->renderTemplate('quickchat/' . $template, $vars);
    }

    private function qc(): array
    {
        $qc = $this->sse()->readSignals()['qc'] ?? [];
        return is_array($qc) ? $qc : [];
    }
}
