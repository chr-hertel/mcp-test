<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\ProgrammeSeeder;
use App\Tests\Support\McpTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The chat page, as a browser sees it.
 *
 * The page is not only the conversation: it is the host's view of what it is
 * connected to, read from the servers themselves at request time. Everything
 * asserted here therefore travelled over MCP first — the tool names, the prompt
 * list, the resources, and the values behind the prompt form's completions.
 */
#[Group('regression')]
final class ChatPageTest extends McpTestCase
{
    public function testThePageShowsWhatTheServersAdvertise(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/chat');

        self::assertResponseIsSuccessful();

        // Tools, as the model will see them.
        self::assertStringContainsString('search_talks', $crawler->filter('aside')->text());
        // A write tool exists, but only on the privileged connection.
        self::assertStringContainsString('schedule_talk', $crawler->filter('aside')->text());

        // Prompts are the user's half of the protocol: they link to a form.
        self::assertGreaterThan(0, $crawler->filter('a[href*="/chat/prompt/conference/"]')->count());

        // Resources are the user's to attach.
        self::assertStringContainsString('conference://current', $crawler->filter('aside')->text());

        // With no key configured the page says which model is answering.
        self::assertStringContainsString('scripted model', $crawler->filter('.chat-head')->text());
    }

    public function testAPromptFormOffersTheServersOwnCompletions(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/chat/prompt/conference/review_schedule_day');

        self::assertResponseIsSuccessful();

        // completion/complete: the days come from the server, not from this form.
        $options = $crawler->filter('datalist option')->extract(['value']);
        self::assertContains(ProgrammeSeeder::DAY_ONE, $options);
    }

    public function testRunningAPromptStartsATurnAndReturnsToTheChat(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/chat/prompt/conference/review_schedule_day');

        $client->submit($crawler->selectButton('Run in the chat')->form([
            'argument_day' => ProgrammeSeeder::DAY_ONE,
        ]));

        self::assertResponseRedirects('/chat');

        $crawler = $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Doctrine Without Tears', $crawler->filter('.chat-transcript')->text());
    }

    public function testTheMarkdownAnAnswerIsRenderedAsCannotSmuggleHtml(): void
    {
        self::bootKernel();

        // An answer is whatever a tool put in front of the model, and a tool
        // result is whatever is in the database. CommonMark passes raw HTML
        // through unless told otherwise; this is where it is told otherwise.
        $markdown = self::getContainer()->get('twig.markdown.default');

        $html = $markdown->convert('<img src=x onerror=alert(1)> and **bold**');

        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function testAnUnknownPromptIsNotFound(): void
    {
        $client = self::createClient();
        $client->request('GET', '/chat/prompt/conference/does_not_exist');

        self::assertResponseStatusCodeSame(404);
    }
}
