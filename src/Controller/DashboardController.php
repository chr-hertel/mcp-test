<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ConferenceRepository;
use App\Repository\ProposalRepository;
use App\Repository\SlotRepository;
use App\Repository\SpeakerRepository;
use App\Repository\TalkRepository;
use App\Service\ProgrammePresenter;
use Mcp\Capability\RegistryInterface;
use Mcp\Server\Builder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * A plain web front end for the same data the MCP servers expose.
 *
 * Its point is the contrast: the tables of talks and speakers are what a person
 * reads, the `/mcp` endpoints are what a model reads, and both sit on the same
 * Doctrine entities behind the same services. The MCP overview is introspected
 * from the running container, so it cannot drift from what is actually served.
 */
final class DashboardController extends AbstractController
{
    /**
     * @param ServiceProviderInterface<Builder>           $builders   the `mcp.server_locator.builder` locator
     * @param ServiceProviderInterface<RegistryInterface> $registries the `mcp.server_locator.registry` locator
     */
    public function __construct(
        private readonly ServiceProviderInterface $builders,
        private readonly ServiceProviderInterface $registries,
        private readonly RouterInterface $router,
        private readonly ConferenceRepository $conferences,
        private readonly TalkRepository $talks,
        private readonly SpeakerRepository $speakers,
        private readonly SlotRepository $slots,
        private readonly ProposalRepository $proposals,
        private readonly ProgrammePresenter $presenter,
    ) {
    }

    #[Route('/', name: 'dashboard')]
    public function index(): Response
    {
        $servers = $this->servers();

        return $this->render('dashboard/index.html.twig', [
            'conference' => $this->conferences->current(),
            'servers' => $servers,
            'endpoints' => implode(', ', array_filter(array_column($servers, 'endpoint'))),
            'counts' => [
                'talks' => \count($this->talks->findAll()),
                'speakers' => \count($this->speakers->findAllOrdered()),
                'scheduled' => \count($this->slots->findAllOrdered()),
                'unscheduled' => \count($this->talks->findUnscheduled()),
                'proposals' => \count($this->proposals->findByStatus()),
            ],
        ]);
    }

    #[Route('/schedule/{day}', name: 'schedule', requirements: ['day' => '\d{4}-\d{2}-\d{2}'])]
    public function schedule(?string $day = null): Response
    {
        $conference = $this->conferences->current();
        $days = $conference->getDays();
        $day = null !== $day && \in_array($day, $days, true) ? $day : $days[0];

        return $this->render('dashboard/schedule.html.twig', [
            'conference' => $conference,
            'day' => $day,
            'days' => $days,
            'slots' => array_map($this->presenter->slot(...), $this->slots->findForDay($day)),
            'unscheduled' => $this->talks->findUnscheduled(),
        ]);
    }

    #[Route('/speakers', name: 'speakers')]
    public function speakers(): Response
    {
        return $this->render('dashboard/speakers.html.twig', [
            'conference' => $this->conferences->current(),
            'speakers' => array_map($this->presenter->speaker(...), $this->speakers->findAllOrdered()),
        ]);
    }

    /**
     * What each configured MCP server actually exposes, read from its registry.
     *
     * Building a server is what populates its registry: the bundle turns every
     * attributed service into an `addTool()`/`addPrompt()`/… call on the builder
     * definition at compile time, and those calls run when `build()` does.
     *
     * @return list<array{
     *     name: string, endpoint: string|null,
     *     tools: list<array{name: string, title: string|null, description: string|null, readOnly: bool}>,
     *     prompts: list<array{name: string, description: string|null}>,
     *     resources: list<array{uri: string, mimeType: string|null}>,
     *     templates: list<array{uriTemplate: string, mimeType: string|null}>
     * }>
     */
    private function servers(): array
    {
        $servers = [];

        foreach (array_keys($this->registries->getProvidedServices()) as $name) {
            $this->builders->get($name)->build();
            $registry = $this->registries->get($name);

            $tools = [];
            foreach ($registry->getTools()->references as $tool) {
                $tools[] = [
                    'name' => $tool->name,
                    'title' => $tool->title,
                    'description' => $tool->description,
                    'readOnly' => true === $tool->annotations?->readOnlyHint,
                ];
            }

            $prompts = [];
            foreach ($registry->getPrompts()->references as $prompt) {
                $prompts[] = ['name' => $prompt->name, 'description' => $prompt->description];
            }

            $resources = [];
            foreach ($registry->getResources()->references as $resource) {
                $resources[] = ['uri' => $resource->uri, 'mimeType' => $resource->mimeType];
            }

            $templates = [];
            foreach ($registry->getResourceTemplates()->references as $template) {
                $templates[] = ['uriTemplate' => $template->uriTemplate, 'mimeType' => $template->mimeType];
            }

            $servers[] = [
                'name' => $name,
                'endpoint' => $this->endpoint($name),
                'tools' => $tools,
                'prompts' => $prompts,
                'resources' => $resources,
                'templates' => $templates,
            ];
        }

        return $servers;
    }

    /**
     * The HTTP path the bundle's route loader gave this server, if it has one.
     */
    private function endpoint(string $name): ?string
    {
        return $this->router->getRouteCollection()->get('_mcp_endpoint_'.$name)?->getPath();
    }
}
