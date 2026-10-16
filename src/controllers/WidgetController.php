<?php

declare(strict_types=1);

namespace larsmarkusstudio\quickchat\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use larsmarkusstudio\quickchat\Plugin;
use larsmarkusstudio\quickchat\services\Chat;
use yii\web\Response;

/**
 * Visitor endpoints. All POST, so the conversation token never lands in a URL or access log.
 *
 * No CSRF: CSRF guards ambient credentials (cookies). These endpoints have none; the
 * conversation token travels in the request body and is the only key.
 *
 * The page sends what it last rendered (`last` message id, `st` status). Whenever either is
 * out of date the whole message list is re-rendered, so a late or overlapping response can
 * never leave gaps or duplicates.
 */
class WidgetController extends Controller
{
    use LiveUpdates;

    protected array|bool|int $allowAnonymous = true;
    public $enableCsrfValidation = false;

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
        $this->patchPanel($this->conversation());
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
            !$chat->throttle('start' . Craft::$app->getRequest()->getUserIP(), 5, 600) => Craft::t('quickchat', 'One moment please, then try again.'),
            default => null,
        };
        if ($error) {
            return $this->error($error);
        }

        $page = (string) ($qc['page'] ?? '');
        $conversation = $chat->start($body, $email ?: null, str_starts_with($page, 'http') ? $page : '', Craft::$app->getSites()->getCurrentSite()->id);
        $this->sse()->patchSignals(['qc' => ['token' => $conversation['token'], 'body' => '']]);
        $this->patchPanel($conversation);
        return $this->sse()->getEventStream();
    }

    public function actionSend(): Response
    {
        $this->requirePostRequest();
        $qc = $this->qc();
        $chat = Plugin::getInstance()->chat;
        $conversation = $this->conversation();
        $body = $this->body($qc);

        if (!$conversation) {
            return $this->error(Craft::t('quickchat', 'This chat no longer exists.'));
        }
        if ($body === '') {
            return $this->sse()->getEventStream();
        }
        if (!$chat->throttle('send' . $conversation['id'], 1, 1)) {
            return $this->error(Craft::t('quickchat', 'Easy, one message per second.'));
        }

        $chat->addMessage($conversation, $body, false);
        $this->sse()->patchSignals(['qc' => ['body' => '']]);
        $this->sse()->patchElements('<p id="qc-error"></p>');
        // Shown right away rather than on the next poll. Writing reopens a resolved chat, which
        // changes the footer too, so that case re-renders the whole panel.
        if ($conversation['status'] === 'resolved') {
            $this->patchPanel($chat->findById((int) $conversation['id']));
        } else {
            $this->patchMessages((int) $conversation['id']);
        }
        return $this->sse()->getEventStream();
    }

    public function actionStream(): Response
    {
        $this->requirePostRequest();
        $qc = $this->qc();
        $chat = Plugin::getInstance()->chat;
        $conversation = $this->conversation();
        if (!$conversation) {
            return $this->sse()->getEventStream();
        }
        $id = (int) $conversation['id'];
        $last = (int) ($qc['last'] ?? 0);
        $status = (string) ($qc['st'] ?? '') ?: $conversation['status'];

        return $this->live(function () use ($chat, $id, &$last, $status) {
            $state = $chat->pollState($id);
            if (!$state || $state['status'] !== $status) {
                $this->patchPanel($state ? $chat->findById($id) : null);
                return false;
            }
            if ($state['lastId'] !== $last) {
                $last = $this->patchMessages($id);
            }
            return true;
        }, fn() => $this->sse()->patchElements($this->partial('_widget/stream', ['n' => random_int(1, 999999999)])));
    }

    private function conversation(): ?array
    {
        return Plugin::getInstance()->chat->findByToken($this->qc()['token'] ?? null);
    }

    /** The whole panel, history included. Forgets a token the server doesn't know (anymore). */
    private function patchPanel(?array $conversation): void
    {
        $plugin = Plugin::getInstance();
        $messages = $conversation ? $plugin->chat->messages((int) $conversation['id']) : [];
        $signals = ['last' => $messages ? (int) end($messages)['id'] : 0, 'st' => $conversation['status'] ?? ''];
        if (!$conversation && !empty($this->qc()['token'])) {
            $signals += ['token' => '', '_forget' => true];
        }
        $this->sse()->patchSignals(['qc' => $signals]);
        $this->sse()->patchElements($this->partial('_widget/panel', [
            'settings' => $plugin->getSettings(),
            'isOpen' => $plugin->chat->isOpen(),
            'conversation' => $conversation,
            'messages' => $messages,
            'n' => random_int(1, 999999999),
            'cssUrl' => UrlHelper::rootRelativeUrl(Craft::$app->getAssetManager()->getPublishedUrl($plugin->getBasePath() . '/web/quickchat.css', true)),
        ]));
    }

    /** Re-renders the full list and returns the newest id the page now shows. */
    private function patchMessages(int $conversationId): int
    {
        $messages = Plugin::getInstance()->chat->messages($conversationId);
        $last = $messages ? (int) end($messages)['id'] : 0;
        $this->sse()->patchElements($this->partial('_widget/list', ['messages' => $messages]));
        $this->sse()->patchSignals(['qc' => ['last' => $last]]);
        return $last;
    }

    private function error(string $message): Response
    {
        $this->sse()->patchElements('<p id="qc-error" class="text-danger small mb-0" role="alert">' . htmlspecialchars($message) . '</p>');
        return $this->sse()->getEventStream();
    }

    private function body(array $qc): string
    {
        return mb_substr(trim((string) ($qc['body'] ?? '')), 0, Chat::MAX_BODY);
    }
}
