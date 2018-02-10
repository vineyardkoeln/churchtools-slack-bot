<?php
namespace App\Command;

use Slack\DirectMessageChannel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ChatBotDaemon extends Command
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
            ->setName('app:daemon:chatbot')
            ->setDescription('Starts the chat bot')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $loop = \React\EventLoop\Factory::create();

        $client = new \Slack\RealTimeClient($loop);
        $client->setToken($this->slackApiToken);
        $counter = 0;
        $client->on('message', function ($data) use ($client, $counter, $output) {
            $output->writeln('Someone sent us a message: ' . $data['text']);

            $client->getDMByUserId($data['user'])->then(function (DirectMessageChannel $channel) use ($client) {
                $client->send('Hello to you, too!', $channel);
            });

            if ($counter++ > 4) {
                // Exit demo after 5 messages
                $output->writeln('We\'re done here. Goodbye!');
                $client->disconnect();
            }
        });

        $client->connect()->then(function () use ($client, $output) {
            $output->writeln('Connected!');
        });

        $loop->run();
    }
}
