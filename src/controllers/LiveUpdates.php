<?php

declare(strict_types=1);

namespace larsmarkusstudio\quickchat\controllers;

use Craft;
use craft\web\View;
use larsmarkusstudio\quickchat\Plugin;
use putyourlightson\datastar\traits\Sse;
use yii\web\Response;

/**
 * What the widget and the CP share: reading the `qc` signals, rendering partials, and the
 * poll-or-hold response around one "check for changes" callback.
 */
trait LiveUpdates
{
    use Sse;

    /**
     * `holdSeconds` 0 (default): runs `$check` once *before* the response starts, so templates can
     * touch the session like any other request. Otherwise keeps checking every second inside the
     * stream until the hold runs out, then calls `$rearm` to make the page open the next one.
     * `$check` returns false to end early.
     */
    protected function live(callable $check, callable $rearm): Response
    {
        $hold = Plugin::getInstance()->getSettings()->holdSeconds;
        if (!$hold) {
            $check();
            return $this->sse()->getEventStream();
        }

        // ponytail: one PHP process per open chat or dashboard while held; pub/sub (Mercure, Redis) if that hurts
        return $this->sse()->getEventStream(function () use ($check, $rearm, $hold) {
            set_time_limit($hold + 15);
            $end = time() + $hold;
            while (true) {
                if ($check() === false) {
                    return; // the check re-rendered what it needed, including the next stream
                }
                if (time() >= $end || connection_aborted()) {
                    break;
                }
                while (ob_get_level() > 0) {
                    ob_end_flush(); // the SSE service leaves a buffer open after each event
                }
                // Comment line: keeps proxies from timing out and lets PHP notice a closed tab
                echo ": ping\n\n";
                flush();
                sleep(1);
            }
            $rearm();
        });
    }

    protected function qc(): array
    {
        $qc = $this->sse()->readSignals()['qc'] ?? [];
        return is_array($qc) ? $qc : [];
    }

    /** CP template mode for both controllers: the plugin's templates live there, even on site requests. */
    protected function partial(string $template, array $vars = []): string
    {
        return Craft::$app->getView()->renderTemplate('quickchat/' . $template, $vars, View::TEMPLATE_MODE_CP);
    }
}
