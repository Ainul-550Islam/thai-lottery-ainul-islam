"""Round 8: DrawResourceTest follows the four-eyes result contract.

The Filament resource no longer publishes directly. Its `publish` action became `stageResult`, which
only creates a Pending ingestion through DrawResultIngestionService::ingest(). A different persisted
operator must confirm it through DrawResultConfirmationService::confirm() before anything is published.
The three publish tests predate that control. They are rewritten to assert the current contract. The
control itself is not weakened, and no resource code changes.

Idempotent. Run after a sandbox reset:  python3 /home/user/qa-fixes/apply_round8.py
"""

TEST = '/home/user/thai-lottery-ainul-islam/tests/Feature/Filament/DrawResourceTest.php'

OLD_OFFERED = """    public function test_publish_is_offered_only_while_awaiting_numbers(): void
    {
        $awaiting = $this->draw(DrawStatus::Drawing);
        $open = $this->draw(DrawStatus::Open);

        Livewire::actingAs($this->operator())
            ->test(ListDraws::class, ['activeTab' => 'all'])
            ->assertTableActionVisible('publish', record: $awaiting)
            ->assertTableActionHidden('publish', record: $open);
    }
"""

NEW_OFFERED = """    public function test_staging_is_offered_only_while_awaiting_numbers(): void
    {
        $awaiting = $this->draw(DrawStatus::Drawing);
        $open = $this->draw(DrawStatus::Open);

        Livewire::actingAs($this->operator())
            ->test(ListDraws::class, ['activeTab' => 'all'])
            ->assertTableActionVisible('stageResult', record: $awaiting)
            ->assertTableActionHidden('stageResult', record: $open);
    }
"""

OLD_WRITES = """    public function test_publishing_writes_the_result_through_the_domain_service(): void
    {
        $draw = $this->draw(DrawStatus::Drawing);

        Livewire::actingAs($this->operator())
            ->test(ViewDraw::class, ['record' => $draw->getKey()])
            ->callAction('publish', data: [
                'first_prize' => '123456',
                'bottom_two' => '56',
            ])
            ->assertHasNoActionErrors();

        $result = DrawResult::query()->where('draw_id', $draw->getKey())->first();

        $this->assertNotNull($result, 'Publishing produced no draw_results row.');
        $this->assertSame('123456', $result->first_prize);
        $this->assertSame(DrawStatus::ResultPublished, $draw->fresh()->status);
    }
"""

NEW_WRITES = """    public function test_staging_creates_a_pending_ingestion_and_publishes_nothing(): void
    {
        $draw = $this->draw(DrawStatus::Drawing);

        Livewire::actingAs($this->operator())
            ->test(ViewDraw::class, ['record' => $draw->getKey()])
            ->callAction('stageResult', data: [
                'first_prize' => '123456',
                'bottom_two' => '56',
            ])
            ->assertHasNoActionErrors();

        // The maker's staging is Pending. It is not a published result.
        $pending = app(\\App\\Services\\Draw\\DrawResultIngestionService::class)->pendingIngestion((int) $draw->getKey());

        $this->assertNotNull($pending, 'Staging produced no pending ingestion.');
        $this->assertSame('123456', $pending['payload']['first_prize']);
        $this->assertDatabaseCount('draw_results', 0);
        $this->assertSame(DrawStatus::Drawing, $draw->fresh()->status);
    }
"""

OLD_REJECTS = """        Livewire::actingAs($this->operator())
            ->test(ViewDraw::class, ['record' => $draw->getKey()])
            ->callAction('publish', data: [
                'first_prize' => '12345',
                'bottom_two' => '56',
            ])
            ->assertHasActionErrors(['first_prize']);"""

NEW_REJECTS = """        Livewire::actingAs($this->operator())
            ->test(ViewDraw::class, ['record' => $draw->getKey()])
            ->callAction('stageResult', data: [
                'first_prize' => '12345',
                'bottom_two' => '56',
            ])
            ->assertHasActionErrors(['first_prize']);"""


def replace_once(s, old, new, label):
    n = s.count(old)
    if n == 0 and new in s:
        return s, False
    assert n == 1, f'{label}: anchor found {n} times'
    return s.replace(old, new, 1), True


def main():
    s = open(TEST).read()
    changed = False
    for old, new, label in [
        (OLD_OFFERED, NEW_OFFERED, 'offered'),
        (OLD_WRITES, NEW_WRITES, 'writes'),
        (OLD_REJECTS, NEW_REJECTS, 'rejects'),
    ]:
        s, did = replace_once(s, old, new, label)
        changed = changed or did
        print(f'{label}: {"applied" if did else "already applied"}')
    if changed:
        open(TEST, 'w').write(s)


if __name__ == '__main__':
    main()


# Pages 77–100 static contract: the admin users route is deliberately bound at /legacy/users.
# routes/web.php documents why: Filament's UserResource owns GET admin/users. This needle followed
# the older binding. The contract is the same (an admin-gated users route), so only the path changes.
STATIC_77 = '/home/user/thai-lottery-ainul-islam/tests/Feature/Pages77To100StaticContractTest.php'
OLD_USERS = "            \"Route::get('/users'\",\n"
NEW_USERS = "            \"Route::get('/legacy/users'\",\n"


def main_static():
    s = open(STATIC_77).read()
    if NEW_USERS in s:
        print('pages 77-100 /legacy/users: already applied')
        return
    assert s.count(OLD_USERS) == 1, f'users needle found {s.count(OLD_USERS)} times'
    open(STATIC_77, 'w').write(s.replace(OLD_USERS, NEW_USERS, 1))
    print('pages 77-100 /legacy/users: applied')


if __name__ == '__main__':
    main_static()
