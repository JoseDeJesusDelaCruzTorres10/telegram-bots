<?php

declare(strict_types = 1);

namespace unreal4u\TelegramBots;

use GuzzleHttp\Client;
use Monolog\Logger;
use unreal4u\TelegramAPI\Telegram\Types\Message;
use unreal4u\TelegramBots\Bots\UptimeMonitor\EventManager;
use unreal4u\TelegramBots\Bots\UptimeMonitorBot;
use unreal4u\TelegramBots\Exceptions\ChatIsBlacklisted;

class RequestHandler {
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var Logger
     */
    private $botLogger;

    /**
     * @var Client
     */
    protected $httpClient;

    public function __construct(Logger $logger, string $requestUri, Client $httpClient = null)
    {
        $beginTime = microtime(true);
        $this->logger = $logger;
        if ($httpClient === null) {
            $httpClient = new Client();
        }

        $this->httpClient = $httpClient;

        if (!array_key_exists($requestUri, BOT_TOKENS)) {
            // Can be a request from UptimeMonitor or somebody scanning us
            $this->logger->info('Received inbound url not coming from Telegram servers');
            $this->uptimeMonitorNotification($requestUri);
        } else {
            // Can only be a request directly to out bot from Telegram servers
            $this->newBotRequest(BOT_TOKENS[$requestUri], $requestUri);
        }

        $this->logger->debug(sprintf('Ending request. Total time: %f seconds', microtime(true) - $beginTime));
    }

    private function setupBotLogger(string $currentBot): RequestHandler
    {
        $this->botLogger = $this->logger->withName($currentBot);
        $this->botLogger->debug(str_repeat('-', 20).' New request '.str_repeat('-', 20));

        return $this;
    }

    private function newBotRequest(string $currentBot, string $botToken): bool
    {
        $this->logger->info(sprintf(
            'New request on bot %s, relaying log to telegramApiLogs/%s.log',
            $currentBot,
            $currentBot
        ));

        $this->setupBotLogger($currentBot);

        $rest_json = file_get_contents('php://input');
        $_POST = json_decode($rest_json, true);

        try {
            $completeName = 'unreal4u\\TelegramBots\\Bots\\' . $currentBot;
            /** @var $bot \unreal4u\TelegramBots\Bots\Base */
            $bot = new $completeName($this->botLogger, $botToken, $this->httpClient);
            $this->botLogger->debug('Incoming data', [$_POST]);
            $bot->createAnswer($_POST);
            $this->botLogger->debug('Went through the createAnswer method, sending possible response (If there is any)');
            // Assume this went well
            $bot->sendResponse();
            $this->botLogger->debug(str_repeat('-', 20).' Finishing request '.str_repeat('-', 20));
        } catch (ChatIsBlacklisted $e) {
            $this->logger->notice('Blacklisted chatId found', ['blacklistedChatId' => $e->getBlacklistedChatId()]);
            http_response_code(200);
        } catch (\Exception $e) {
            // Log in the specific bot logger instead of general log
            $this->botLogger->error(sprintf('Captured exception: "%s" for bot %s', $e->getMessage(), $currentBot));
        }

        return true;
    }

    /**
     * @param string $requestUri
     * @return bool
     */
    private function uptimeMonitorNotification(string $requestUri): bool
    {
        $redirect = true;
        $requestUriParts = explode('/', $requestUri);
        $this->logger->info('Received a probable notification from monitor API', $requestUriParts);

        if (!empty($requestUriParts[0])) {
            $this->logger->info('Incoming request for bot', [
                'bot' => $requestUriParts[0],
                'uuid' => $requestUriParts[1],
            ]);

            if (strtolower($requestUriParts[0]) === 'uptimemonitorbot') {
                $this->setupBotLogger('UptimeMonitorBot');
                $flippedKeys = array_flip(BOT_TOKENS);

                try {
                    $bot = new UptimeMonitorBot($this->botLogger, $flippedKeys['UptimeMonitorBot'], $this->httpClient);
                    $eventManager = $bot->handleUptimeMonitorNotification($_GET, $requestUriParts[1]);
                    /** @var Message $message */
                    $message = $bot->sendResponse();
                    if ($message instanceof Message && $eventManager instanceof EventManager) {
                        $redirect = false;
                        if ($message->message_id !== false) {
                            // To be able to respond with a reply, quoting the original text
                            $eventManager->setEventNotified($message->message_id);
                        }
                    }
                } catch (ChatIsBlacklisted $e) {
                    $this->logger->info($e->getMessage());
                } catch (\Exception $e) {
                    $this->logger->error($e->getMessage().' (File: '.$e->getFile().':'.$e->getLine().')');
                    // Do nothing here and let the requestHandler redirect the user to my github page
                }
            }
        }

        if ($redirect === true) {
            $this->logger->info('Request not coming from monitor API or Telegram servers, please check logs');
            header('Location: https://github.com/unreal4u?tab=repositories', true, 302);
        }

        return true;
    }
}
