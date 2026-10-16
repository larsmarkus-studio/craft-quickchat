<?php

declare(strict_types=1);

namespace larsmarkusstudio\quickchat\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use larsmarkusstudio\quickchat\Plugin;
use yii\base\Component;

/**
 * Plain table rows, no elements: conversations are private and never need Craft's element features.
 */
class Chat extends Component
{
    public const C = '{{%quickchat_conversations}}';
    public const M = '{{%quickchat_messages}}';
    public const MAX_BODY = 2000;

    public function isOpen(?DateTime $now = null): bool
    {
        $s = Plugin::getInstance()->getSettings();
        // Craft sets PHP's timezone to the system timezone
        return $s->enabled && self::withinHours((array) $s->openDays, $s->openFrom, $s->openTo, $now ?? new DateTime());
    }

    /** Pure, so `tests/hours.php` can check it without Craft. */
    public static function withinHours(array $days, string $from, string $to, DateTime $now): bool
    {
        $time = $now->format('H:i');
        if (!in_array((int) $now->format('N'), array_map('intval', $days), true)) {
            return false;
        }
        return $from <= $to
            ? $time >= $from && $time < $to
            : $time >= $from || $time < $to; // spans midnight
    }

    public function findByToken(mixed $token): ?array
    {
        if (!is_string($token) || !preg_match('/^[a-zA-Z0-9]{32}$/', $token)) {
            return null;
        }
        return (new Query())->from(self::C)->where(['token' => $token])->one() ?: null;
    }

    public function findById(int $id): ?array
    {
        return (new Query())->from(self::C)->where(['id' => $id])->one() ?: null;
    }

    public function start(string $body, ?string $email, string $pageUrl, ?int $siteId): array
    {
        $now = Db::prepareDateForDb(new DateTime());
        Db::insert(self::C, [
            'token' => StringHelper::randomString(32),
            'siteId' => $siteId,
            'email' => $email ?: null,
            'pageUrl' => mb_substr($pageUrl, 0, 500),
            'status' => 'open',
            'lastVisitorAt' => $now,
        ]);
        $conversation = $this->findById((int) Craft::$app->getDb()->getLastInsertID());
        $this->addMessage($conversation, $body, false);
        $this->notify($conversation, $body);
        return $conversation;
    }

    public function addMessage(array $conversation, string $body, bool $fromAgent, ?int $userId = null): void
    {
        Db::insert(self::M, [
            'conversationId' => $conversation['id'],
            'fromAgent' => $fromAgent,
            'userId' => $userId,
            'body' => $body,
        ]);
        $now = Db::prepareDateForDb(new DateTime());
        // A visitor writing again reopens a resolved conversation, like Chatwoot does
        Db::update(self::C, $fromAgent
            ? ['dateUpdated' => $now, 'agentReadAt' => $now]
            : ['dateUpdated' => $now, 'lastVisitorAt' => $now, 'status' => 'open'],
            ['id' => $conversation['id']], updateTimestamp: false);
    }

    public function messages(int $conversationId): array
    {
        return (new Query())->from(self::M)
            ->where(['conversationId' => $conversationId])
            ->orderBy(['id' => SORT_ASC])
            ->collect()
            ->map(fn(array $m) => $m + ['time' => $this->time($m['dateCreated'])])
            ->all();
    }

    /** DB dates are UTC; shown in the system timezone. */
    public function time(?string $utc, string $format = 'short'): string
    {
        return $utc ? Craft::$app->getFormatter()->asTime(new DateTime($utc, new \DateTimeZone('UTC')), $format) : '';
    }

    /** Time for today, date for anything older, so the CP list never passes off an old chat as new. */
    public function when(?string $utc): string
    {
        if (!$utc) {
            return '';
        }
        $date = (new DateTime($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()));
        return $date->format('Y-m-d') === (new DateTime())->format('Y-m-d')
            ? Craft::$app->getFormatter()->asTime($date, 'short')
            : Craft::$app->getFormatter()->asDate($date, 'short');
    }

    /** What a widget poll needs, in one query: the status and the newest message id. */
    public function pollState(int $id): ?array
    {
        $row = (new Query())
            ->select(['status', 'lastId' => (new Query())->select('MAX(id)')->from(self::M)->where('[[conversationId]] = [[c.id]]')])
            ->from(['c' => self::C])
            ->where(['c.id' => $id])
            ->one();
        return $row ? ['status' => $row['status'], 'lastId' => (int) $row['lastId']] : null;
    }

    /** Root-relative, so sites served on several hostnames never make a cross-origin request. */
    public function url(string $action): string
    {
        return UrlHelper::rootRelativeUrl(UrlHelper::actionUrl('quickchat/' . $action));
    }

