<?php
namespace App\Command;

use ChurchTools\Api\Event;
use ChurchTools\Api\RestApi;
use ChurchTools\Api\ServiceEntry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Slack\Message\Attachment;

class UnassignedServiceReminderCommand extends Command
{
    private $slackApiToken;
    private $churchToolsLoginId;
    private $churchToolsApiToken;

    public function __construct(string $slackApiToken, string $churchToolsLoginId, string $churchToolsApiToken)
    {
        $this->slackApiToken = $slackApiToken;
        $this->churchToolsLoginId = $churchToolsLoginId;
        $this->churchToolsApiToken = $churchToolsApiToken;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('app:remind:unassigned-services')
            ->setDescription('Sends a reminder message about unassigned services for upcoming events.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ctApi = RestApi::createWithLoginIdToken('vykoeln', $this->churchToolsLoginId, $this->churchToolsApiToken);
        $masterData = $ctApi->getServiceMasterData();
        $services = $masterData->getServiceEntries();

        $eventData = $ctApi->getAllEventData();
        $unassignedServices = [];

        $today = date('Y-m-d');
        $nextWeek = date('Y-m-d', strtotime('+1 week'));
        foreach ($eventData as $event) {
            /** @var Event $event */
            $startdate = $event->getStartDate()->format('Y-m-d');
            if ($startdate > $nextWeek || $startdate < $today) {
                continue;
            }

            $unassignedService = [
                'bezeichnung' => $event->getTitle(),
                'startdate' => $event->getStartDate(),
                'services' => [],
            ];
            foreach ($event->getServiceEntries() as $service) {
                /** @var ServiceEntry $service */
                if (!$service->hasAccepted() && $service->isValid()) {
                    $suffix = '';
                    if ($service->getName()) {
                        $suffix = ' (vorgeschlagen: ' . $service->getName() . ')';
                    }
                    $serviceDefinition = $services->getService($service->getServiceID());
                    $unassignedService['services'][] = $serviceDefinition->getTitle() . $suffix;
                }
            }
            $unassignedService['services'] = array_unique($unassignedService['services']);

            if (count($unassignedService['services']) > 0) {
                sort($unassignedService['services']);
                $unassignedServices[$event->getID()] = $unassignedService;
            }
        }

        $loop = \React\EventLoop\Factory::create();

        $client = new \Slack\ApiClient($loop);
        $client->setToken($this->slackApiToken);

        foreach ($unassignedServices as $unassignedService) {
            $client->getGroupByName('staff')
                ->then(function (\Slack\Channel $channel) use ($client, $unassignedService) {
                    $messageTemplate = "Folgende Dienste sind für *%s* am *%s* noch unbesetzt:\n%s";
                    $date = $unassignedService['startdate']->format('d.m.Y');
                    $servicesText = '• ' . implode("\n• ", $unassignedService['services']);

                    $attachment = new Attachment('', '', 'Schau in ChurchTools nach für mehr Infos');
                    $attachment->data['actions'] = [
                        [
                            "title" => "Link zu ChurchTools",
                            "type" => "button",
                            "text" => "Dienste in ChurchTools aufrufen",
                            "url"  => "https://vykoeln.church.tools/?q=churchservice#ListView/",
                        ]
                    ];

                    $message = $client->getMessageBuilder()
                        ->setText(sprintf($messageTemplate, $unassignedService['bezeichnung'], $date, $servicesText))
                        ->setChannel($channel)
                        ->addAttachment($attachment)
                        ->create();

                    $client->postMessage($message);
                });
        }

        $loop->run();
    }
}
