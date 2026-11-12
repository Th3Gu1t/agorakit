<?php

namespace Tests;

use App\Discussion;
use App\Group;
use App\Membership;
use App\Poll;
use App\User;
use Carbon\Carbon;

/**
 * Votes held in discussions.
 *
 * The first test creates a group of its own, with members whose roles the
 * other tests rely on:
 * - alice, bob and carol are members, and alice starts the votes;
 * - erin is an admin of the group;
 * - dave is not a member.
 */
class PollTest extends BaseTest
{
    private const GROUP = 'Voting group';
    private const DISCUSSION = 'Should we buy a printer?';

    private function user(string $name): User
    {
        return User::where('email', $name . '@agorakit.local')->firstOrFail();
    }

    private function group(): Group
    {
        return Group::where('name', self::GROUP)->firstOrFail();
    }

    private function discussion(): Discussion
    {
        return Discussion::where('name', self::DISCUSSION)->firstOrFail();
    }

    private function createUser(string $name): User
    {
        $user = User::create([
            'name' => ucfirst($name),
            'email' => $name . '@agorakit.local',
            'password' => bcrypt('123456789'),
        ]);
        $user->confirmEmail();

        return $user;
    }

    /**
     * Creates a poll directly, as the controller would.
     *
     * @param string[] $options keys of a proposal, or labels of a choice
     */
    private function createPoll(string $question, string $type = Poll::PROPOSAL, array $options = []): Poll
    {
        $poll = new Poll(['type' => $type, 'question' => $question]);
        $poll->user()->associate($this->user('alice'));
        $this->discussion()->polls()->save($poll);

        $options = $options ?: Poll::PROPOSAL_OPTIONS;
        foreach ($options as $position => $option) {
            $attribute = $type === Poll::PROPOSAL ? 'key' : 'label';
            $poll->options()->create([$attribute => $option, 'position' => $position]);
        }

        return $poll->fresh();
    }

    public function testSetUpTheVotingGroup()
    {
        $group = new Group();
        $group->name = self::GROUP;
        $group->body = 'A group where decisions are taken together';
        $group->save();

        foreach (['alice', 'bob', 'carol', 'erin', 'dave'] as $name) {
            $this->createUser($name);
        }
        foreach (['alice', 'bob', 'carol'] as $name) {
            Membership::create(['group_id' => $group->id, 'user_id' => $this->user($name)->id, 'membership' => Membership::MEMBER]);
        }
        Membership::create(['group_id' => $group->id, 'user_id' => $this->user('erin')->id, 'membership' => Membership::ADMIN]);

        $discussion = new Discussion(['name' => self::DISCUSSION, 'body' => 'Our printer is broken.']);
        $discussion->user()->associate($this->user('alice'));
        $discussion->group()->associate($group);
        $discussion->save();

        $this->assertCount(4, $group->users()->get());
    }

    public function testPollIsOpenUntilClosedByHand()
    {
        $poll = $this->createPoll('Open until closed?');
        $this->assertTrue($poll->isOpen());
        $this->assertNull($poll->closedAt());

        $poll->close();

        $this->assertFalse($poll->fresh()->isOpen());
        $this->assertNotNull($poll->fresh()->closedAt());
    }

