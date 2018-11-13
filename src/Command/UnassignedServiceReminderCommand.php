<?php
namespace App\Command;

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
        $ctApi = \ChurchTools\Api\RestApi::createWithLoginIdToken('vykoeln', $this->churchToolsLoginId, $this->churchToolsApiToken);
        $masterData = $ctApi->getMasterData();
        $services = $masterData['data']['service'];

        $eventData = $ctApi->getAllEventData();
        $unassignedServices = [];

        $today = date('Y-m-d');
        $nextWeek = date('Y-m-d', strtotime('+1 week'));
        foreach ($eventData['data'] as $event) {
            $startdate = substr($event['startdate'], 0, 10);
            if ($startdate > $nextWeek || $startdate < $today) {
                continue;
            }

            $unassignedService = [
                'bezeichnung' => $event['bezeichnung'],
                'startdate' => $event['startdate'],
                'services' => [],
            ];
            foreach ($event['services'] as $service) {
                if ($service['zugesagt_yn'] == 0 && $service['valid_yn'] == 1) {
                    $unassignedService['services'][] = $services[$service['service_id']]['bezeichnung'];
                }
            }
            $unassignedService['services'] = array_unique($unassignedService['services']);

            if (count($unassignedService['services']) > 0) {
                sort($unassignedService['services']);
                $unassignedServices[$event['id']] = $unassignedService;
            }
        }

        $loop = \React\EventLoop\Factory::create();

        $client = new \Slack\ApiClient($loop);
        $client->setToken($this->slackApiToken);

        foreach ($unassignedServices as $unassignedService) {
//            $client->getGroupByName('spielwiese')
            $client->getChannelByName('allgemein')
                ->then(function (\Slack\Channel $channel) use ($client, $unassignedService) {
                    $messageTemplate = "Folgende Dienste sind für *%s* am *%s* noch unbesetzt:\n%s";
                    $date = (new \DateTime($unassignedService['startdate']))->format('d.m.Y');
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
