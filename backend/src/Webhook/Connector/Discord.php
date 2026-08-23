<?php

declare(strict_types=1);

namespace App\Webhook\Connector;

use App\Entity\Api\NowPlaying\NowPlaying;
use App\Entity\Station;
use App\Entity\StationWebhook;
use App\Utilities\Time;
use App\Utilities\Types;
use App\Utilities\Urls;
use App\Utilities\UserUrlFilter;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

/*
 * https://discordapp.com/developers/docs/resources/webhook#execute-webhook
 */
final class Discord extends AbstractConnector
{
    public function __construct(
        Client $httpClient,
        private readonly UserUrlFilter $userUrlFilter
    ) {
        parent::__construct($httpClient);
    }

    public function dispatch(
        Station $station,
        StationWebhook $webhook,
        NowPlaying $np,
        array $triggers
    ): void {
        $config = $webhook->config ?? [];

        $webhookUrl = $this->userUrlFilter->filterSensitiveUserUrl(
            $config['webhook_url'],
            'Discord Webhook'
        );

        if (empty($webhookUrl)) {
            throw $this->incompleteConfigException($webhook);
        }

        $rawVars = [
            'content' => $config['content'] ?? '',
            'title' => $config['title'] ?? '',
            'description' => $config['description'] ?? '',
            'url' => $config['url'] ?? '',
            'author' => $config['author'] ?? '',
            'thumbnail' => $config['thumbnail'] ?? '',
            'footer' => $config['footer'] ?? '',
            'color' => $config['color'] ?? '',
        ];

        $vars = $this->replaceVariables($rawVars, $np);

        $colorInput = ltrim(trim($vars['color'] ?? ''), '#');
        if (!empty($colorInput) && preg_match('/^[0-9A-F]{6}$/i', $colorInput)) {
            $colorDecimal = hexdec(ltrim($colorInput, '#'));
        } else {
            $colorDecimal = 2201331;
        }

        $includeTimestamp = Types::bool($config['include_timestamp'] ?? false, false, true);

        $embed = array_filter(
            [
                'title' => $vars['title'] ?? '',
                'description' => $vars['description'] ?? '',
                'url' => Urls::tryParseUserUrl(
                    $vars['url'],
                    'Discord Webhook'
                ) ?? '',
                'color' => $colorDecimal,
            ]
        );

        if ($includeTimestamp) {
            $embed['timestamp'] = Time::nowUtc()->toAtomString();
        }

        if (!empty($vars['author'])) {
            $embed['author'] = ['name' => $vars['author']];
        }
        if (!empty($vars['thumbnail']) && $this->getImageUrl($vars['thumbnail'])) {
            $embed['thumbnail'] = ['url' => $this->getImageUrl($vars['thumbnail'])];
        }
        if (!empty($vars['footer'])) {
            $embed['footer'] = ['text' => $vars['footer']];
        }

        $webhookBody = ['content' => $vars['content'] ?? ''];
        if (count($embed) > 1) {
            $webhookBody['embeds'] = [$embed];
        }

        $this->logger->debug('Dispatching Discord webhook...');

        $response = $this->httpClient->request(
            'POST',
            $webhookUrl,
            [
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::HEADERS => ['Content-Type' => 'application/json'],
                RequestOptions::JSON => $webhookBody,
            ]
        );

        $this->logHttpResponse($webhook, $response, $webhookBody);
    }

    private function getImageUrl(?string $url = null): ?string
    {
        $url = Urls::tryParseUserUrl($url, 'Discord Webhook Image URL');

        if (null !== $url) {
            return str_replace('http://', 'https://', (string)$url);
        }

        return null;
    }
}