    public function testPollClosesAtItsClosingDate()
    {
        $poll = $this->createPoll('Closes by itself?');
        $poll->closes_at = Carbon::now()->addDay();
        $poll->save();
        $this->assertTrue($poll->isOpen());

        Carbon::setTestNow(Carbon::now()->addDays(2));
        try {
            $this->assertFalse($poll->isOpen());
            $this->assertEquals($poll->closes_at, $poll->closedAt());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testElectorateIsTheMembersOfTheGroup()
    {
        $poll = $this->createPoll('Who votes?');

        $electorate = $poll->electorate()->pluck('email')->sort()->values()->all();

        $this->assertSame([
            'alice@agorakit.local',
            'bob@agorakit.local',
            'carol@agorakit.local',
            'erin@agorakit.local',
        ], $electorate);
        $this->assertCount(4, $poll->nonVoters());
    }

    private function createPath(): string
    {
        return route('groups.discussions.polls.create', [$this->group(), $this->discussion()]);
    }

    public function testMemberStartsAProposal()
    {
        $this->actingAs($this->user('alice'))
            ->visit(route('groups.discussions.show', [$this->group(), $this->discussion()]))
            ->click(__('Start a vote'))
            ->seePageIs($this->createPath())
            ->select('proposal', 'type')
            ->type('We buy a laser printer', 'question')
            ->type('About 300 euros.', 'body')
            ->type('', 'closes_at_date')
            ->press(__('Start the vote'))
            ->see(__('The vote is open.'))
            ->see('We buy a laser printer')
            ->see('About 300 euros.')
            ->see(__('No closing date'));

        $poll = Poll::where('question', 'We buy a laser printer')->firstOrFail();
        $this->assertSame(Poll::PROPOSAL, $poll->type);
        $this->assertSame('alice@agorakit.local', $poll->user->email);
        $this->assertSame(Poll::PROPOSAL_OPTIONS, $poll->options->pluck('key')->all());
        $this->assertNull($poll->closes_at);
    }

    public function testProposalMayAllowBlocking()
    {
        $this->actingAs($this->user('alice'))
            ->visit($this->createPath())
            ->select('proposal', 'type')
            ->type('We change the statutes', 'question')
            ->check('allow_block')
            ->press(__('Start the vote'));

        $poll = Poll::where('question', 'We change the statutes')->firstOrFail();
        $this->assertSame(['agree', 'abstain', 'disagree', 'block'], $poll->options->pluck('key')->all());
    }

    public function testMemberStartsAChoiceWithAClosingDate()
    {
        $date = Carbon::now()->addDays(3);

        $this->actingAs($this->user('alice'))
            ->visit($this->createPath())
            ->select('choice', 'type')
            ->type('Which printer?', 'question')
            ->type("Laser\n\n  Inkjet  \nlaser\nThermal", 'options')
            ->type($date->format('Y-m-d'), 'closes_at_date')
            ->type('18:30', 'closes_at_time')
            ->press(__('Start the vote'))
            ->see('Which printer?')
            ->see('Inkjet');

        $poll = Poll::where('question', 'Which printer?')->firstOrFail();
        $this->assertSame(Poll::CHOICE, $poll->type);
        // blank lines and a repeat (any case) are left out, the order is kept
        $this->assertSame(['Laser', 'Inkjet', 'Thermal'], $poll->options->pluck('label')->all());
        $this->assertSame($date->format('Y-m-d') . ' 18:30', $poll->closes_at->format('Y-m-d H:i'));
    }

    public function testChoiceNeedsTwoOptions()
    {
        $this->actingAs($this->user('alice'))
            ->visit($this->createPath())
            ->select('choice', 'type')
            ->type('Only one way?', 'question')
            ->type("Yes\nyes", 'options')
            ->press(__('Start the vote'))
            ->see(__('A choice needs at least two different options, one per line.'))
            ->seeInField('question', 'Only one way?');

        $this->assertFalse(Poll::where('question', 'Only one way?')->exists());
    }

    public function testClosingDateMustBeInTheFuture()
    {
        $this->actingAs($this->user('alice'))
            ->visit($this->createPath())
            ->select('proposal', 'type')
            ->type('Back to the past', 'question')
            ->type(Carbon::now()->subDay()->format('Y-m-d'), 'closes_at_date')
            ->press(__('Start the vote'))
            ->see(__('The closing date must be in the future.'));

        $this->assertFalse(Poll::where('question', 'Back to the past')->exists());
    }

    public function testNonMemberCannotStartAVote()
    {
        $this->actingAs($this->user('dave'))
            ->get($this->createPath())
            ->assertResponseStatus(403);

        $this->actingAs($this->user('dave'))
            ->post(route('groups.discussions.polls.store', [$this->group(), $this->discussion()]), [
                'type' => 'proposal',
                'question' => 'Intruder proposal',
            ])
            ->assertResponseStatus(403);

        $this->assertFalse(Poll::where('question', 'Intruder proposal')->exists());
    }

    public function testNonMemberDoesNotSeeTheButton()
    {
        $this->actingAs($this->user('dave'))
            ->visit(route('groups.discussions.show', [$this->group(), $this->discussion()]))
            ->dontSee(__('Start a vote'));
    }

    public function testDiscussionMustBelongToTheGroup()
    {
        $otherGroup = $this->getTestGroup();

        $this->actingAs($this->user('alice'))
            ->get(route('groups.discussions.polls.create', [$otherGroup, $this->discussion()]))
            ->assertResponseStatus(404);
    }

    public function testNoVoteInAnArchivedDiscussion()
    {
        $discussion = $this->discussion();
        $discussion->archive();
        $discussion->save();

        try {
            $this->actingAs($this->user('alice'))
                ->get($this->createPath())
                ->assertResponseStatus(403);
        } finally {
            $discussion->unArchive();
            $discussion->save();
        }
    }

    public function testProposalOptionsAreTranslated()
    {
        $poll = $this->createPoll('Translated options?');

        app()->setLocale('fr');
        try {
            $this->assertSame(['Pour', 'Abstention', 'Contre'], $poll->options->map->name()->all());
        } finally {
            app()->setLocale('en');
        }
    }
}
