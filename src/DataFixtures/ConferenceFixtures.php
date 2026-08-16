<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Conference;
use App\Entity\Proposal;
use App\Entity\Room;
use App\Entity\Slot;
use App\Entity\Speaker;
use App\Entity\Talk;
use App\Enum\Level;
use App\Enum\Track;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * A small, entirely deterministic conference programme.
 *
 * The regression suite asserts against these exact rows, so changing them means
 * changing tests — see tests/Regression.
 */
final class ConferenceFixtures extends Fixture
{
    public const DAY_ONE = '2026-11-19';
    public const DAY_TWO = '2026-11-20';

    public function load(ObjectManager $manager): void
    {
        $conference = new Conference(
            name: 'SymfonyCon',
            edition: 'Berlin 2026',
            city: 'Berlin',
            startsOn: new \DateTimeImmutable(self::DAY_ONE),
            endsOn: new \DateTimeImmutable(self::DAY_TWO),
            website: 'https://symfony.com/symfonycon',
            timezone: 'Europe/Berlin',
        );
        $manager->persist($conference);

        $rooms = [];
        foreach ([['Grand Ballroom', 800, 'Ground'], ['Studio A', 240, 'First'], ['Workshop Lab', 90, 'Second']] as [$name, $capacity, $floor]) {
            $room = new Room($name, $capacity, $floor);
            $manager->persist($room);
            $rooms[$name] = $room;
        }

        $speakers = [];
        foreach ($this->speakerData() as $data) {
            $speaker = new Speaker(...$data);
            $manager->persist($speaker);
            $speakers[$data['slug']] = $speaker;
        }

        $talks = [];
        foreach ($this->talkData() as $data) {
            $data['speaker'] = $speakers[$data['speaker']];
            $talk = new Talk(...$data);
            $manager->persist($talk);
            $talks[$data['slug']] = $talk;
        }

        foreach ($this->slotData() as [$talkSlug, $roomName, $startsAt]) {
            $manager->persist(new Slot($talks[$talkSlug], $rooms[$roomName], new \DateTimeImmutable($startsAt)));
        }

        foreach ($this->proposalData() as $data) {
            $manager->persist(new Proposal(...$data));
        }

        $manager->flush();
    }

