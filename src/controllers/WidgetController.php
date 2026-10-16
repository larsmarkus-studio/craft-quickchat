<?php

declare(strict_types=1);

namespace larsmarkusstudio\quickchat\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use larsmarkusstudio\quickchat\Plugin;
use larsmarkusstudio\quickchat\services\Chat;
use putyourlightson\datastar\traits\Sse;
use yii\web\Response;

/**
 * Visitor endpoints. All POST, so the conversation token never lands in a URL or access log.
 *
 * No CSRF: CSRF guards ambient credentials (cookies). These endpoints have none; the
 * conversation token travels in the request body and is the only key.
 */
class WidgetController extends Controller
{
    use Sse;

    protected array|bool|int $allowAnonymous = true;
    public $enableCsrfValidation = false;

    private const POLL_SECONDS = 1;

    public function beforeAction($action): bool
    {
        // Action URLs live at the root, so the site comes from the widget, not the URL.
        // Switching it makes `|t` and the date formatter speak the visitor's language.
        $site = Craft::$app->getSites()->getSiteById((int) ($this->qc()['site'] ?? 0));
        if ($site) {
            Craft::$app->getSites()->setCurrentSite($site);
            Craft::$app->language = $site->language;
        }
        return parent::beforeAction($action);
    }

    public function actionOpen(): Response
    {
        $this->requirePostRequest();
        $this->patchPanel($this->qc());
        return $this->sse()->getEventStream();
    }

    public function actionStart(): Response
    {
        $this->requirePostRequest();
        $qc = $this->qc();
        $chat = Plugin::getInstance()->chat;
        $body = $this->body($qc);
        $email = trim((string) ($qc['email'] ?? ''));

        $error = match (true) {
            !$chat->isOpen() => Craft::t('quickchat', 'We just went offline, please mail us instead.'),
            $body === '' => Craft::t('quickchat', 'Please type a message.'),
            $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL) => Craft::t('quickchat', 'That email address looks off.'),
            !$chat->throttle('start' . Craft::$app->getRequest()->getUserIP(), 30) => Craft::t('quickchat', 'One moment please, then try again.'),
            default => null,
        };
        if ($error) {
            return $this->error($error);
        }

        $page = (string) ($qc['page'] ?? '');
        $conversation = $chat->start($body, $email ?: null, str_starts_with($page, 'http') ? $page : '', Craft::$app->getSites()->getCurrentSite()->id);
        $qc = array_merge($qc, ['token' => $conversation['token'], 'body' => '', 'last' => 0]);
        $this->sse()->patchSignals(['qc' => ['token' => $conversation['token'], 'body' => '', 'last' => 0]]);
        $this->patchPanel($qc);
        return $this->sse()->getEventStream();
    }

    public function actionSend(): Response
    {
        $this->requirePostRequest();
        $qc = $this->qc();
        $chat = Plugin::getInstance()->chat;
        $conversation = $chat->findByToken($qc['token'] ?? null);
        $body = $this->body($qc);

        if (!$conversation) {
            return $this->error(Craft::t('quickchat', 'This chat no longer exists.'));
        }
        if ($body === '') {
            return $this->sse()->getEventStream();
        }
        if (!$chat->throttle('send' . $conversation['id'], 1)) {
            return $this->error(Craft::t('quickchat', 'Easy, one message per second.'));
        }

        $chat->addMessage($conversation, $body, false);
        // The stream renders the message, so no duplicate patch here
        $this->sse()->patchSignals(['qc' => ['body' => '']]);
        $this->sse()->patchElements('<p id="qc-error"></p>');
        return $this->sse()->getEventStream();
    }

    /**
     * Sends messages newer than `last`. With `holdSeconds` 0 it answers at once and the page polls
     * again; otherwise it keeps checking for that long, then re-renders `#qc-stream` to reconnect.
     */
    public function actionStream(): Response
    {
        $this->requirePostRequest();
        $qc = $this->qc();
        $chat = Plugin::getInstance()->chat;
        $conversation = $chat->findByToken($qc['token'] ?? null);
        if (!$conversation) {
            return $this->sse()->getEventStream();
        }

        $last = (int) ($qc['last'] ?? 0);
        // The status the page last rendered, so a resolve between two polls still re-renders it
        $status = (string) ($qc['st'] ?? '') ?: $conversation['status'];
        $hold = Plugin::getInstance()->getSettings()->holdSeconds;

        // ponytail: one indexed query per check; with holdSeconds > 0 each open chat also holds a PHP
        // process. Move to pub/sub (Redis, Mercure) when polling load or held processes start to hurt.
        return $this->sse()->getEventStream(function () use ($chat, $conversation, $last, $status, $hold, $qc) {
            set_time_limit($hold + 15);
            $end = time() + $hold;
            while (true) {
                $messages = $chat->messages((int) $conversation['id'], $last);
                if ($messages) {
                    $last = (int) end($messages)['id'];
                    $this->sse()->patchElements($this->partial('_widget/messages', ['messages' => $messages]), [
                        'selector' => '#qc-messages',
                        'mode' => 'append',
                    ]);
                    $this->sse()->patchSignals(['qc' => ['last' => $last]]);
                }
                $now = $chat->findById((int) $conversation['id']);
                if (!$now || $now['status'] !== $status) {
                    $this->patchPanel(array_merge($qc, ['last' => 0]));
                    return;
                }
                if (time() >= $end || connection_aborted()) {
                    break;
                }
                // Comment line: keeps proxies from timing out and lets PHP notice a closed tab
                while (ob_get_level() > 0) {
                    ob_end_flush(); // the SSE service leaves a buffer open after each event
                }
                echo ": ping\n\n";
                flush();
                sleep(self::POLL_SECONDS);
            }
            if ($hold) {
                $this->sse()->patchElements($this->partial('_widget/stream', ['n' => random_int(1, 999999999)]));
            }
        });
    }

    private function patchPanel(array $qc): void
    {
        $plugin = Plugin::getInstance();
        $conversation = $plugin->chat->findByToken($qc['token'] ?? null);
        if (!$conversation && !empty($qc['token'])) {
            $this->sse()->patchSignals(['qc' => ['token' => '']]);
        }
        // The visitor's view starts empty and the stream fills it, so there's one render path for messages
        $this->sse()->patchSignals(['qc' => ['last' => 0, 'st' => $conversation['status'] ?? '']]);
        $this->sse()->patchElements($this->partial('_widget/panel', [
            'settings' => $plugin->getSettings(),
            'isOpen' => $plugin->chat->isOpen(),
            'conversation' => $conversation,
            'n' => random_int(1, 999999999),
            'cssUrl' => UrlHelper::rootRelativeUrl(Craft::$app->getAssetManager()->getPublishedUrl($plugin->getBasePath() . '/web/quickchat.css', true)),
        ]));
    }

    private function error(string $message): Response
    {
        $this->sse()->patchElements('<p id="qc-error" class="text-danger small mb-0" role="alert">' . htmlspecialchars($message) . '</p>');
        return $this->sse()->getEventStream();
    }

    private function qc(): array
    {
        $qc = $this->sse()->readSignals()['qc'] ?? [];
        return is_array($qc) ? $qc : [];
    }

    private function body(array $qc): string
    {
        return mb_substr(trim((string) ($qc['body'] ?? '')), 0, Chat::MAX_BODY);
    }

    private function partial(string $template, array $vars): string
    {
        return Craft::$app->getView()->renderTemplate('quickchat/' . $template, $vars, View::TEMPLATE_MODE_CP);
    }
}
