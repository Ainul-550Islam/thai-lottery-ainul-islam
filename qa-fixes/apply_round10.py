#!/usr/bin/env python3
"""Round 10: bet-page fixture. The market list renders only while a draw is open.

Idempotent. Adds an open-draw fixture to each test that requests the bet page in
tests/Feature/Player/PlayerFrontendModulesTest.php. Assertions are not changed.
"""
from pathlib import Path

ROOT = Path('/home/user/thai-lottery-ainul-islam')
REL = 'tests/Feature/Player/PlayerFrontendModulesTest.php'
REQUEST = "        $content = (string) $this->actingAs($this->player)\n            ->get(route('player.bet'))"
FIXTURE = (
    "        // The market list is only rendered while a draw is open, so the fixture must supply one.\n"
    "        Draw::factory()\n"
    "            ->open()\n"
    "            ->create([\n"
    "                'scheduled_at' => now()->addDay(),\n"
    "                'betting_close_at' => now()->addHours(3),\n"
    "            ]);\n\n"
)

def main():
    path = ROOT / REL
    s = path.read_text()
    if FIXTURE in s:
        print(f'{REL}: already applied')
        return
    n = s.count(REQUEST)
    if n == 0:
        raise SystemExit(f'{REL}: request anchor not found')
    s = s.replace(REQUEST, FIXTURE + REQUEST)
    path.write_text(s)
    print(f'{REL}: applied to {n} bet-page request(s)')

if __name__ == '__main__':
    main()