    /**
     * @return list<array{slug: string, name: string, email: string, bio: string, company: string, country: string, mastodon: string}>
     */
    private function speakerData(): array
    {
        return [
            [
                'slug' => 'nadia-fischer',
                'name' => 'Nadia Fischer',
                'email' => 'nadia@example.com',
                'bio' => 'Backend engineer with a decade of Symfony in production and a weakness for message queues.',
                'company' => 'Kettenwerk',
                'country' => 'DE',
                'mastodon' => '@nadia@phpc.social',
            ],
            [
                'slug' => 'tomas-brandt',
                'name' => 'Tomás Brandt',
                'email' => 'tomas@example.com',
                'bio' => 'Platform engineer. Spends his days making deploys boring and his nights reading RFCs.',
                'company' => 'Nordsee Digital',
                'country' => 'PT',
                'mastodon' => '@tomas@phpc.social',
            ],
            [
                'slug' => 'ayesha-rahman',
                'name' => 'Ayesha Rahman',
                'email' => 'ayesha@example.com',
                'bio' => 'Frontend architect who believes the fastest JavaScript is the one you never shipped.',
                'company' => 'Freelance',
                'country' => 'GB',
                'mastodon' => '@ayesha@front-end.social',
            ],
            [
                'slug' => 'lukas-meier',
                'name' => 'Lukas Meier',
                'email' => 'lukas@example.com',
                'bio' => 'Works on developer tooling and open source maintenance sustainability.',
                'company' => 'Helvetic Labs',
                'country' => 'CH',
                'mastodon' => '@lukas@phpc.social',
            ],
            [
                'slug' => 'iris-okonkwo',
                'name' => 'Iris Okonkwo',
                'email' => 'iris@example.com',
                'bio' => 'Machine learning engineer bringing LLM workloads into ordinary web applications.',
                'company' => 'Lagos AI Collective',
                'country' => 'NG',
                'mastodon' => '@iris@sigmoid.social',
            ],
            [
                'slug' => 'marek-nowak',
                'name' => 'Marek Nowak',
                'email' => 'marek@example.com',
                'bio' => 'Consultant. Has migrated more legacy monoliths than he cares to admit.',
                'company' => 'Wisła Software',
                'country' => 'PL',
                'mastodon' => '@marek@phpc.social',
            ],
            [
                'slug' => 'sofia-marino',
                'name' => 'Sofia Marino',
                'email' => 'sofia@example.com',
                'bio' => 'Community organiser and accessibility advocate.',
                'company' => 'PHP Roma',
                'country' => 'IT',
                'mastodon' => '@sofia@phpc.social',
            ],
            [
                'slug' => 'kenji-watanabe',
                'name' => 'Kenji Watanabe',
                'email' => 'kenji@example.com',
                'bio' => 'Database engineer with strong opinions about transaction isolation levels.',
                'company' => 'Tokyo Data Works',
                'country' => 'JP',
                'mastodon' => '@kenji@phpc.social',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function talkData(): array
    {
        return [
            [
                'slug' => 'model-context-protocol-in-symfony',
                'title' => 'Model Context Protocol in Symfony',
                'abstract' => 'MCP gives AI hosts a uniform way to reach your application. We build a server with tools, prompts and resources, wire a client to a remote server, and look at what the protocol asks of a PHP process.',
                'speaker' => 'iris-okonkwo',
                'track' => Track::AI,
                'level' => Level::Intermediate,
                'durationMinutes' => 45,
                'tags' => ['mcp', 'ai', 'protocol'],
            ],
            [
                'slug' => 'messenger-at-scale',
                'title' => 'Messenger at Scale',
                'abstract' => 'Running Symfony Messenger with millions of messages a day: transports, failure handling, back pressure, and the operational habits that keep a queue healthy.',
                'speaker' => 'nadia-fischer',
                'track' => Track::Backend,
                'level' => Level::Advanced,
                'durationMinutes' => 45,
                'tags' => ['messenger', 'queues', 'scaling'],
            ],
            [
                'slug' => 'doctrine-without-tears',
                'title' => 'Doctrine Without Tears',
                'abstract' => 'The identity map, the unit of work, and the six queries you did not know you were running. A practical tour of what Doctrine actually does.',
                'speaker' => 'kenji-watanabe',
                'track' => Track::Backend,
                'level' => Level::Intermediate,
                'durationMinutes' => 45,
                'tags' => ['doctrine', 'orm', 'database'],
            ],
            [
                'slug' => 'the-html-over-the-wire-revival',
                'title' => 'The HTML-over-the-Wire Revival',
                'abstract' => 'Turbo, Live Components and a server that renders. How much interactivity can you ship before you need a build step?',
                'speaker' => 'ayesha-rahman',
                'track' => Track::Frontend,
                'level' => Level::Beginner,
                'durationMinutes' => 45,
                'tags' => ['twig', 'ux', 'turbo'],
            ],
            [
                'slug' => 'deploying-symfony-on-kubernetes',
                'title' => 'Deploying Symfony on Kubernetes',
                'abstract' => 'Probes, migrations, warm caches and zero-downtime rollouts, without a YAML file you cannot explain to a colleague.',
                'speaker' => 'tomas-brandt',
                'track' => Track::DevOps,
                'level' => Level::Advanced,
                'durationMinutes' => 45,
                'tags' => ['kubernetes', 'deployment', 'ops'],
            ],
            [
                'slug' => 'hexagonal-symfony',
                'title' => 'Hexagonal Symfony',
                'abstract' => 'Where the framework ends and the domain begins. A concrete look at ports, adapters, and the cost of each boundary you draw.',
                'speaker' => 'marek-nowak',
                'track' => Track::Architecture,
                'level' => Level::Advanced,
                'durationMinutes' => 45,
                'tags' => ['architecture', 'ddd', 'design'],
            ],
            [
                'slug' => 'maintaining-open-source-without-burning-out',
                'title' => 'Maintaining Open Source Without Burning Out',
                'abstract' => 'Issue triage, funding, saying no, and the quiet work of handing a project over. Notes from eight years of maintenance.',
                'speaker' => 'lukas-meier',
                'track' => Track::Community,
                'level' => Level::Beginner,
                'durationMinutes' => 30,
                'tags' => ['open-source', 'community', 'maintenance'],
            ],
            [
                'slug' => 'accessibility-is-a-backend-problem',
                'title' => 'Accessibility Is a Backend Problem',
                'abstract' => 'Semantics start in the template, and templates start in the controller. Where server-side decisions decide what a screen reader can do.',
                'speaker' => 'sofia-marino',
                'track' => Track::Frontend,
                'level' => Level::Intermediate,
                'durationMinutes' => 30,
                'tags' => ['a11y', 'twig', 'html'],
            ],
            [
                'slug' => 'rag-pipelines-in-php',
                'title' => 'RAG Pipelines in PHP',
                'abstract' => 'Chunking, embeddings, vector stores and evaluation — assembled with the Symfony AI components rather than a Python detour.',
                'speaker' => 'iris-okonkwo',
                'track' => Track::AI,
                'level' => Level::Intermediate,
                'durationMinutes' => 45,
                'tags' => ['ai', 'rag', 'embeddings'],
            ],
            [
                'slug' => 'observability-for-php-applications',
                'title' => 'Observability for PHP Applications',
                'abstract' => 'Traces, metrics and structured logs in a request-scoped runtime. What OpenTelemetry gives you and what it costs.',
                'speaker' => 'tomas-brandt',
                'track' => Track::DevOps,
                'level' => Level::Intermediate,
                'durationMinutes' => 45,
                'tags' => ['observability', 'otel', 'ops'],
            ],
            [
                'slug' => 'the-shape-of-a-good-api',
                'title' => 'The Shape of a Good API',
                'abstract' => 'Naming, errors, versioning and the fact that every API is a promise. Lessons from APIs that aged well and ones that did not.',
                'speaker' => 'marek-nowak',
                'track' => Track::Architecture,
                'level' => Level::Beginner,
                'durationMinutes' => 30,
                'tags' => ['api', 'design', 'rest'],
            ],
            [
                'slug' => 'postgres-features-you-are-not-using',
                'title' => 'Postgres Features You Are Not Using',
                'abstract' => 'Partial indexes, generated columns, LISTEN/NOTIFY and range types — from a PHP application that already has them installed.',
                'speaker' => 'kenji-watanabe',
                'track' => Track::Backend,
                'level' => Level::Advanced,
                'durationMinutes' => 45,
                'tags' => ['postgres', 'database', 'sql'],
            ],
            // Deliberately left off the schedule: the `schedule_talk` tool needs candidates.
            [
                'slug' => 'testing-what-matters',
                'title' => 'Testing What Matters',
                'abstract' => 'A test suite is a budget. Where to spend it, and how to tell a test that protects you from one that only slows you down.',
                'speaker' => 'nadia-fischer',
                'track' => Track::Backend,
                'level' => Level::Intermediate,
                'durationMinutes' => 45,
                'tags' => ['testing', 'phpunit', 'quality'],
            ],
            [
                'slug' => 'running-a-local-user-group',
                'title' => 'Running a Local User Group',
                'abstract' => 'Venues, sponsors, speakers and the meeting that nobody comes to. Practical advice for the first two years.',
                'speaker' => 'sofia-marino',
                'track' => Track::Community,
                'level' => Level::Beginner,
                'durationMinutes' => 30,
                'tags' => ['community', 'meetup'],
            ],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function slotData(): array
    {
        return [
            ['model-context-protocol-in-symfony', 'Grand Ballroom', self::DAY_ONE.' 09:30:00'],
            ['messenger-at-scale', 'Studio A', self::DAY_ONE.' 09:30:00'],
            ['maintaining-open-source-without-burning-out', 'Workshop Lab', self::DAY_ONE.' 09:30:00'],
            ['doctrine-without-tears', 'Grand Ballroom', self::DAY_ONE.' 11:00:00'],
            ['the-html-over-the-wire-revival', 'Studio A', self::DAY_ONE.' 11:00:00'],
            ['accessibility-is-a-backend-problem', 'Workshop Lab', self::DAY_ONE.' 11:00:00'],
            ['deploying-symfony-on-kubernetes', 'Grand Ballroom', self::DAY_ONE.' 14:00:00'],
            ['hexagonal-symfony', 'Studio A', self::DAY_ONE.' 14:00:00'],
            ['rag-pipelines-in-php', 'Grand Ballroom', self::DAY_TWO.' 09:30:00'],
            ['observability-for-php-applications', 'Studio A', self::DAY_TWO.' 09:30:00'],
            ['the-shape-of-a-good-api', 'Workshop Lab', self::DAY_TWO.' 09:30:00'],
            ['postgres-features-you-are-not-using', 'Grand Ballroom', self::DAY_TWO.' 11:00:00'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function proposalData(): array
    {
        return [
            [
                'title' => 'Property Hooks in Anger',
                'abstract' => 'PHP 8.4 property hooks a year in: where they replaced getters, where they made things worse, and what they cost at runtime.',
                'speakerName' => 'Renata Silva',
                'speakerEmail' => 'renata@example.com',
                'track' => Track::Backend,
                'level' => Level::Intermediate,
                'submittedAt' => new \DateTimeImmutable('2026-06-02 10:14:00'),
            ],
            [
                'title' => 'The Cost of a Cache Miss',
                'abstract' => 'Measuring, not guessing: a walk through cache stampedes, early expiration and the tag invalidation that quietly took down production.',
                'speakerName' => 'Ivan Petrov',
                'speakerEmail' => 'ivan@example.com',
                'track' => Track::Backend,
                'level' => Level::Advanced,
                'submittedAt' => new \DateTimeImmutable('2026-06-05 16:40:00'),
            ],
            [
                'title' => 'Designing for the Second Language',
                'abstract' => 'Translation is not a string table. What internationalisation asks of your routes, your database and your date formats.',
                'speakerName' => 'Amara Diallo',
                'speakerEmail' => 'amara@example.com',
                'track' => Track::Frontend,
                'level' => Level::Beginner,
                'submittedAt' => new \DateTimeImmutable('2026-06-11 08:05:00'),
            ],
            [
                'title' => 'Terraform for People Who Write PHP',
                'abstract' => 'Enough infrastructure-as-code to own your own staging environment, and the parts you should still hand to somebody else.',
                'speakerName' => 'Bjorn Lindqvist',
                'speakerEmail' => 'bjorn@example.com',
                'track' => Track::DevOps,
                'level' => Level::Beginner,
                'submittedAt' => new \DateTimeImmutable('2026-06-14 21:22:00'),
            ],
        ];
    }
}
