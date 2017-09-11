<?php
/*
 * Copyright (c) Nate Brunette.
 * Distributed under the MIT License (http://opensource.org/licenses/MIT)
 */

declare(strict_types=1);

namespace Tebru\RetrofitHttp\Guzzle6;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use LogicException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tebru\Retrofit\HttpClient;

/**
 * Class Guzzle6HttpClient
 *
 * @author Nate Brunette <n@tebru.net>
 */
class Guzzle6HttpClient implements HttpClient
{
    /**
     * @var Client
     */
    private $client;

    /**
     * An array of pending requests
     *
     * @var array
     */
    private $requests = [];

    /**
     * Constructor
     *
     * @param ClientInterface $client
     */
    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
    }

    /**
     * Send a request synchronously and return a PSR-7 [@see ResponseInterface]
     *
     * @param RequestInterface $request
     * @return ResponseInterface
     * @throws \GuzzleHttp\Exception\RequestException
     */
    public function send(RequestInterface $request): ResponseInterface
    {
        try {
            $response = $this->client->send($request);
        } catch (RequestException $exception) {
            $response = $exception->getResponse();
            if ($response === null) {
                throw $exception;
            }
        }

        return $response;
    }

    /**
     * Send a request asynchronously
     *
     * The response callback must be called if any response is returned from the request, and the failure
     * callback should only be executed if a request was not completed.
     *
     * The response callback should pass a PSR-7 [@see ResponseInterface] as the one and only argument. The
     * failure callback should pass a [@see Throwable] as the one and only argument.
     *
     * @param RequestInterface $request
     * @param callable $onResponse
     * @param callable $onFailure
     * @return void
     */
    public function sendAsync(RequestInterface $request, callable $onResponse, callable $onFailure): void
    {
        $this->requests[] = [
            'promise' => $this->client->sendAsync($request),
            'onResponse' => $onResponse,
            'onFailure' => $onFailure,
        ];
    }

    /**
     * Calling this method should execute any enqueued requests asynchronously
     *
     * @return void
     * @throws \LogicException
     */
    public function wait(): void
    {
        if ($this->requests === []) {
            return;
        }

        $requests = function () {
            foreach ($this->requests as $request) {
                yield function () use ($request) { return $request['promise']; };
            }
        };
        $this->requests = [];

        $pool = new Pool( $this->client, $requests(), [
            'concurrency' => 5,
            'fulfilled' => function (ResponseInterface $response, $index) {
                return $this->requests[$index]['onResponse']($response);
            },
            'rejected' => function ($reason, $index) {
                if ($reason instanceof RequestException && $reason->getResponse() !== null) {
                    return $this->requests[$index]['onResponse']($reason->getResponse());
                }
                return $this->requests[$index]['onFailure']($reason);
            },
        ]);

        $pool->promise()->wait();
    }
}
