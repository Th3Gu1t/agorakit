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
