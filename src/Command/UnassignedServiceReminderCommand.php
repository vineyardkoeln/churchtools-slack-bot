<?php
namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UnassignedServiceReminderCommand extends Command
{
    private $slackApiToken;

    public function __construct(string $slackApiToken)
    {
        $this->slackApiToken = $slackApiToken;

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
        $loop = \React\EventLoop\Factory::create();

        $client = new \Slack\ApiClient($loop);
        $client->setToken($this->slackApiToken);

        $client->getGroupByName('spielwiese')->then(function (\Slack\Channel $channel) use ($client) {
            $message = $client->getMessageBuilder()
                ->setText('Hello world from PHP!')
                ->setChannel($channel)
                ->create();

            $client->postMessage($message);
        });

        $loop->run();
    }
}
