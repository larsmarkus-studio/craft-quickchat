<?php
// php tests/hours.php — no Craft needed; exits non-zero on failure.

namespace craft\db { class Query {} }
namespace yii\base { class Component {} }

namespace {
    require __DIR__ . '/../src/services/Chat.php';

    use larsmarkusstudio\quickchat\services\Chat;

    $at = fn(string $s) => new DateTime($s); // 2026-10-12 is a Monday
    $weekdays = [1, 2, 3, 4, 5];

    assert(Chat::withinHours($weekdays, '09:00', '17:00', $at('2026-10-12 09:00')));
    assert(!Chat::withinHours($weekdays, '09:00', '17:00', $at('2026-10-12 17:00')), 'closing minute is closed');
    assert(!Chat::withinHours($weekdays, '09:00', '17:00', $at('2026-10-12 08:59')));
    assert(!Chat::withinHours($weekdays, '09:00', '17:00', $at('2026-10-17 12:00')), 'Saturday');
    assert(Chat::withinHours(['6'], '09:00', '17:00', $at('2026-10-17 12:00')), 'days arrive as strings from the CP');
    assert(Chat::withinHours($weekdays, '22:00', '02:00', $at('2026-10-12 23:30')), 'spans midnight, evening');
    assert(Chat::withinHours($weekdays, '22:00', '02:00', $at('2026-10-13 01:00')), 'spans midnight, morning');
    assert(!Chat::withinHours($weekdays, '22:00', '02:00', $at('2026-10-12 12:00')));

    echo "hours: ok\n";
}