    /** Newest first, with the last message and an unread flag. */
    public function conversations(int $limit = 100): array
    {
        $last = (new Query())->select('MAX(id)')->from(self::M)->groupBy('conversationId');
        return (new Query())
            ->select(['c.*', 'm.body AS lastBody', 'm.fromAgent AS lastFromAgent'])
            ->from(['c' => self::C])
            ->leftJoin(['m' => self::M], ['and', '[[m.conversationId]] = [[c.id]]', ['m.id' => $last]])
            ->orderBy(['c.status' => SORT_ASC, 'c.dateUpdated' => SORT_DESC]) // 'open' sorts before 'resolved'
            ->limit($limit)
            ->all();
    }

    public function isUnread(array $c): bool
    {
        return $c['status'] === 'open' && $c['lastVisitorAt'] && (!$c['agentReadAt'] || $c['lastVisitorAt'] > $c['agentReadAt']);
    }

    /** Drives the CP chime: any visitor message newer than what the page has seen. */
    public function lastVisitorMessageId(): int
    {
        return (int) (new Query())->from(self::M)->where(['fromAgent' => false])->max('id');
    }

    public function unreadCount(): int
    {
        return (int) (new Query())->from(self::C)
            ->where(['status' => 'open'])
            ->andWhere(['or', ['agentReadAt' => null], '[[lastVisitorAt]] > [[agentReadAt]]'])
            ->count();
    }

    public function markRead(int $id): void
    {
        Db::update(self::C, ['agentReadAt' => Db::prepareDateForDb(new DateTime())], ['id' => $id], updateTimestamp: false);
    }

    /** Same, but a no-op unless something is unread, so polling doesn't bump the CP version every time. */
    public function markReadIfUnread(int $id): void
    {
        Db::update(self::C, ['agentReadAt' => Db::prepareDateForDb(new DateTime())], [
            'and',
            ['id' => $id, 'status' => 'open'],
            ['or', ['agentReadAt' => null], '[[lastVisitorAt]] > [[agentReadAt]]'],
        ], updateTimestamp: false);
    }

    public function setStatus(int $id, string $status): void
    {
        Db::update(self::C, ['status' => $status === 'resolved' ? 'resolved' : 'open'], ['id' => $id]);
    }

    /**
     * Changes whenever any conversation or message changes, so the CP knows to re-render. The
     * resolved count catches a resolve/reopen in the same second as the latest update.
     */
    public function version(): string
    {
        $row = (new Query())
            ->select([
                'm' => (new Query())->select('MAX(id)')->from(self::M),
                'u' => 'MAX([[dateUpdated]])',
                'r' => 'MAX([[agentReadAt]])',
                'n' => "SUM(CASE WHEN [[status]] = 'resolved' THEN 1 ELSE 0 END)",
            ])
            ->from(self::C)
            ->one();
        return implode('|', $row ?: []);
    }

    /**
     * At most `$max` hits per `$seconds` per key; cache-backed, so no cookies or extra tables.
     * A count instead of "once per window", so visitors sharing an IP (offices, a misconfigured
     * proxy) don't block each other.
     */
    public function throttle(string $key, int $max, int $seconds): bool
    {
        $cache = Craft::$app->getCache();
        $key = 'quickchat:' . md5($key);
        // ponytail: get/set isn't atomic, so a burst can slip one or two past the limit; fine for spam control
        [$hits, $until] = $cache->get($key) ?: [0, time() + $seconds];
        if ($hits >= $max) {
            return false;
        }
        // Keeps the window's original end, so later hits don't extend it
        $cache->set($key, [$hits + 1, $until], max(1, $until - time()));
        return true;
    }

    private function notify(array $conversation, string $body): void
    {
        $to = Plugin::getInstance()->getSettings()->getNotifyEmail();
        if (!$to) {
            return;
        }
        try {
            Craft::$app->getMailer()->compose()
                ->setTo($to)
                ->setSubject('New chat on ' . (Craft::$app->getSites()->getSiteById((int) $conversation['siteId'])?->getName() ?? Craft::$app->getSystemName()))
                ->setTextBody($body . "\n\n" . ($conversation['email'] ?: 'no email') . ' · ' . $conversation['pageUrl']
                    . "\n\n" . UrlHelper::cpUrl('quickchat', ['id' => $conversation['id']]))
                ->send();
        } catch (\Throwable $e) {
            // A broken mailer must not lose the visitor's message
            Craft::error('Quickchat notify failed: ' . $e->getMessage(), __METHOD__);
        }
    }
}
